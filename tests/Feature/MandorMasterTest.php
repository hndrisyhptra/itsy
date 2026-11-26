<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\FinanceDocuments;
use App\Services\MandorMaster;
use App\Services\RelationalWorkflowStore;
use App\Services\UploadQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class MandorMasterTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->asRole('Admin Konstruksi');
        $this->getJson('/api/prototype/state')->assertOk();
    }

    private function input(string $unit = 'Konstruksi', ?int $branch = null): array
    {
        return ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'unit' => $unit, 'branch_id' => $branch ?? (int) DB::table('branches')->where('is_active', true)->value('id_branch'), 'version' => 0,
            'data' => ['name' => 'Mandor Uji Saja', 'address' => 'Alamat Uji Bukan Data Pribadi', 'nik' => '0000000000000001', 'accountNumber' => '0000012345', 'accountName' => 'Penerima Uji', 'bank' => 'Bank Uji']];
    }

    private function create(?array $input = null): array
    {
        return $this->postJson('/api/mandors', $input ?? $this->input())->assertOk()->json();
    }

    private function upload(string $id, string $kind, ?string $uuid = null): string
    {
        return $this->post('/api/mandors/'.$id.'/uploads/'.$kind, ['request_id' => $uuid ?? (string) Str::uuid(), 'file' => UploadedFile::fake()->image('synthetic.png', 3, 3)], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
    }

    private function complete(array $m): array
    {
        foreach (MandorMaster::KINDS as $kind) {
            $id = $this->upload($m['id'], $kind);
            app(UploadQueue::class)->process($id);
            $this->getJson('/api/uploads/'.$id)->assertOk()->assertJsonPath('task.status', 'completed');
        }

        return $this->getJson('/api/mandors/'.$m['id'])->assertOk()->assertJsonPath('ready', true)->json();
    }

    private function draftSpk(bool $operation = false, ?string $branchName = null): array
    {
        $this->seedWorkflow('D');
        $w = PrototypeWorkspace::first();
        $store = app(RelationalWorkflowStore::class);
        $before = $store->read($w);
        $next = $before;
        foreach ($next['projects'] as &$p) {
            $p['status'] = 'Draft';
            if ($branchName) {
                $p['branch'] = $branchName;
            }
            $p['unit'] = $operation ? 'Operation' : 'Konstruksi';
            $p['approvals'] = $p['approvalHistory'] = [];
            $p['contractBundle'] = ['version' => 1, 'submissions' => []];
        }
        unset($p);
        foreach ($next['deals'] as &$d) {
            $d['status'] = 'Draft';
        }
        unset($d);
        foreach ($next['spks'] as &$v) {
            $v['status'] = 'Draft';
        }
        unset($v);
        $next['terms'] = $next['bapps'] = [];
        DB::transaction(fn () => $store->write($w, $next, $before));
        $this->asRole($operation ? 'Admin Operation' : 'Admin Konstruksi');
        $v = $next['spks'][0];
        $d = collect($next['deals'])->firstWhere('id', $v['dealId']);
        $p = collect($next['projects'])->firstWhere('id', $d['projectId']);
        $branch = (int) DB::table('branches')->where('name', $p['branch'])->value('id_branch');

        return [$v, $d, $p, $branch];
    }

    private function apply(array $m, string $spk, ?array $override = null)
    {
        return $this->postJson('/api/finance/spk/'.$spk.'/mandor', $override ?? ['request_id' => (string) Str::uuid(), 'revision' => PrototypeWorkspace::first()->revision, 'data' => ['id' => $m['id'], 'version' => $m['version']]]);
    }

    public function test_five_roles_can_save_shared_encrypted_master_with_actor_payload_replay_and_no_workflow_mutation(): void
    {
        $state = DB::table('project_plans')->get()->toJson();
        $revision = PrototypeWorkspace::first()->revision;
        foreach (MandorMaster::ROLES as $i => $role) {
            $actor = $this->asRole($role);
            $in = $this->input(in_array($role, ['Admin Operation', 'Officer 1 Operation'], true) ? 'Operation' : 'Konstruksi');
            $in['data']['nik'] = str_pad((string) ($i + 1), 16, '0', STR_PAD_LEFT);
            $this->create($in);
            $raw = DB::table('mandors')->where('id_mandor', $in['id'])->first();
            $this->assertStringNotContainsString($in['data']['nik'], $raw->private_data);
            $this->assertSame($in['data']['nik'], json_decode(Crypt::decryptString($raw->private_data), true)['nik']);
            $this->postJson('/api/mandors', $in)->assertOk()->assertJsonPath('replayed', true);
            $in['data']['name'] = 'Isi lain';
            $this->postJson('/api/mandors', $in)->assertConflict();
            $this->assertEquals($actor->id_user, $raw->created_by);
        }
        $this->assertDatabaseCount('mandors', 5);
        $this->assertDatabaseCount('mandor_changes', 5);
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertSame($state, DB::table('project_plans')->get()->toJson());
    }

    public function test_shared_master_roles_nik_duplicate_and_stale_versions_never_leak_or_overwrite(): void
    {
        $in = $this->input();
        $m = $this->create($in);
        $duplicate = $in;
        $duplicate['id'] = (string) Str::uuid();
        $duplicate['request_id'] = (string) Str::uuid();
        $this->postJson('/api/mandors', $duplicate)->assertUnprocessable();
        $in['request_id'] = (string) Str::uuid();
        $this->postJson('/api/mandors', $in)->assertConflict();
        foreach (['Finance', 'Mgr Finance & Settlement', 'PM Konstruksi', 'Manager SS'] as $role) {
            $this->asRole($role);
            $this->getJson('/api/mandors')->assertForbidden();
            $this->getJson('/api/mandors/'.$m['id'])->assertForbidden();
            $this->postJson('/api/mandors', $in)->assertForbidden();
        }
        $this->asRole('Admin Operation');
        $this->getJson('/api/mandors')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/mandors/'.$m['id'])->assertOk();
        $actor = $this->asRole('Admin Konstruksi');
        $other = (int) DB::table('branches')->where('id_branch', '<>', $in['branch_id'])->value('id_branch');
        app(AccountAccess::class)->assign($actor, 'ADMIN_KONSTRUKSI', [$other]);
        $this->getJson('/api/mandors')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/mandors/'.$m['id'])->assertOk();
        $this->postJson('/api/mandors', $in)->assertConflict();
        $this->assertDatabaseCount('mandors', 1);
    }

    public function test_queue_photos_private_replay_and_revoked_actor_cleanup(): void
    {
        $m = $this->create();
        $m = $this->complete($m);
        foreach (['ktpFileId', 'bankFileId'] as $key) {
            $this->get('/api/mandors/files/'.$m[$key])->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            $this->assertFalse(str_starts_with(Storage::disk('local')->path(DB::table('mandor_files')->where('id_mandor_file', $m[$key])->value('storage_key')), public_path()));
        }
        $task = $this->upload($m['id'], 'master-ktp');
        $actor = auth()->user();
        $this->asRole('Superadmin');
        $this->getJson('/api/uploads/'.$task)->assertNotFound();
        DB::table('users')->where('id_user', $actor->id_user)->update(['active' => false]);
        app(UploadQueue::class)->process($task);
        $this->assertSame('failed', DB::table('upload_tasks')->where('id_upload_task', $task)->value('status'));
        $this->assertDatabaseCount('mandor_files', 2);
        $this->assertCount(2, Storage::disk('local')->allFiles('mandor-private'));
        $this->asRole('Admin Operation');
        $this->get('/api/mandors/files/'.$m['ktpFileId'])->assertOk();
    }

    public function test_explicit_selection_copies_exact_files_and_names_into_draft_not_future_master_changes(): void
    {
        foreach ([false, true] as $operation) {
            [$spk, $deal, $p, $branch] = $this->draftSpk($operation);
            $in = $this->input($operation ? 'Operation' : 'Konstruksi', $branch);
            $in['data']['nik'] = $operation ? '0000000000000002' : '0000000000000001';
            $m = $this->complete($this->create($in));
            $request = ['request_id' => (string) Str::uuid(), 'revision' => PrototypeWorkspace::first()->revision, 'data' => ['id' => $m['id'], 'version' => $m['version']]];
            DB::enableQueryLog();
            DB::flushQueryLog();
            $result = $this->apply($m, $spk['id'], $request)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            fwrite(STDERR, "\nMaster Mandor selection ".($operation ? 'Operation' : 'Construction').": {$queries} HTTP queries.\n");
            $this->assertLessThanOrEqual(65, $queries);
            $this->assertSame($m['name'], collect($result->json('state.deals'))->firstWhere('id', $deal['id'])['mandor']);
            $saved = collect($result->json('state.spks'))->firstWhere('id', $spk['id']);
            $this->assertSame($in['data']['address'], $saved['address']);
            $payee = app(FinanceDocuments::class)->requirePayee(PrototypeWorkspace::first(), $spk['id']);
            $this->assertSame($in['data']['nik'], $payee['nik']);
            $this->assertNotSame($m['ktpFileId'], $payee['ktpFileId']);
            $this->assertSame($m['id'], $payee['masterMandorId']);
            $fileBytes = DB::table('finance_private_files')->where('id_finance_file', $payee['ktpFileId'])->first();
            $this->assertSame(hash_file('sha256', Storage::disk('local')->path($fileBytes->storage_key)), $fileBytes->sha256);
            $this->apply($m, $spk['id'], $request)->assertOk()->assertJsonPath('replayed', true);
            $this->assertEquals(1, $saved['payeeVersion']);
            $in['version'] = $m['version'];
            $in['request_id'] = (string) Str::uuid();
            $in['data']['accountNumber'] = '000009999';
            $this->create($in);
            $this->assertSame($payee, app(FinanceDocuments::class)->requirePayee(PrototypeWorkspace::first(), $spk['id']));
            $this->assertSame($saved, collect($this->getJson('/api/prototype/state')->assertOk()->json('state.spks'))->firstWhere('id', $spk['id']));
            $request['data']['version']++;
            $this->apply($m, $spk['id'], $request)->assertConflict();
        }
    }

    public function test_wrong_document_access_stale_revision_locked_document_reject_without_private_side_effects(): void
    {
        [$spk, , , $branch] = $this->draftSpk();
        $m = $this->create($this->input('Konstruksi', $branch));
        $m = $this->complete($m);
        $bad = ['request_id' => (string) Str::uuid(), 'revision' => 99999, 'data' => ['id' => $m['id'], 'version' => $m['version']]];
        $this->apply($m, $spk['id'], $bad)->assertConflict();
        $other = (int) DB::table('branches')->where('id_branch', '<>', $branch)->value('id_branch');
        $actor = auth()->user();
        app(AccountAccess::class)->assign($actor, 'ADMIN_KONSTRUKSI', [$other]);
        $this->getJson('/api/mandors/'.$m['id'])->assertOk(); // Master is shared, document is still restricted.
        $this->apply($m, $spk['id'])->assertNotFound();
        app(AccountAccess::class)->assign($actor, 'ADMIN_KONSTRUKSI', DB::table('branches')->pluck('id_branch')->all());
        $sqlSpk = app(RelationalWorkflowStore::class)->id(PrototypeWorkspace::first()->id, 'spks', $spk['id']);
        $doc = json_decode(DB::table('work_orders')->where('id_spk', $sqlSpk)->value('document_data'), true);
        $doc['status'] = 'Sah';
        DB::table('work_orders')->where('id_spk', $sqlSpk)->update(['document_data' => json_encode($doc)]);
        $this->apply($m, $spk['id'])->assertUnprocessable();
        $this->assertDatabaseCount('mandor_payee_profiles', 0);
        $this->assertDatabaseCount('finance_private_files', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('finance-private'));
        $this->postJson('/api/prototype/commands', ['command' => '_selectMasterMandor', 'args' => [$spk['id'], []], 'revision' => 0, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_minimal_master_can_be_selected_in_draft_but_package_requires_bank_and_both_photos(): void
    {
        foreach ([false, true] as $operation) {
            [$spk, , $p] = $this->draftSpk($operation);
            $in = $this->input();
            $in['data'] = ['name' => 'Mandor Minimal', 'address' => 'Alamat Uji', 'nik' => $operation ? '0000000000000002' : '0000000000000001'];
            $m = $this->create($in);
            $this->assertSame('', $m['profile']['accountNumber']);
            $this->apply($m, $spk['id'])->assertOk();
            $this->assertDatabaseCount('finance_private_files', 0);
            $request = ['command' => 'submitProjectBundle', 'args' => [$p['id']], 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()];
            $this->postJson('/api/prototype/commands', $request)->assertUnprocessable()->assertJsonValidationErrors(['accountNumber', 'accountName', 'bank', 'ktpFileId', 'bankFileId']);
            $this->postJson('/api/finance/spk/'.$spk['id'].'/payee', ['request_id' => (string) Str::uuid(), 'revision' => PrototypeWorkspace::first()->revision, 'data' => ['nik' => $in['data']['nik']]])->assertUnprocessable()->assertJsonPath('message', 'Mandor terhubung ke master. Lengkapi pada Master Mandor lalu gunakan ulang data master pada draf SPK.');
            $this->post('/api/finance/uploads/mandor-ktp/'.$spk['id'], ['request_id' => (string) Str::uuid(), 'revision' => PrototypeWorkspace::first()->revision, 'file' => UploadedFile::fake()->image('synthetic.png', 3, 3)], ['Accept' => 'application/json'])->assertUnprocessable();
            $in['version'] = $m['version'];
            $in['request_id'] = (string) Str::uuid();
            $in['data'] += ['accountNumber' => '00000123', 'bank' => 'Bank Uji', 'accountName' => 'Penerima Uji'];
            $m = $this->complete($this->create($in));
            $this->apply($m, $spk['id'])->assertOk();
            $payee = app(FinanceDocuments::class)->requirePayee(PrototypeWorkspace::first(), $spk['id']);
            $this->assertSame('00000123', $payee['accountNumber']);
            $this->assertEquals($m['version'], $payee['masterMandorVersion']);
        }
    }

    public function test_pagination_search_has_bounded_queries_and_does_not_return_private_columns(): void
    {
        $m = $this->create();
        $row = (array) DB::table('mandors')->where('id_mandor', $m['id'])->first();
        $rows = [];
        for ($i = 0; $i < 104; $i++) {
            $rows[] = array_replace($row, ['id_mandor' => (string) Str::uuid(), 'name' => sprintf('Worker %03d', $i), 'nik_hash' => hash('sha256', (string) $i)]);
        }
        DB::table('mandors')->insert($rows);
        $counts = [];
        foreach ([10, 20, 50, 100] as $size) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $r = $this->getJson('/api/mandors?per_page='.$size)->assertOk()->assertJsonPath('total', 105)->assertJsonCount($size, 'rows');
            $q = DB::getQueryLog();
            DB::disableQueryLog();
            $counts[] = count($q);
            foreach ($q as $query) {
                if (str_contains($query['query'], 'from "mandors"')) {
                    $this->assertStringNotContainsString('private_data', $query['query']);
                }
            }
            $this->assertStringNotContainsString($row['private_data'], $r->getContent());
        }
        $this->assertCount(1, array_unique($counts));
        fwrite(STDERR, "\nMaster Mandor paged list: {$counts[0]} HTTP queries for 10/20/50/100 rows.\n");
        $this->getJson('/api/mandors?search=Worker%2010')->assertOk()->assertJsonPath('total', 4);
        $this->getJson('/api/mandors?per_page=100&page=2')->assertOk()->assertJsonCount(5, 'rows');
        $this->getJson('/api/mandors?per_page=1000')->assertUnprocessable();
        $migration = require database_path('migrations/2026_11_26_000001_create_mandor_master.php');
        try {
            $migration->down();
            $this->fail('Populated rollback allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rollback ditolak', $e->getMessage());
        }
        $this->assertDatabaseCount('mandors', 105);
    }

    public function test_invalid_identity_and_photo_are_rejected_and_late_selection_audit_failure_rolls_back_everything(): void
    {
        [$spk, , , $branch] = $this->draftSpk();
        $in = $this->input('Konstruksi', $branch);
        foreach (['nik' => '123', 'accountNumber' => '1 23', 'name' => '<script>', 'address' => "Alamat\nbaris"] as $field => $value) {
            $bad = $in;
            $bad['data'][$field] = $value;
            $this->postJson('/api/mandors', $bad)->assertUnprocessable();
        }
        $m = $this->create($in);
        // Laravel's fake file advertises PNG; the worker must still reject the real non-image bytes.
        $invalid = $this->post('/api/mandors/'.$m['id'].'/uploads/master-ktp', ['request_id' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('fake.png', 'not an image')], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
        app(UploadQueue::class)->process($invalid);
        $this->assertSame('failed', DB::table('upload_tasks')->where('id_upload_task', $invalid)->value('status'));
        $this->assertDatabaseCount('mandor_files', 0);
        $m = $this->complete($m);
        $before = DB::table('work_orders')->get()->toJson();
        $revision = PrototypeWorkspace::first()->revision;
        DB::unprepared("CREATE TRIGGER reject_mandor_selection BEFORE INSERT ON mandor_changes WHEN NEW.action = 'select' BEGIN SELECT RAISE(ABORT, 'qa late audit failure'); END");
        $this->apply($m, $spk['id'])->assertServerError();
        $this->assertSame($before, DB::table('work_orders')->get()->toJson());
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertDatabaseCount('mandor_payee_profiles', 0);
        $this->assertDatabaseCount('finance_private_files', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('finance-private'));
    }

    public function test_existing_paid_workflow_and_private_snapshots_are_unchanged_by_master_save_reads_and_upload(): void
    {
        $this->seedWorkflow('E');
        $this->asRole('Admin Konstruksi');
        $tables = ['project_plans', 'contracts', 'work_orders', 'payment_terms', 'ba_completions', 'approval_decisions', 'apm_settlements', 'mandor_payee_profiles', 'finance_kasbons'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toJson(), $tables);
        $before = $snapshot();
        $m = $this->complete($this->create());
        $this->getJson('/api/mandors')->assertOk();
        $in = $this->input();
        $in['id'] = $m['id'];
        $in['version'] = $m['version'];
        $in['data']['name'] = 'Nama Master Diperbarui';
        $this->postJson('/api/mandors', $in)->assertOk();
        $this->assertSame($before, $snapshot());
    }

    public function test_same_master_is_used_across_branches_and_units_but_not_outside_document_access(): void
    {
        $in = $this->input();
        unset($in['unit'], $in['branch_id']);
        $m = $this->complete($this->create($in));
        $this->assertNull(DB::table('mandors')->where('id_mandor', $m['id'])->value('branch_id'));
        $this->assertNull(DB::table('mandors')->where('id_mandor', $m['id'])->value('unit'));
        $origins = DB::table('branches')->where('is_active', true)->limit(2)->get();
        $ids = [];
        foreach ([false, true] as $i => $operation) {
            [$v, , , $branch] = $this->draftSpk($operation, $origins[$i]->name);
            app(AccountAccess::class)->assign(auth()->user(), $operation ? 'ADMIN_OPS' : 'ADMIN_KONSTRUKSI', [$branch]);
            $this->getJson('/api/mandors?unit='.($operation ? 'Konstruksi' : 'Operation').'&branch_id='.$origins[1 - $i]->id_branch)->assertOk()->assertJsonPath('total', 1)->assertJsonMissingPath('rows.0.unit')->assertJsonMissingPath('rows.0.branchId');
            $this->getJson('/api/mandors/'.$m['id'])->assertOk();
            $response = $this->apply($m, $v['id'])->assertOk();
            $saved = collect($response->json('state.spks'))->firstWhere('id', $v['id']);
            $this->assertSame($m['id'], $saved['masterMandor']['id']);
            if ($operation) {
                $this->asRole('Admin Konstruksi');
                $this->getJson('/api/mandors/'.$m['id'])->assertOk();
                $this->apply($m, $v['id'])->assertNotFound();
                $this->asRole('Admin Operation');
            }
            $ids[] = $v['id'];
        }
        $other = $this->input('Operation', (int) $origins[1]->id_branch);
        $this->postJson('/api/mandors', $other)->assertUnprocessable();
        $this->assertDatabaseCount('mandors', 1);
        $global = require database_path('migrations/2026_11_26_000002_share_mandor_master_across_units.php');
        try {
            $global->down();
            $this->fail('Shared record rollback allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rollback', $e->getMessage());
        }
    }

    public function test_global_migration_preserves_legacy_rows_files_audit_and_receipt_and_refuses_duplicate_nik_without_deleting(): void
    {
        $in = $this->input();
        $m = $this->complete($this->create($in));
        $w = PrototypeWorkspace::first();
        // Explicit pre-update metadata fixture only in :memory:, with valid unchanged file/profile bytes.
        DB::table('mandors')->where('id_mandor', $m['id'])->update(['branch_id' => $in['branch_id'], 'unit' => $in['unit']]);
        $legacyHash = hash('sha256', json_encode([$in['id'], $in['unit'], (int) $in['branch_id'], 0, $in['data']], JSON_THROW_ON_ERROR));
        DB::table('mandor_changes')->where('id_mandor_change', $in['request_id'])->update(['payload_hash' => $legacyHash]);
        $migration = require database_path('migrations/2026_11_26_000002_share_mandor_master_across_units.php');
        $migration->down();
        $snapshot = fn () => array_map(fn ($t) => DB::table($t)->get()->toJson(), ['mandors', 'mandor_files', 'mandor_changes']);
        $before = $snapshot();
        $migration->up();
        $migration->up();
        $this->assertSame($before, $snapshot());
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        $this->assertSame(0, (int) DB::selectOne('PRAGMA defer_foreign_keys')->defer_foreign_keys);
        $this->assertEmpty(DB::select('PRAGMA foreign_key_check'));
        $this->postJson('/api/mandors', $in)->assertOk()->assertJsonPath('replayed', true);
        $this->asRole('Admin Operation');
        $this->get('/api/mandors/files/'.$m['ktpFileId'])->assertOk();
        $migration->down();
        $duplicate = (array) DB::table('mandors')->first();
        $duplicate['id_mandor'] = (string) Str::uuid();
        $duplicate['unit'] = 'Operation';
        DB::table('mandors')->insert($duplicate);
        $before = $snapshot();
        try {
            $migration->up();
            $this->fail('Duplicate legacy NIK silently merged');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('NIK ganda', $e->getMessage());
        }
        $this->assertSame($before, $snapshot());
        $this->assertFalse(Schema::hasIndex('mandors', 'mandor_workspace_nik'));
    }
}
