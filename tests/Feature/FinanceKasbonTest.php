<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\FinanceDocuments;
use App\Services\KasbonSettlement;
use App\Services\PrototypeDomain;
use App\Services\RelationalWorkflowStore;
use App\Services\UploadQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
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
        if ($command === 'decideFinancePayment' && count($args) === 3) {
            $args[] = $this->term($args[0])['kasbon']['id'] ?? '';
        }

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

    public function test_mandor_photos_complete_without_browser_auth_and_worker_restores_identity(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $state = $this->seedWorkflow('D');
        $admin = $this->asRole('Admin Konstruksi');
        $spk = $state['spks'][0]['id'];
        foreach (['mandor-ktp', 'mandor-bank'] as $kind) {
            $task = $this->post('/api/finance/uploads/'.$kind.'/'.$spk, ['file' => UploadedFile::fake()->image('identity.png'), 'revision' => (string) PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
            Auth::forgetGuards();
            Auth::forgetUser();
            $this->assertNull(Auth::user());
            DB::enableQueryLog();
            DB::flushQueryLog();
            app(UploadQueue::class)->process($task);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertLessThanOrEqual(75, count($queries));
            fwrite(STDERR, "\nSessionless $kind worker: ".count($queries)." queries.\n");
            $this->assertNull(Auth::user());
            $this->assertDatabaseHas('upload_tasks', ['id_upload_task' => $task, 'status' => 'completed', 'error' => null]);
            $this->assertDatabaseHas('finance_private_files', ['id_finance_file' => $task, 'kind' => $kind, 'uploaded_by' => $admin->id_user]);
            $this->asRole('Admin Konstruksi');
            $this->get('/api/finance/files/'.$task)->assertOk();
        }
    }

    public function test_payee_validation_names_ktp_and_bank_fields_without_account_username_messages(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $state = $this->seedWorkflow('D');
        $this->asRole('Admin Konstruksi');
        $path = '/api/finance/spk/'.$state['spks'][0]['id'].'/payee';
        $before = PrototypeWorkspace::first()->revision;
        foreach (['123', '00112233445566X7', '0011 223344556677'] as $nik) {
            $this->write($path, ['nik' => $nik])->assertUnprocessable()->assertJsonPath('errors.nik.0', 'NIK KTP Mandor harus terdiri dari tepat 16 digit angka tanpa spasi atau tanda baca.');
        }
        $this->write($path, ['accountNumber' => '12.34'])->assertUnprocessable()->assertJsonPath('errors.accountNumber.0', 'Nomor rekening harus terdiri dari 1–40 digit angka tanpa spasi atau tanda baca.');
        $this->assertSame($before, PrototypeWorkspace::first()->revision);
        $this->assertDatabaseCount('mandor_payee_profiles', 0);
        $this->write($path, ['nik' => ' 0011223344556677 ', 'accountNumber' => ' 0001234567 ', 'bank' => ' Bank QA '])->assertOk();
        $this->getJson($path)->assertOk()->assertJsonPath('profile.nik', '0011223344556677')->assertJsonPath('profile.accountNumber', '0001234567')->assertJsonPath('profile.bank', 'Bank QA');
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
        $t = $this->historicalKasbonFixture($t['id']);
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
        $spk = collect($s['spks'])->firstWhere('id', $t['spkId']);
        $amount = KasbonSettlement::quote(collect($s['deals'])->firstWhere('id', $spk['dealId']), $t)['net'];
        $payeeVersion = DB::table('mandor_payee_profiles as m')->join('work_orders as s', 's.id_spk', '=', 'm.spk_id')
            ->where('s.workspace_id', PrototypeWorkspace::first()->id)->where('s.source_id', $t['spkId'])->max('m.version');

        return ['payeeVersion' => max(1, (int) $payeeVersion), 'costCenter' => 'QA-COST', 'wbs' => 'QA-WBS', 'activity' => 'Kegiatan uji', 'place' => 'Tempat QA', 'date' => '2026-11-11', 'evp' => 'Pejabat Fisik QA', 'businessSupport' => 'Pejabat Support QA', 'rows' => [['accountCode' => 'QA-001', 'accountName' => 'Akun QA', 'text' => 'Termin '.$t['number'], 'amount' => (string) $amount]]];
    }

    private function issue(array $t): array
    {
        $this->asRole('Admin Konstruksi');
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $this->form($t))->assertOk();
        $this->asRole('Finance');

        return $this->term($t['id']);
    }

    public function test_legacy_paid_kasbon_physical_signer_is_audited_without_reissuing_or_changing_payment(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', ''])->assertOk();
        $this->upload('transfer-proof', $t['id']);
        $this->api('recordApm', [$t['id'], 'APM/OLD/001', true])->assertOk();
        $selected = $this->asRole('Mgr Finance & Settlement');
        $this->asRole('Finance');
        $before = $this->term($t['id']);
        $original = DB::table('finance_kasbons')->first();
        $settlement = DB::table('apm_settlements')->first();
        $archive = PrototypeWorkspace::first()->state;
        $revision = PrototypeWorkspace::first()->revision;
        $request = ['command' => 'setKasbonPrintSigner', 'args' => [$t['id'], $t['kasbon']['id'], 'db-user-'.$selected->id_user], 'revision' => $revision, 'request_id' => (string) Str::uuid()];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/api/prototype/commands', $request)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(50, count($queries));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert into|update|delete from) ["`]*(finance_kasbons|apm_settlements|project_plans|term_actuals|approval_runs|approval_steps|approval_decisions|document_files|volume_balances|volume_movements)["` ]/i', $query['query']);
        }
        fwrite(STDERR, "\nLegacy kasbon signer HTTP: ".count($queries)." queries.\n");
        $after = $this->term($t['id']);
        $signer = $after['kasbonPrintSigners'][$t['kasbon']['id']];
        $this->assertSame('db-user-'.$selected->id_user, $signer['userId']);
        $this->assertSame($selected->name, $signer['name']);
        $this->assertSame('Mgr Finance & Settlement Area Jawa Bali', $signer['job']);
        $this->assertSame($before['approvals'], $after['approvals']);
        unset($after['kasbonPrintSigners'], $after['kasbonPrintSignerHistory']);
        $this->assertSame($before, $after);
        $this->assertEquals($original, DB::table('finance_kasbons')->first());
        $this->assertEquals($settlement, DB::table('apm_settlements')->first());
        $this->assertSame($archive, PrototypeWorkspace::first()->state);
        $this->postJson('/api/prototype/commands', $request)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($revision + 1, PrototypeWorkspace::first()->revision);
        $this->assertCount(1, $this->term($t['id'])['kasbonPrintSignerHistory']);
        $w = PrototypeWorkspace::first();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $view = app(FinanceDocuments::class)->kasbon($w, $t['id'], false);
        $readQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $readQueries);
        $this->assertSame($signer, $view['documents'][0]['data']['printSigner']);
        $this->assertSame('Pejabat Support QA', $view['documents'][0]['data']['businessSupport']);
        // Account renames do not silently replace the explicitly saved identity.
        $selected->update(['name' => 'New account name']);
        $this->assertSame($signer, app(FinanceDocuments::class)->kasbon($w, $t['id'], false)['documents'][0]['data']['printSigner']);
        $this->postJson('/api/prototype/commands', [...$request, 'request_id' => (string) Str::uuid()])->assertConflict();
    }

    public function test_legacy_kasbon_signer_requires_current_version_authorized_role_and_active_branch_account(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $selected = $this->asRole('Mgr Finance & Settlement');
        $args = [$t['id'], $t['kasbon']['id'], 'db-user-'.$selected->id_user];
        $this->api('setKasbonPrintSigner', $args)->assertForbidden();
        $this->asRole('PM Konstruksi');
        $this->api('setKasbonPrintSigner', $args)->assertForbidden();
        $this->asRole('Finance');
        $before = PrototypeWorkspace::first()->revision;
        $this->api('setKasbonPrintSigner', [$t['id'], 'archived-version', $args[2]])->assertUnprocessable();
        $this->api('setKasbonPrintSigner', [$t['id'], $args[1], 'db-user-'.$this->accounts['PM Konstruksi']->id_user])->assertUnprocessable();
        $other = DB::table('branches')->where('name', '!=', 'DENPASAR')->value('id_branch');
        app(AccountAccess::class)->assign($selected, 'MGR_FINANCE_SETTLEMENT', [$other]);
        $this->api('setKasbonPrintSigner', $args)->assertUnprocessable();
        $this->assertSame($before, PrototypeWorkspace::first()->revision);
        $selected->forceFill(['active' => false])->save();
        $this->api('setKasbonPrintSigner', $args)->assertUnprocessable();
        $selected->forceFill(['active' => true])->save();
        app(AccountAccess::class)->assign($selected, 'MGR_FINANCE_SETTLEMENT', DB::table('branches')->pluck('id_branch')->all());
        $this->asRole('Admin Konstruksi');
        $this->api('setKasbonPrintSigner', $args)->assertOk();
        $this->asRole('Finance');
        $this->api('decideFinancePayment', [$t['id'], 'Kembalikan', 'Koreksi kasbon'])->assertOk();
        $t = $this->issue($this->term($t['id']));
        $this->api('setKasbonPrintSigner', $args)->assertUnprocessable();
        $documents = $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?documents_only=1')->assertOk()->json('documents');
        $this->assertCount(2, $documents);
        foreach ($documents as $document) {
            $this->assertArrayNotHasKey('printSigner', $document['data'], 'Archived originals and a new kasbon do not inherit the override.');
        }
        $finance = $this->asRole('Finance');
        app(AccountAccess::class)->assign($finance, 'FINANCE', [$other]);
        $this->api('setKasbonPrintSigner', [$t['id'], $t['kasbon']['id'], $args[2]])->assertForbidden();
    }

    public function test_physical_signer_cannot_reassign_seven_stage_approvals_or_bypass_a_reopened_head(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $selected = $this->asRole('Mgr Finance & Settlement');
        $this->asRole('Finance');
        $state = $this->getJson('/api/prototype/state')->assertOk()->json('state');
        $args = [$t['id'], $t['kasbon']['id'], 'db-user-'.$selected->id_user];
        $current = collect($state['terms'])->search(fn ($row) => $row['id'] === $t['id']);
        $changed = $state;
        $changed['terms'][$current]['paymentFlow'] = 'kasbon-settlement-v2';
        try {
            app(PrototypeDomain::class)->execute($changed, 'setKasbonPrintSigner', $args);
            $this->fail('Seven-stage assignment must not be replaced by a physical-only signer.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('tujuh approval', $e->getMessage());
        }
        $spk = collect($state['spks'])->firstWhere('id', $t['spkId']);
        $deal = collect($state['deals'])->firstWhere('id', $spk['dealId']);
        $pi = collect($state['projects'])->search(fn ($row) => $row['id'] === $deal['projectId']);
        $changed = $state;
        $changed['projects'][$pi]['status'] = 'Diajukan';
        $changed['projects'][$pi]['headApprovalReopen'] = ['version' => 1];
        try {
            app(PrototypeDomain::class)->execute($changed, 'setKasbonPrintSigner', $args);
            $this->fail('Head correction must continue blocking descendants.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Head of PMO', $e->getMessage());
        }
        $this->assertArrayNotHasKey('kasbonPrintSigners', $this->term($t['id']));
    }

    public function test_pending_legacy_payment_routes_to_actual_manager_then_returns_to_finance_without_repeating_work_approvals(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $manager = $this->asRole('Mgr Finance & Settlement');
        $this->asRole('Finance');
        $before = $this->term($t['id']);
        $original = DB::table('finance_kasbons')->first();
        $decisions = DB::table('approval_decisions')->get()->toArray();
        $archive = PrototypeWorkspace::first()->state;
        $revision = PrototypeWorkspace::first()->revision;
        $input = ['command' => 'routeLegacyFinance', 'args' => [[['id' => $t['id'], 'kasbonId' => $t['kasbon']['id']]], 'db-user-'.$manager->id_user], 'revision' => $revision, 'request_id' => (string) Str::uuid()];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/api/prototype/commands', $input)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(50, count($queries));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert into|update|delete from) ["`]*(finance_kasbons|apm_settlements|project_plans|term_actuals|approval_runs|approval_steps|approval_decisions|document_files|volume_balances|volume_movements)["` ]/i', $query['query']);
        }
        fwrite(STDERR, "\nLegacy routing single HTTP: ".count($queries)." queries.\n");
        $this->getJson('/api/finance/payments')->assertOk()->assertJsonPath('items.0.approval', 'Menunggu Mgr Finance & Settlement');
        $this->postJson('/api/prototype/commands', $input)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($revision + 1, PrototypeWorkspace::first()->revision);
        $routed = $this->term($t['id']);
        unset($routed['legacySettlementReview'], $routed['legacySettlementReviewHistory']);
        $this->assertSame($before, $routed);
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', ''])->assertUnprocessable();
        $this->api('settleFinancePayment', [$t['id'], ''])->assertUnprocessable();
        $this->api('recordApm', [$t['id'], 'APM/BYPASS/001', true])->assertUnprocessable();
        $this->post('/api/finance/uploads/transfer-proof/'.$t['id'], ['file' => UploadedFile::fake()->createWithContent('proof.pdf', '%PDF-1.4'), 'request_id' => (string) Str::uuid(), 'revision' => (string) PrototypeWorkspace::first()->revision], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->api('setKasbonPrintSigner', [$t['id'], $t['kasbon']['id'], 'db-user-'.$manager->id_user])->assertUnprocessable();
        $this->asRole('Superadmin');
        $this->api('decideLegacySettlementReview', [$t['id'], $t['kasbon']['id'], 'Setujui', ''])->assertForbidden();
        $this->asRole('Mgr Finance & Settlement');
        $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?documents_only=1')->assertOk()->assertJsonPath('documents.0.data.printSigner.name', $manager->name);
        $this->api('decideLegacySettlementReview', [$t['id'], 'stale-kasbon', 'Setujui', ''])->assertUnprocessable();
        $this->api('decideLegacySettlementReview', [$t['id'], $t['kasbon']['id'], 'Setujui', 'Berkas sesuai'])->assertOk();
        $this->assertTrue(PrototypeDomain::legacyReviewApproved($this->term($t['id'])));
        $this->getJson('/api/finance/payments')->assertOk()->assertJsonPath('items.0.approval', 'Mgr Finance disetujui · Menunggu Finance');
        $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?documents_only=1')->assertOk()->assertJsonPath('documents.0.data.printSignerApproval.userId', 'db-user-'.$manager->id_user);
        $this->assertEquals($original, DB::table('finance_kasbons')->first());
        $this->assertEquals($decisions, DB::table('approval_decisions')->get()->toArray());
        $this->assertSame($archive, PrototypeWorkspace::first()->state);
        $this->assertSame($before['approvals'], $this->term($t['id'])['approvals']);
        $this->asRole('Finance');
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', ''])->assertOk();
        $this->getJson('/api/finance/payments')->assertOk()->assertJsonPath('items.0.approval', 'Finance disetujui · Proses transfer');
        $this->api('routeLegacyFinance', $input['args'])->assertUnprocessable();
        $this->upload('transfer-proof', $t['id']);
        $this->api('settleFinancePayment', [$t['id'], ''])->assertOk();
        $this->assertEquals($before['kasbon']['amount'], $this->term($t['id'])['paid']);
    }

    public function test_manager_return_requires_note_and_new_kasbon_review_without_changing_five_work_approvals(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $manager = $this->asRole('Mgr Finance & Settlement');
        $this->asRole('Finance');
        $this->api('routeLegacyFinance', [[['id' => $t['id'], 'kasbonId' => $t['kasbon']['id']]], 'db-user-'.$manager->id_user])->assertOk();
        $approvals = $this->term($t['id'])['approvals'];
        $this->asRole('Mgr Finance & Settlement');
        $this->api('decideLegacySettlementReview', [$t['id'], $t['kasbon']['id'], 'Kembalikan', ''])->assertUnprocessable();
        $this->api('decideLegacySettlementReview', [$t['id'], $t['kasbon']['id'], 'Kembalikan', 'Perbaiki rincian'])->assertOk();
        $this->assertSame('Dikembalikan', $this->term($t['id'])['financePayment']['status']);
        $new = $this->issue($this->term($t['id']));
        $this->assertNotSame($t['kasbon']['id'], $new['kasbon']['id']);
        $this->assertTrue(PrototypeDomain::legacyReviewPending($new));
        $this->assertFalse(PrototypeDomain::legacyReviewApproved($new));
        $this->assertSame($approvals, $new['approvals']);
        $this->assertCount(3, $new['legacySettlementReviewHistory']);
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', ''])->assertUnprocessable();
        $this->asRole('Mgr Finance & Settlement');
        $this->api('decideLegacySettlementReview', [$t['id'], $t['kasbon']['id'], 'Setujui', ''])->assertUnprocessable();
        $this->api('decideLegacySettlementReview', [$t['id'], $new['kasbon']['id'], 'Setujui', ''])->assertOk();
        $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?documents_only=1')->assertOk()->assertJsonPath('documents.0.data.printSignerApproval.kasbonId', $new['kasbon']['id']);
    }

    public function test_routing_rejects_unauthorized_roles_branches_inactive_manager_duplicates_and_stale_version_atomically(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $manager = $this->asRole('Mgr Finance & Settlement');
        $target = ['id' => $t['id'], 'kasbonId' => $t['kasbon']['id']];
        $args = [[$target], 'db-user-'.$manager->id_user];
        $this->asRole('Admin Konstruksi');
        $this->api('routeLegacyFinance', $args)->assertForbidden();
        $this->asRole('Finance');
        $revision = PrototypeWorkspace::first()->revision;
        $this->api('routeLegacyFinance', [[...$args[0], $target], $args[1]])->assertUnprocessable();
        $this->api('routeLegacyFinance', [[...$args[0], ['id' => $t['id'], 'kasbonId' => 'stale']], $args[1]])->assertUnprocessable();
        $this->api('routeLegacyFinance', [array_fill(0, 51, $target), $args[1]])->assertUnprocessable();
        $this->api('routeLegacyFinance', [[['id' => [], 'kasbonId' => $target['kasbonId']]], $args[1]])->assertUnprocessable();
        $manager->forceFill(['active' => false])->save();
        $this->api('routeLegacyFinance', $args)->assertUnprocessable();
        $manager->forceFill(['active' => true])->save();
        $other = DB::table('branches')->where('name', '!=', 'DENPASAR')->value('id_branch');
        app(AccountAccess::class)->assign($manager, 'MGR_FINANCE_SETTLEMENT', [$other]);
        $this->api('routeLegacyFinance', $args)->assertUnprocessable();
        $finance = $this->asRole('Finance');
        app(AccountAccess::class)->assign($finance, 'FINANCE', [$other]);
        $this->api('routeLegacyFinance', $args)->assertForbidden();
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertArrayNotHasKey('legacySettlementReview', app(RelationalWorkflowStore::class)->read(PrototypeWorkspace::first())['terms'][0]);
    }

    public function test_bulk_legacy_routing_is_atomic_bounded_and_does_not_read_private_ciphertext_per_term(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $manager = $this->asRole('Mgr Finance & Settlement');
        $this->asRole('Finance');
        $w = PrototypeWorkspace::first();
        $store = app(RelationalWorkflowStore::class);
        $before = $store->read($w);
        $s = $before;
        $original = DB::table('finance_kasbons')->first();
        $baseSpk = collect($s['spks'])->firstWhere('id', $t['spkId']);
        $baseDeal = collect($s['deals'])->firstWhere('id', $baseSpk['dealId']);
        $baseProject = collect($s['projects'])->firstWhere('id', $baseDeal['projectId']);
        $targets = [['id' => $t['id'], 'kasbonId' => $t['kasbon']['id']]];
        // Cloned synthetic memory-only graphs; no user database or live PII.
        for ($i = 1; $i < 8; $i++) {
            $p = $baseProject;
            $p['id'] = $p['rootId'] = 'route-pp-'.$i;
            $p['no'] = 'ROUTE/PP/'.$i;
            $d = $baseDeal;
            $d['id'] = 'route-deal-'.$i;
            $d['no'] = 'ROUTE/BA/'.$i;
            $d['projectId'] = $p['id'];
            $v = $baseSpk;
            $v['id'] = 'route-spk-'.$i;
            $v['no'] = 'ROUTE/SPK/'.$i;
            $v['dealId'] = $d['id'];
            $copy = $t;
            $copy['id'] = 'route-term-'.$i;
            $copy['spkId'] = $v['id'];
            $copy['kasbon']['id'] = (string) Str::uuid();
            $s['projects'][] = $p;
            $s['deals'][] = $d;
            $s['spks'][] = $v;
            $s['terms'][] = $copy;
            $targets[] = ['id' => $copy['id'], 'kasbonId' => $copy['kasbon']['id']];
        }
        DB::transaction(fn () => $store->write($w, $s, $before));
        foreach (array_slice($targets, 1) as $target) {
            DB::table('finance_kasbons')->insert(['id_kasbon' => $target['kasbonId'], 'term_id' => $store->id($w->id, 'terms', $target['id']), 'version' => 1, 'amount' => $original->amount, 'private_data' => 'NEVER-DECRYPT-FOR-ROUTING', 'issued_by' => $original->issued_by, 'issued_at' => now(), 'request_uuid' => (string) Str::uuid(), 'input_sha256' => str_repeat('a', 64)]);
        }
        $revision = $w->fresh()->revision;
        $bad = $targets;
        $bad[7]['kasbonId'] = 'stale';
        $this->api('routeLegacyFinance', [$bad, 'db-user-'.$manager->id_user])->assertUnprocessable();
        $this->assertSame($revision, $w->fresh()->revision);
        foreach ($store->read($w)['terms'] as $term) {
            $this->assertArrayNotHasKey('legacySettlementReview', $term);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->api('routeLegacyFinance', [$targets, 'db-user-'.$manager->id_user])->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(120, count($queries));
        $this->assertCount(1, collect($queries)->filter(fn ($q) => str_contains($q['query'], 'user_assignments') && str_contains($q['query'], 'join') && ! str_contains($q['query'], '"u"."id_user" = ?')), 'One batch assignment read at command bind, independent of term count.');
        foreach ($queries as $q) {
            $this->assertStringNotContainsString('private_data', $q['query']);
        }
        fwrite(STDERR, "\nLegacy routing 8-term HTTP: ".count($queries)." queries.\n");
        foreach ($store->read($w)['terms'] as $term) {
            $this->assertSame('db-user-'.$manager->id_user, $term['legacySettlementReview']['userId']);
        }
    }

    public function test_existing_paid_kasbon_can_correct_apm_without_rewriting_original_settlement_or_history(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', ''])->assertOk();
        $this->upload('transfer-proof', $t['id']);
        $this->api('recordApm', [$t['id'], 'APM/OLD/001', true])->assertOk();
        $paid = $this->term($t['id']);
        $before = (array) DB::table('apm_settlements')->first();
        $this->api('updatePaymentApm', [$t['id'], 'APM/CORRECTED/002'])->assertOk();
        $updated = $this->term($t['id']);
        $this->assertSame($paid['paymentHistory'], $updated['paymentHistory']);
        $this->assertSame($paid['paid'], $updated['paid']);
        $this->assertSame('APM/OLD/001', $updated['financePayment']['apmHistory'][0]['before']);
        $after = (array) DB::table('apm_settlements')->first();
        $this->assertSame('APM/CORRECTED/002', $after['apm_no']);
        unset($before['apm_no'], $after['apm_no']);
        $this->assertSame($before, $after);
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
            $domain = app(PrototypeDomain::class)->load($this->getJson('/api/prototype/state')->json('state'));
            $this->assertGreaterThan($paid['paid'], $domain->termTotal($paid), 'Mandor service withholding does not change the gross bill.');
            $this->assertEquals($paid['paid'], $domain->paymentDue($paid));
            $this->assertSame('mandor-service-pph25-v1', $paid['kasbon']['settlement']['policy']);
            $this->assertSame('Dibayar', $paid['financePayment']['status']);
            $this->assertSame('NOK', $paid['financePayment']['documentsAtPayment']);
            $this->assertEquals($paid['kasbon']['amount'], $paid['paid']);
            $settlement = (array) DB::table('apm_settlements')->first();
            $this->assertEquals($paid['paid'], $settlement['amount'], 'APM records the net transfer, not the gross invoice.');
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
        $this->assertFalse(PrototypeDomain::financeTaskPending($t));
        $this->api('requestPartialPayment', [$t['id']])->assertUnprocessable();
        $this->asRole('Finance');
        $this->api('recordApm', [$t['id'], 'APM/BYPASS', true])->assertUnprocessable();
        $this->assertDatabaseCount('apm_settlements', 0);
    }

    public function test_finance_is_readonly_before_head_and_private_fields_cannot_be_forged(): void
    {
        $t = $this->setupTerm(false, 4);
        $this->assertFalse(PrototypeDomain::financeTaskPending($t));
        $this->assertArrayNotHasKey('kasbon', $t);
        $this->assertArrayNotHasKey('transferProof', $t);
        $this->payee($t['spkId']);
        $this->asRole('Finance');
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $this->form($t))->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $this->form($t))->assertUnprocessable();
        $this->asRole('Finance');
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
        $this->asRole('Admin Konstruksi');
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
        $this->asRole('Admin Konstruksi');
        $form = $this->form($t);
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
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', 'Review versi lama', $issued['kasbon']['id']])->assertUnprocessable();
        $this->assertSame('Kasbon Terbit', $this->term($t['id'])['financePayment']['status']);
    }

    public function test_admin_submits_after_head_with_user_requested_defaults_then_finance_reviews_without_issuing(): void
    {
        $t = $this->setupTerm(false, 4);
        $this->payee($t['spkId']);
        $this->asRole('Admin Konstruksi');
        $this->assertFalse(PrototypeDomain::adminKasbonPending($t));
        $form = $this->form($t);
        unset($form['place'], $form['date'], $form['evp'], $form['businessSupport']);
        $form['issuer'] = 'Client cannot select maker';
        $form['issuerRole'] = 'Finance';
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $form)->assertUnprocessable();
        $this->assertDatabaseCount('finance_kasbons', 0);
        $this->asRole('Head of PMO');
        $this->api('decide', [$t['id'], 'Setujui', 'Reviewed'])->assertOk();
        $t = $this->term($t['id']);
        $decisions = $t['approvals'];
        $this->assertTrue(PrototypeDomain::adminKasbonPending($t));
        $this->assertFalse(PrototypeDomain::financeTaskPending($t));
        $this->asRole('Finance');
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $form)->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $counter = $this->getJson('/api/prototype/revision')->assertOk()->json('revision');
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $form)->assertOk();
        $this->assertDatabaseHas('finance_kasbons', ['issued_by' => $this->accounts['Admin Konstruksi']->getKey()]);
        $t = $this->term($t['id']);
        $this->assertSame($decisions, $t['approvals']);
        $this->assertFalse(PrototypeDomain::adminKasbonPending($t));
        $this->assertTrue(PrototypeDomain::financeTaskPending($t));
        $this->assertSame('Admin Konstruksi', $t['kasbon']['issuedRole']);
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', ''])->assertForbidden();
        $this->post('/api/finance/uploads/transfer-proof/'.$t['id'], ['file' => UploadedFile::fake()->image('proof.png'), 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertForbidden();
        $this->asRole('Finance');
        $this->getJson('/api/prototype/revision')->assertOk()->assertExactJson(['revision' => $counter + 1]);
        $data = $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon')->assertOk()->json('documents.0.data');
        $this->assertSame('Surabaya', $data['place']);
        $this->assertSame(now('Asia/Jakarta')->toDateString(), $data['date']);
        $this->assertSame('I GEDE WAHYU DANYNDRA', $data['evp']);
        $this->assertSame("ARIZA ARBA'ATUS S", $data['businessSupport']);
        $this->assertSame(2, $data['templateVersion']);
        $this->assertSame('Admin Konstruksi', $data['issuerRole']);
        $this->assertSame($this->accounts['Admin Konstruksi']->name, $data['issuer']);
        $this->postJson('/api/prototype/commands', ['command' => 'decideFinancePayment', 'args' => [$t['id'], 'Setujui', 'Missing reviewed version'],
            'revision' => PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->api('decideFinancePayment', [$t['id'], 'Setujui', 'Reviewed PP through kasbon'])->assertOk();
    }

    public function test_returned_kasbon_moves_back_to_admin_task_and_wrong_branch_admin_cannot_resubmit(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $old = DB::table('finance_kasbons')->first();
        $this->api('decideFinancePayment', [$t['id'], 'Kembalikan', 'Periksa Cost Center'])->assertOk();
        $t = $this->term($t['id']);
        $this->assertTrue(PrototypeDomain::adminKasbonPending($t));
        $this->assertFalse(PrototypeDomain::financeTaskPending($t));
        $admin = $this->asRole('Admin Konstruksi');
        $form = $this->form($t);
        $other = DB::table('branches')->where('name', '!=', 'DENPASAR')->value('id_branch');
        app(AccountAccess::class)->assign($admin, 'ADMIN_KONSTRUKSI', [$other]);
        $revision = PrototypeWorkspace::first()->revision;
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $form)->assertNotFound();
        $this->assertDatabaseCount('finance_kasbons', 1);
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertEquals($old, DB::table('finance_kasbons')->first());
    }

    public function test_lazy_form_and_review_reads_skip_unused_file_catalogs_without_changing_private_permissions(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $w = PrototypeWorkspace::first();
        $docs = app(FinanceDocuments::class);
        $audits = DB::table('prototype_audits')->count();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $payee = $docs->payee($w, $t['spkId'], false);
        $profileQueries = DB::getQueryLog();
        DB::flushQueryLog();
        $kasbon = $docs->kasbon($w, $t['id'], false);
        $documentQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $profileQueries);
        $this->assertCount(3, $documentQueries);
        foreach ([...$profileQueries, ...$documentQueries] as $q) {
            $this->assertMatchesRegularExpression('/^select /i', $q['query']);
            $this->assertStringNotContainsString('finance_private_files', $q['query']);
            $this->assertStringNotContainsString('designator_package_prices', $q['query']);
        }
        $this->assertCount(0, $payee['files']);
        $this->assertCount(0, $kasbon['files']);
        $this->assertNotEmpty($payee['profile']['accountNumber']);
        $this->assertSame('Admin Konstruksi', $kasbon['documents'][0]['data']['issuerRole']);
        $this->getJson('/api/finance/spk/'.$t['spkId'].'/payee?profile_only=1')->assertOk()->assertJsonCount(0, 'files');
        $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?documents_only=1')->assertOk()->assertJsonCount(0, 'files');
        $this->asRole('PM Konstruksi');
        $this->getJson('/api/finance/spk/'.$t['spkId'].'/payee?profile_only=1')->assertForbidden();
        $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?documents_only=1')->assertForbidden();
        $this->assertSame($audits, DB::table('prototype_audits')->count());
    }

    public function test_package_current_kasbon_read_is_uuid_bound_three_queries_and_never_decrypts_archived_versions(): void
    {
        $t = $this->setupTerm();
        $this->payee($t['spkId']);
        $t = $this->issue($t);
        $original = DB::table('finance_kasbons')->first();
        $this->api('decideFinancePayment', [$t['id'], 'Kembalikan', 'Koreksi kasbon'])->assertOk();
        $t = $this->issue($this->term($t['id']));
        $w = PrototypeWorkspace::first();
        $revision = $w->revision;
        $audits = DB::table('prototype_audits')->count();
        // An unreadable archived ciphertext must not even be selected for a current-only export.
        DB::table('finance_kasbons')->where('id_kasbon', $original->id_kasbon)->update(['private_data' => 'archive-not-selected']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $view = app(FinanceDocuments::class)->kasbon($w, $t['id'], false, true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $queries);
        $this->assertCount(1, $view['documents']);
        $this->assertSame($t['kasbon']['id'], $view['documents'][0]['id']);
        $this->assertCount(0, $view['files']);
        foreach ($queries as $q) {
            $this->assertMatchesRegularExpression('/^select /i', $q['query']);
            $this->assertStringNotContainsString('finance_private_files', $q['query']);
            $this->assertStringNotContainsString('designator_package_prices', $q['query']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?documents_only=1&current_only=1&kasbon_id='.$original->id_kasbon)
            ->assertOk()->assertJsonCount(1, 'documents')->assertJsonPath('documents.0.id', $t['kasbon']['id'])->assertJsonCount(0, 'files');
        $httpQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(50, count($httpQueries));
        fwrite(STDERR, "\nCurrent-only kasbon package HTTP: ".count($httpQueries)." queries; service 3 queries.\n");
        foreach ($httpQueries as $q) {
            $this->assertMatchesRegularExpression('/^select /i', $q['query']);
            $this->assertStringNotContainsString('finance_private_files', $q['query']);
        }
        $this->asRole('PM Konstruksi');
        $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?current_only=1')->assertForbidden();
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertSame($audits, DB::table('prototype_audits')->count());
    }

    public function test_refreshing_workspace_revision_cannot_silently_accept_a_different_payee_version(): void
    {
        $t = $this->setupTerm();
        $payee = $this->payee($t['spkId']);
        $this->asRole('Admin Konstruksi');
        $form = $this->form($t);
        $old = DB::table('mandor_payee_profiles')->first();
        $this->write('/api/finance/spk/'.$t['spkId'].'/payee', [...$payee, 'accountNumber' => '000555444333'])->assertOk();
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $form)->assertConflict();
        $this->assertDatabaseCount('finance_kasbons', 0);
        $form['payeeVersion'] = 2; // User explicitly reloads and checks the new recipient before retrying.
        $this->write('/api/finance/terms/'.$t['id'].'/kasbon', $form)->assertOk();
        $data = $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon')->assertOk()->json('documents.0.data');
        $this->assertSame('000555444333', $data['payee']['accountNumber']);
        $this->assertSame(2, $data['payee']['version']);
        $this->assertEquals($old, DB::table('mandor_payee_profiles')->where('id_payee', $old->id_payee)->first());
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
        $this->asRole('Admin Konstruksi');
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
        $this->assertStringContainsString('Branch penugasan akun', DB::table('upload_tasks')->where('id_upload_task', $task)->value('error'));
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
