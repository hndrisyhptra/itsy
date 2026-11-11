<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\FinanceDocuments;
use App\Services\PrototypeDomain;
use App\Services\RelationalWorkflowStore;
use App\Services\UploadQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class FinanceKasbonTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function api(string $command, array $args)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()]);
    }

    private function write(string $path, array $data, ?string $request = null, ?int $revision = null)
    {
        return $this->postJson($path, ['data' => $data, 'request_id' => $request ?? (string) Str::uuid(), 'revision' => $revision ?? PrototypeWorkspace::first()->revision]);
    }

    private function upload(string $kind, string $id): string
    {
        $file = $kind === 'transfer-proof' ? UploadedFile::fake()->createWithContent('transfer.pdf', "%PDF-1.4\ntransfer QA\n%%EOF") : UploadedFile::fake()->image('private-identity.png');
        $task = $this->post('/api/finance/uploads/'.$kind.'/'.$id, ['file' => $file, 'revision' => (string) PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
        app(UploadQueue::class)->process($task);
        $this->assertDatabaseHas('upload_tasks', ['id_upload_task' => $task, 'status' => 'completed']);

        return $task;
    }

    private function setupTerm(bool $final = false, int $approvals = 5, bool $compatibilitySubmit = false): array
    {
        $this->withoutVite();
        Storage::fake('local');
        $this->seedWorkflow('D');
        $this->asRole('Admin Konstruksi');
        $s = $this->getJson('/api/prototype/state')->assertOk()->json('state');
        $spk = $s['spks'][0];
        $this->api('createTerm', [$spk['id']])->assertOk();
        $t = $this->getJson('/api/prototype/state')->json('state.terms.0');
        $t['type'] = $final ? 'Final' : 'Parsial';
        $t['actuals'] = [$s['deals'][0]['rows'][0]['rowId'] => ['m' => 1, 's' => 1]];
        $t['barp']['place'] = 'Tempat uji';
        $t['barp']['date'] = $spk['start'];
        $t['kasbon'] = ['id' => (string) Str::uuid(), 'amount' => 1];
        $t['transferProof'] = ['id' => (string) Str::uuid()];
        $this->api($compatibilitySubmit ? 'saveTerm' : 'submitFinanceTerm', $compatibilitySubmit ? [$t, true] : [$t])->assertOk()->assertJsonPath('state.terms.0.paymentFlow', 'kasbon-v1');
        foreach (array_slice(PrototypeDomain::APPROVERS_V3, 0, $approvals) as $role) {
            $this->asRole($role);
            $this->api('decide', [$t['id'], 'Setujui', ''])->assertOk();
        }
        $this->asRole('Admin Konstruksi');

        return $this->term($t['id']);
    }

    private function term(string $id): array
    {
        return collect($this->getJson('/api/prototype/state')->assertOk()->json('state.terms'))->firstWhere('id', $id);
    }

    private function payee(string $spk): array
    {
        $this->asRole('Admin Konstruksi');
        $data = ['nik' => '0011223344556677', 'accountNumber' => '000123456789', 'accountName' => 'Mandor QA', 'bank' => 'Bank QA', 'ktpFileId' => $this->upload('mandor-ktp', $spk), 'bankFileId' => $this->upload('mandor-bank', $spk)];
        $this->write('/api/finance/spk/'.$spk.'/payee', $data)->assertOk();

        return $data;
    }

    private function form(array $t): array
    {
        $s = $this->getJson('/api/prototype/state')->json('state');
        $amount = app(PrototypeDomain::class)->load($s)->termTotal($t);

        return ['costCenter' => 'QA-COST', 'wbs' => 'QA-WBS', 'activity' => 'Kegiatan uji', 'place' => 'Tempat QA', 'date' => '2026-11-11', 'evp' => 'Pejabat Fisik QA', 'businessSupport' => 'Pejabat Support QA', 'rows' => [['accountCode' => 'QA-001', 'accountName' => 'Akun QA', 'text' => 'Termin '.$t['number'], 'amount' => (string) $amount]]];
    }

    private function issue(array $t): array
    {
        $this->asRole('Finance');
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $this->form($t))->assertOk();

        return $this->term($t['id']);
    }

    public function test_partial_and_final_can_pay_without_scan_after_kasbon_finance_and_transfer_then_verify_late_scan(): void
    {
        foreach ([false, true] as $final) {
            $t = $this->setupTerm($final);
            $archive = PrototypeWorkspace::first()->getRawOriginal('state');
            $this->api('requestPartialPayment', [$t['id']])->assertUnprocessable();
            $payee = $this->payee($t['spkId']);
            $t = $this->issue($t);
            $this->api('recordApm', [$t['id'], 'APM/QA/001', true])->assertUnprocessable();
            $this->api('decideFinancePayment', [$t['id'], 'Setujui', 'Berkas menyusul'])->assertOk();
            $this->api('recordApm', [$t['id'], 'APM/QA/001', true])->assertUnprocessable();
            $file = $this->upload('transfer-proof', $t['id']);
            $this->api('recordApm', [$t['id'], 'APM/QA/001', true])->assertOk();
            $paid = $this->term($t['id']);
            $this->assertSame('Dibayar', $paid['financePayment']['status']);
            $this->assertSame('NOK', $paid['financePayment']['documentsAtPayment']);
            $this->assertEquals($paid['kasbon']['amount'], $paid['paid']);
            $settlement = (array) DB::table('apm_settlements')->first();
            $this->assertSame($file, $settlement['transfer_file_id']);
            $this->assertSame($paid['kasbon']['id'], $settlement['kasbon_id']);
            $this->assertNull($settlement['verified_scan_id']);
            $this->assertNull($settlement['payment_request_id']);
            $private = $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon')->assertOk()->json('documents.0.data');
            $this->assertSame($payee['accountNumber'], $private['payee']['accountNumber']);
            $this->assertSame($payee['nik'], $private['payee']['nik']);
            $this->assertStringNotContainsString($payee['nik'], DB::table('mandor_payee_profiles')->value('private_data'));
            $this->assertStringNotContainsString($payee['accountNumber'], $this->getJson('/api/prototype/state')->getContent());
            $this->api('recordApm', [$t['id'], 'APM/QA/002', true])->assertUnprocessable();
            $this->asRole('Admin Konstruksi');
            $this->api('createTerm', [$t['spkId']])->{$final ? 'assertUnprocessable' : 'assertOk'}();
            $this->post('/api/prototype/payments/'.$t['id'].'/scan', ['revision' => (string) PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('late.pdf', "%PDF-1.4\nwet scan QA\n%%EOF")], ['Accept' => 'application/json'])->assertOk();
            $this->asRole('Finance');
            $this->api('verifyTermScan', [$t['id'], 'Valid', ''])->assertOk();
            $this->assertEquals($settlement, (array) DB::table('apm_settlements')->first());
            $this->assertCount(1, $this->term($t['id'])['paymentHistory']);
            $this->assertSame($archive, PrototypeWorkspace::first()->getRawOriginal('state'));
        }
    }

    public function test_new_term_defaults_to_finance_flow_and_generic_save_cannot_restore_legacy_payment_authority(): void
    {
        $t = $this->setupTerm(false, 5, true);
        $this->assertSame('kasbon-v1', $t['paymentFlow']);
        $this->assertTrue(PrototypeDomain::financeTaskPending($t));
        $this->api('requestPartialPayment', [$t['id']])->assertUnprocessable();
        $this->asRole('Finance');
        $this->api('recordApm', [$t['id'], 'APM/BYPASS', true])->assertUnprocessable();
        $this->assertDatabaseCount('apm_settlements', 0);
    }

    public function test_finance_is_readonly_before_head_and_private_fields_cannot_be_forged(): void
    {
        $t = $this->setupTerm(false, 4);
        $this->assertTrue(PrototypeDomain::financeTaskPending($t));
        $this->assertArrayNotHasKey('kasbon', $t);
        $this->assertArrayNotHasKey('transferProof', $t);
        $this->payee($t['spkId']);
        $this->asRole('Finance');
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $this->form($t))->assertUnprocessable();
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', ''])->assertUnprocessable();
        $this->assertDatabaseCount('finance_kasbons', 0);
        $this->asRole('PM Konstruksi');
        $this->getJson('/api/finance/spk/'.$t['spkId'].'/payee')->assertForbidden();
        $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon')->assertForbidden();
        $this->get('/api/finance/files/'.DB::table('finance_private_files')->value('id_finance_file'))->assertForbidden();
        $this->api('_issueKasbon', [$t['id'], []])->assertUnprocessable();
    }

    public function test_wrong_total_missing_payee_invalid_fields_stale_revision_and_replay(): void
    {
        $t = $this->setupTerm();
        $this->asRole('Finance');
        $form = $this->form($t);
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $form)->assertUnprocessable();
        $payee = $this->payee($t['spkId']);
        $rev = PrototypeWorkspace::first()->revision;
        $uuid = (string) Str::uuid();
        $path = '/api/finance/spk/'.$t['spkId'].'/payee';
        $this->write($path, $payee, $uuid, $rev)->assertOk();
        $this->write($path, $payee, $uuid, $rev)->assertOk()->assertJsonPath('replayed', true);
        $this->write($path, [...$payee, 'bank' => 'Changed'], $uuid, $rev)->assertConflict();
        $this->write($path, $payee, null, $rev)->assertConflict();
        $this->write($path, [...$payee, 'nik' => '1234'])->assertUnprocessable();
        $this->asRole('Finance');
        $bad = $form;
        $bad['rows'][0]['amount'] = '1.00';
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $bad)->assertUnprocessable();
        $form['payee'] = ['accountNumber' => 'FORGED'];
        $id = (string) Str::uuid();
        $r = PrototypeWorkspace::first()->revision;
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $form, $id, $r)->assertOk();
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $form, $id, $r)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('finance_kasbons', 1);
        $this->assertSame($payee['accountNumber'], $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon')->json('documents.0.data.payee.accountNumber'));
    }

    public function test_return_and_reissue_freeze_old_payee_and_keep_five_approvals(): void
    {
        $t = $this->setupTerm();
        $payee = $this->payee($t['spkId']);
        $issued = $this->issue($t);
        $old = (array) DB::table('finance_kasbons')->first();
        $this->api('decideFinancePayment', [$t['id'], 'Kembalikan', ''])->assertUnprocessable();
        $this->api('decideFinancePayment', [$t['id'], 'Kembalikan', 'Periksa rekening'])->assertOk();
        $this->asRole('Admin Konstruksi');
        $this->write('/api/finance/spk/'.$t['spkId'].'/payee', [...$payee, 'accountNumber' => '000999999999'])->assertOk();
        $new = $this->issue($t);
        $this->assertSame($issued['approvals'], $new['approvals']);
        $this->assertSame(2, $new['kasbon']['version']);
        $this->assertEquals($old, (array) DB::table('finance_kasbons')->where('id_kasbon', $old['id_kasbon'])->first());
        $docs = $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon')->json('documents');
        $this->assertSame('000999999999', $docs[0]['data']['payee']['accountNumber']);
        $this->assertSame($payee['accountNumber'], $docs[1]['data']['payee']['accountNumber']);
    }

    public function test_branch_role_worker_rechecks_and_private_query_budget(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(FinanceDocuments::class)->payee(PrototypeWorkspace::first(), $t['spkId']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(10, count($queries));
        fwrite(STDERR, "\nPrivate payee service + workspace fixture: ".count($queries)." queries.\n");
        $this->assertFalse(collect($queries)->contains(fn ($q) => str_contains($q['query'], 'designator_package_prices')));
        $account = $this->asRole('Finance');
        $branch = DB::table('branches')->where('name', '!=', 'DENPASAR')->value('id_branch');
        app(AccountAccess::class)->assign($account, 'FINANCE', [$branch]);
        $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon')->assertNotFound();
        $this->getJson('/api/finance/spk/'.$t['spkId'].'/payee')->assertNotFound();
        $this->get('/api/finance/files/'.DB::table('finance_private_files')->value('id_finance_file'))->assertNotFound();
    }

    public function test_issuing_kasbon_has_bounded_queries_and_does_not_rewrite_plans_ledger_or_approvals(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $this->asRole('Finance');
        $form = $this->form($t);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $form)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(75, count($queries));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\\s*(insert into|update|delete from) ["`]*(project_plans|boq_lines|boq_components|term_actuals|approval_runs|approval_steps|approval_decisions|document_files|term_scans|term_work_evidence|volume_balances|volume_movements)["` ]/i', $query['query']);
        }
        fwrite(STDERR, "\nKasbon issue one-term HTTP: ".count($queries)." queries.\n");
    }

    public function test_spk_attestation_requires_private_payee_even_with_forged_client_readiness(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $this->seedWorkflow('D');
        $this->asRole('Admin Konstruksi');
        $w = PrototypeWorkspace::first();
        $store = app(RelationalWorkflowStore::class);
        $state = $store->read($w);
        $before = $state;
        $state['spks'][0]['status'] = 'Draft';
        $state['spks'][0]['financialFlow'] = 'kasbon-v1';
        DB::transaction(fn () => $store->write($w, $state, $before));
        $spk = $state['spks'][0];
        $spk['_payeeReady'] = true;
        $this->api('saveSpkFinance', [$spk, 'attest'])->assertUnprocessable();
        $this->api('saveSpk', [$spk, 'attest'])->assertUnprocessable();
        $this->assertDatabaseHas('work_orders', ['source_id' => $spk['id'], 'status' => 'Draft']);
        $data = $this->payee($spk['id']);
        $this->write('/api/finance/spk/'.$spk['id'].'/payee', [...$data, 'ktpFileId' => (string) Str::uuid()])->assertUnprocessable();
        $this->api('saveSpkFinance', [$spk, 'attest'])->assertOk();
        $this->assertDatabaseHas('work_orders', ['source_id' => $spk['id'], 'status' => 'Sah']);
        $this->assertStringNotContainsString($data['nik'], $this->getJson('/api/prototype/state')->getContent());
    }

    public function test_reissued_kasbon_requires_new_proof_and_rejects_queued_proof_for_previous_bank_snapshot(): void
    {
        $t = $this->setupTerm();
        $payee = $this->payee($t['spkId']);
        $this->issue($t);
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', ''])->assertOk();
        $oldProof = $this->upload('transfer-proof', $t['id']);
        $task = $this->post('/api/finance/uploads/transfer-proof/'.$t['id'], ['file' => UploadedFile::fake()->createWithContent('bank-old.pdf', "%PDF-1.4\nold bank QA\n%%EOF"), 'revision' => (string) PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
        $this->api('decideFinancePayment', [$t['id'], 'Kembalikan', 'Koreksi rekening'])->assertOk();
        $this->asRole('Admin Konstruksi');
        $this->write('/api/finance/spk/'.$t['spkId'].'/payee', [...$payee, 'accountNumber' => '000555555555'])->assertOk();
        $updated = $this->issue($t);
        $this->assertArrayNotHasKey('transferProof', $updated);
        $this->assertSame($oldProof, $updated['transferProofHistory'][0]['id']);
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', ''])->assertOk();
        app(UploadQueue::class)->process($task);
        $this->assertDatabaseHas('upload_tasks', ['id_upload_task' => $task, 'status' => 'failed']);
        $this->assertDatabaseMissing('finance_private_files', ['id_finance_file' => $task]);
        $this->api('recordApm', [$t['id'], 'APM/NEW/BANK', true])->assertUnprocessable();
        $fresh = $this->upload('transfer-proof', $t['id']);
        $this->api('recordApm', [$t['id'], 'APM/NEW/BANK', true])->assertOk();
        $this->assertDatabaseHas('apm_settlements', ['kasbon_id' => $updated['kasbon']['id'], 'transfer_file_id' => $fresh]);
    }

    public function test_queue_revalidates_branch_and_private_file_ownership_without_writing_a_receipt(): void
    {
        $t = $this->setupTerm();
        $admin = auth()->user();
        $uuid = (string) Str::uuid();
        $task = $this->post('/api/finance/uploads/mandor-ktp/'.$t['spkId'], ['file' => UploadedFile::fake()->image('contains-private-name.png'), 'revision' => (string) PrototypeWorkspace::first()->revision, 'request_id' => $uuid], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
        $this->assertDatabaseHas('upload_tasks', ['id_upload_task' => $task, 'original_name' => 'mandor-ktp']);
        $branch = DB::table('branches')->where('name', '!=', 'DENPASAR')->value('id_branch');
        app(AccountAccess::class)->assign($admin, 'ADMIN_KONSTRUKSI', [$branch]);
        $rev = PrototypeWorkspace::first()->revision;
        app(UploadQueue::class)->process($task);
        $this->assertDatabaseHas('upload_tasks', ['id_upload_task' => $task, 'status' => 'failed']);
        $this->assertDatabaseMissing('finance_private_files', ['id_finance_file' => $task]);
        $this->assertDatabaseMissing('prototype_audits', ['request_id' => $uuid]);
        $this->assertSame($rev, PrototypeWorkspace::first()->revision);
    }

    public function test_existing_scan_requires_verification_and_settlement_has_one_same_term_authority(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $this->asRole('Admin Konstruksi');
        $this->post('/api/prototype/payments/'.$t['id'].'/scan', ['revision' => (string) PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('signed.pdf', "%PDF-1.4\nwet QA\n%%EOF")], ['Accept' => 'application/json'])->assertOk();
        $this->asRole('Finance');
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', ''])->assertUnprocessable();
        $this->api('verifyTermScan', [$t['id'], 'Valid', ''])->assertOk();
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', ''])->assertOk();
        $this->upload('transfer-proof', $t['id']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->api('recordApm', [$t['id'], 'APM/KASBON/FK', true])->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(75, count($queries));
        fwrite(STDERR, "\nKasbon APM one-term HTTP: ".count($queries)." queries.\n");
        $settle = (array) DB::table('apm_settlements')->first();
        foreach ([['kasbon_id' => null], ['transfer_file_id' => null], ['payment_request_id' => (string) Str::uuid()], ['verified_scan_id' => (string) Str::uuid()]] as $change) {
            try {
                DB::table('apm_settlements')->where('id_apm_settlement', $settle['id_apm_settlement'])->update($change);
                $this->fail('Authority must fail closed.');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/settlement_authority|FOREIGN KEY/i', $e->getMessage());
            }
        }
        $this->assertEquals($settle, (array) DB::table('apm_settlements')->first());
    }

    public function test_current_runtime_requires_full_previous_payment_even_for_an_old_apm_document(): void
    {
        $t = $this->setupTerm();
        $state = $this->getJson('/api/prototype/state')->json('state');
        $state['terms'][0]['apmStatus'] = 'Valid';
        $state['terms'][0]['apm'] = 'APM/HISTORICAL';
        $state['terms'][0]['paid'] = 0;
        unset($state['terms'][0]['paymentFlow'], $state['terms'][0]['financePayment']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('harus lunas');
        app(PrototypeDomain::class)->execute($state, 'createTerm', [$t['spkId']]);
    }

    public function test_migration_also_refuses_unpaid_new_documents_before_any_private_record_exists(): void
    {
        $this->setupTerm(false, 0);
        $this->assertDatabaseCount('finance_kasbons', 0);
        $migration = require database_path('migrations/2026_11_11_000001_create_finance_kasbon_workflow.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback ditolak');
        $migration->down();
    }

    public function test_finance_sql_failure_rolls_back_full_payment_and_migration_refuses_history(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', ''])->assertOk();
        $this->upload('transfer-proof', $t['id']);
        $before = $this->term($t['id']);
        $revision = PrototypeWorkspace::first()->revision;
        DB::statement("CREATE TRIGGER block_kasbon_settle BEFORE INSERT ON apm_settlements BEGIN SELECT RAISE(ABORT, 'forced QA failure'); END");
        $this->api('recordApm', [$t['id'], 'APM/ROLLBACK', true])->assertStatus(500);
        $this->assertSame($before, $this->term($t['id']));
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertDatabaseCount('apm_settlements', 0);
        DB::statement('DROP TRIGGER block_kasbon_settle');
        $this->api('recordApm', [$t['id'], 'APM/ROLLBACK', true])->assertOk();
        $migration = require database_path('migrations/2026_11_11_000001_create_finance_kasbon_workflow.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback ditolak');
        $migration->down();
    }
}
