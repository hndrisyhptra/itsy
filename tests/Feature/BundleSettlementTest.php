<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\AuthenticatedWorkspace;
use App\Services\FinancePaymentCatalog;
use App\Services\KasbonSettlement;
use App\Services\NegotiationProjectCatalog;
use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
use App\Services\UploadQueue;
use App\Services\WorkUnitAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class BundleSettlementTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function api(string $command, array $args)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()]);
    }

    private function state(): array
    {
        return $this->getJson('/api/prototype/state')->assertOk()->json('state');
    }

    private function term(): array
    {
        return $this->state()['terms'][0];
    }

    private function netAmount(array $t): float
    {
        $s = $this->state();
        $v = collect($s['spks'])->firstWhere('id', $t['spkId']);

        return KasbonSettlement::quote(collect($s['deals'])->firstWhere('id', $v['dealId']), $t)['net'];
    }

    private function kasbon(array $t): array
    {
        return ['settlementUserId' => 'db-user-'.$this->accounts['Mgr Finance & Settlement']->getKey(), 'payeeVersion' => 1, 'costCenter' => 'QA CC', 'wbs' => 'QA WBS', 'activity' => 'QA kasbon', 'place' => 'Surabaya', 'date' => '2026-11-15', 'evp' => 'I GEDE WAHYU DANYNDRA', 'rows' => [['accountCode' => 'QA1', 'accountName' => 'QA Account', 'text' => 'QA Work', 'amount' => (string) $this->netAmount($t)]]];
    }

    private function submitKasbon(array $t, ?array $form = null)
    {
        return $this->postJson('/api/finance/terms/'.$t['id'].'/kasbon', ['data' => $form ?? $this->kasbon($t), 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()]);
    }

    private function attestWorkBarp(): void
    {
        $t = $this->term();
        if (PrototypeDomain::requiresBarp($t) && ! PrototypeDomain::barpCurrent($t)) {
            $this->api('attestBarp', [$t])->assertOk();
        }
    }

    private function attestCurrentWorkBapp(array $t): void
    {
        $b = collect($this->state()['bapps'])->firstWhere('termId', $t['id']);
        if ($b && $b['status'] !== 'Sah') {
            $this->attestWorkBarp();
            $this->api('attestBapp', [$b['id'], $b['no']])->assertOk();
        }
    }

    private function draftPayment(bool $prepareWork = true): array
    {
        $this->withoutVite();
        Storage::fake('local');
        $s = $this->seedWorkflow('D');
        $this->asRole('Admin Konstruksi');
        $this->api('createTerm', [$s['spks'][0]['id']])->assertOk();
        $t = $this->term();
        $this->assertSame('kasbon-settlement-v2', $t['paymentFlow']);
        $t['actuals'] = [$s['deals'][0]['rows'][0]['rowId'] => ['m' => 1, 's' => 1]];
        $t['barp']['place'] = 'Surabaya';
        $t['barp']['date'] = $s['spks'][0]['start'];
        $this->api('saveTerm', [$t, false])->assertOk();
        $this->completeMandorPayee($t['spkId']);
        if ($prepareWork) {
            $this->attestWorkBarp();
            $this->api('prepareBapp', [$t['id']])->assertOk();
        }

        return $this->term();
    }

    public function test_barp_attestation_precedes_explicit_bapp_creation_and_each_document_keeps_its_date(): void
    {
        $t = $this->draftPayment(false);
        $this->assertSame('attest-before-bapp-v1', $t['barpFlow']);
        $this->assertDatabaseCount('ba_completions', 0);
        $this->api('prepareBapp', [$t['id']])->assertUnprocessable();
        $this->submitKasbon($t)->assertUnprocessable();
        $revision = PrototypeWorkspace::first()->revision;
        $bad = $t;
        $bad['barp']['date'] = '2026-02-30';
        $this->api('attestBarp', [$bad])->assertUnprocessable();
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->asRole('Finance');
        $this->api('attestBarp', [$t])->assertForbidden();
        $this->asRole('Admin Operation');
        $this->api('attestBarp', [$t])->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $balances = DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->api('attestBarp', [$t])->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(80, $count);
        fwrite(STDERR, "\nBARP attestation HTTP: ".$count." queries.\n");
        $saved = $this->term();
        $this->assertTrue(PrototypeDomain::barpCurrent($saved));
        $this->assertSame('db-user-'.$this->accounts['Admin Konstruksi']->getKey(), $saved['barpAttestation']['by']);
        $this->assertDatabaseCount('ba_completions', 0);
        $this->assertDatabaseCount('approval_decisions', 0);
        $this->api('prepareBapp', [$t['id']])->assertOk();
        $b = $this->state()['bapps'][0];
        $this->assertSame($t['barp']['date'], $b['date']);
        $this->api('prepareBapp', [$t['id']])->assertOk();
        $this->assertDatabaseCount('ba_completions', 1);
        $this->api('attestBapp', [$b['id'], $b['no'], '', '2026-02-30'])->assertUnprocessable();
        $this->api('attestBapp', [$b['id'], $b['no'], '', '2026-11-23'])->assertOk();
        $b = $this->state()['bapps'][0];
        $this->assertSame('2026-11-23', $b['date']);
        $this->assertSame($t['barp']['date'], $this->term()['barp']['date']);
        $this->assertTrue(PrototypeDomain::bappCurrent($b, $this->term()));
        $row = DB::table('ba_completions')->first();
        $this->assertSame('2026-11-23', json_decode($row->document_data, true)['date']);
        // Forged attestation/marker fields cannot survive an ordinary draft edit.
        $changed = $this->term();
        $changed['barp']['date'] = '2026-11-22';
        $changed['barpAttestation']['source'] = PrototypeDomain::bappBasis($changed);
        unset($changed['barpFlow']);
        $this->api('saveTerm', [$changed, false])->assertOk();
        $changed = $this->term();
        $this->assertSame('attest-before-bapp-v1', $changed['barpFlow']);
        $this->assertFalse(PrototypeDomain::barpCurrent($changed));
        $this->assertCount(1, $changed['barpAttestationHistory']);
        $this->assertSame('Draft', $this->state()['bapps'][0]['status']);
        $this->api('attestBapp', [$b['id'], $b['no'], '', '2026-11-23'])->assertUnprocessable();
        $this->api('attestBarp', [$changed])->assertOk();
        $this->api('attestBapp', [$b['id'], $b['no'], '', '2026-11-23'])->assertOk();
        $this->assertDatabaseCount('approval_decisions', 0);
        $this->assertSame(0, $this->term()['paid']);
        $this->assertSame($balances, DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson());
        // Rejection still allows Admin to correct and re-attest work before a new kasbon cycle.
        $this->submitKasbon($this->term())->assertOk();
        $oldKasbon = $this->term()['kasbon']['id'];
        $this->asRole('PM Konstruksi');
        $this->api('decide', [$t['id'], 'Tolak', 'QA koreksi tanggal'])->assertOk();
        $this->asRole('Admin Konstruksi');
        $revised = $this->term();
        $revised['barp']['date'] = '2026-11-24';
        $this->api('saveTerm', [$revised, false])->assertOk();
        $this->api('attestBarp', [$this->term()])->assertOk();
        $this->api('attestBapp', [$b['id'], $b['no'], '', '2026-11-25'])->assertOk();
        $this->submitKasbon($this->term())->assertOk();
        $this->assertNotSame($oldKasbon, $this->term()['kasbon']['id']);
        $this->assertCount(0, $this->term()['approvals']);
        $this->assertCount(1, $this->term()['history']);
        $this->assertSame('Diajukan', $this->term()['status']);

    }

    public function test_pending_work_evidence_blocks_barp_attestation_without_losing_saved_work(): void
    {
        $t = $this->draftPayment(false);
        $task = $this->post('/api/uploads/payments/'.$t['id'].'/evidence', [
            'file' => UploadedFile::fake()->createWithContent('work.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
            'request_id' => (string) Str::uuid(), 'revision' => PrototypeWorkspace::first()->revision,
        ], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
        $revision = PrototypeWorkspace::first()->revision;
        $this->api('attestBarp', [$t])->assertUnprocessable()->assertJsonPath('message', 'Tunggu upload eviden selesai sebelum mengesahkan BA RP.');
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertFalse(PrototypeDomain::barpCurrent($this->term()));
        app(UploadQueue::class)->process($task);
        $this->api('attestBarp', [$this->term()])->assertOk();
        $this->assertTrue(PrototypeDomain::barpCurrent($this->term()));
        $this->assertDatabaseCount('ba_completions', 0);
        $this->assertDatabaseCount('approval_decisions', 0);
    }

    public function test_partial_bapp_blocks_kasbon_until_attested_and_never_releases_or_closes_work(): void
    {
        $t = $this->draftPayment();
        $this->assertSame('bapp-each-payment-v2', $t['completionFlow']);
        $this->get('/ba-rp')->assertOk();
        $this->get('/ba-rp/'.$t['id'])->assertOk();
        $b = collect($this->state()['bapps'])->firstWhere('termId', $t['id']);
        $this->assertSame('barp-each-payment-v2', $b['completionPolicy']);
        $this->assertStringEndsWith('/T1', $b['no']);
        $revision = PrototypeWorkspace::first()->revision;
        $balances = DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson();
        $this->submitKasbon($t)->assertUnprocessable();
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertDatabaseCount('finance_kasbons', 0);
        $this->asRole('Finance');
        $this->api('attestBapp', [$b['id'], $b['no']])->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $this->attestWorkBarp();
        $this->api('attestBapp', [$b['id'], $b['no']])->assertOk();
        $this->assertDatabaseCount('approval_decisions', 0);
        $this->assertSame($balances, DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson());
        $this->api('releaseVolume', [$b['id'], []])->assertUnprocessable();
        $this->api('createContinuation', [$b['id'], '', [], ''])->assertUnprocessable();
        $t['actuals'][array_key_first($t['actuals'])]['s'] = 2;
        $this->api('saveTerm', [$t, false])->assertOk();
        $changed = collect($this->state()['bapps'])->firstWhere('id', $b['id']);
        $this->assertSame('Draft', $changed['status']);
        $this->assertCount(1, $changed['attestationHistory']);
        $this->submitKasbon($this->term())->assertUnprocessable();
        $this->attestWorkBarp();
        $this->api('attestBapp', [$b['id'], $b['no']])->assertOk();
        $this->submitKasbon($this->term())->assertOk();
        $this->assertSame(0, $this->term()['paid']);
    }

    public function test_final_bapp_is_attested_before_any_payment_approval_and_changes_require_reattestation(): void
    {
        $t = $this->draftPayment();
        $t['type'] = 'Final';
        $balances = DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson();
        $this->api('saveTerm', [$t, false])->assertOk();
        $t = $this->term();
        $b = collect($this->state()['bapps'])->firstWhere('termId', $t['id']);
        $this->assertSame('Draft', $b['status']);
        $this->assertSame('Draft', $t['status']);
        $this->assertCount(0, $t['approvals']);
        $revision = PrototypeWorkspace::first()->revision;
        $this->submitKasbon($t)->assertUnprocessable();
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertDatabaseCount('finance_kasbons', 0);
        $this->asRole('Admin Operation');
        $this->api('prepareBapp', [$t['id']])->assertForbidden();
        $this->asRole('Finance');
        $this->api('attestBapp', [$b['id'], $b['no']])->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $this->attestWorkBarp();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->api('attestBapp', [$b['id'], $b['no']])->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(80, count($queries));
        fwrite(STDERR, "\nPreapproval BAPP attestation: ".count($queries)." queries.\n");
        $this->assertDatabaseHas('ba_completions', ['status' => 'ATTESTED', 'attested_by' => $this->accounts['Admin Konstruksi']->getKey()]);
        $b = collect($this->state()['bapps'])->firstWhere('id', $b['id']);
        $this->assertTrue(PrototypeDomain::bappCurrent($b, $this->term()));
        $this->assertSame('db-user-'.$this->accounts['Admin Konstruksi']->getKey(), $b['attestedBy']);
        $this->assertSame($balances, DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson());
        $this->assertDatabaseCount('approval_decisions', 0);
        $this->api('releaseVolume', [$b['id'], [$t['actuals'] ? array_key_first($t['actuals']) : '' => ['m' => 1, 's' => 1]]])->assertUnprocessable();
        $this->api('createContinuation', [$b['id'], '', [], 'QA'])->assertUnprocessable();
        $t['actuals'][array_key_first($t['actuals'])]['s'] = 2;
        $this->api('saveTerm', [$t, false])->assertOk();
        $b = collect($this->state()['bapps'])->firstWhere('id', $b['id']);
        $this->assertSame('Draft', $b['status']);
        $this->assertCount(1, $b['attestationHistory']);
        $this->assertArrayNotHasKey('source', $b);
        $this->assertDatabaseHas('ba_completions', ['status' => 'DRAFT', 'attested_by' => null, 'attested_at' => null]);
        $this->submitKasbon($this->term())->assertUnprocessable();
        $this->attestWorkBarp();
        $this->api('attestBapp', [$b['id'], $b['no']])->assertOk();
        $source = collect($this->state()['bapps'])->firstWhere('id', $b['id'])['source'];
        $this->attestCurrentWorkBapp($t);
        $this->submitKasbon($this->term())->assertOk();
        $this->assertCount(0, $this->term()['approvals']);
        $this->assertSame($source, collect($this->state()['bapps'])->firstWhere('id', $b['id'])['source']);
        $this->assertDatabaseCount('ba_completions', 1);
    }

    public function test_pending_legacy_final_can_explicitly_prepare_bapp_without_fabricating_approvals(): void
    {
        $t = $this->draftPayment();
        $s = $this->state();
        $s['terms'][0]['type'] = 'Final';
        $s['terms'][0]['status'] = 'Diajukan';
        unset($s['terms'][0]['completionFlow'], $s['terms'][0]['barpFlow'], $s['terms'][0]['barpAttestation']);
        $s['bapps'] = [];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(PrototypeDomain::class)->execute($s, 'prepareBapp', [$t['id']], 'bapp-legacy-qa');
        $after = $result['state'];
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame('bapp-legacy-qa', $after['bapps'][0]['id']);
        $this->assertSame($s['terms'][0]['approvals'], $after['terms'][0]['approvals']);
        $this->assertSame('Diajukan', $after['terms'][0]['status']);
        $this->assertSame($s['terms'][0]['paid'], $after['terms'][0]['paid']);
        $after = app(PrototypeDomain::class)->execute($after, 'attestBapp', ['bapp-legacy-qa', 'BAPP-LEGACY-QA'])['state'];
        $this->assertSame('Sah', $after['bapps'][0]['status']);
        $this->assertCount(0, $after['terms'][0]['approvals']);
    }

    public static function paymentTypes(): array
    {
        return [[false], [true]];
    }

    public function test_new_kasbon_captures_account_titles_in_one_existing_batch_and_keeps_issued_snapshot(): void
    {
        $t = $this->draftPayment();
        foreach (['Admin Konstruksi', ...PrototypeDomain::APPROVERS_V3, 'Mgr Finance & Settlement'] as $role) {
            $this->accounts[$role]->update(['jabatan' => 'Jabatan kasbon QA '.$role]);
        }
        $this->attestCurrentWorkBapp($t);
        $this->submitKasbon($t, $this->kasbon($t) + ['issuerJob' => 'FORGED', 'signers' => ['Head of PMO' => ['job' => 'FORGED']]])->assertOk();
        $data = $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?current_only=1')->assertOk()->json('documents.0.data');
        $this->assertSame('Jabatan kasbon QA Admin Konstruksi', $data['issuerJob']);
        foreach ([...PrototypeDomain::APPROVERS_V3, 'Mgr Finance & Settlement'] as $role) {
            $this->assertSame('Jabatan kasbon QA '.$role, $data['signers'][$role]['job']);
        }
        $original = DB::table('finance_kasbons')->value('private_data');
        $this->accounts['Mgr Finance & Settlement']->update(['jabatan' => 'Jabatan diubah kemudian']);
        $this->assertSame($data, $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?current_only=1')->assertOk()->json('documents.0.data'));
        $this->assertSame($original, DB::table('finance_kasbons')->value('private_data'));
    }

    #[DataProvider('paymentTypes')]
    public function test_kasbon_before_seven_real_decisions_transfer_and_apm_with_private_review_and_sql_order(bool $final): void
    {
        $t = $this->draftPayment();
        if ($final) {
            $t['type'] = 'Final';
            $this->api('saveTerm', [$t, false])->assertOk();
            $t = $this->term();
            $b = collect($this->state()['bapps'])->firstWhere('termId', $t['id']);
            $this->attestWorkBarp();
            $this->api('attestBapp', [$b['id'], $b['no']])->assertOk();
        }
        $archive = PrototypeWorkspace::first()->getRawOriginal('state');
        $this->api('submitFinanceTerm', [$t])->assertUnprocessable();
        $this->api('saveTerm', [$t, true])->assertUnprocessable();
        $bad = $this->kasbon($t);
        $bad['settlementUserId'] = 'db-user-'.$this->accounts['Mgr Bussiness Support']->getKey();
        $this->submitKasbon($t, $bad)->assertUnprocessable();
        $this->assertDatabaseCount('finance_kasbons', 0);
        $this->attestCurrentWorkBapp($t);
        $this->submitKasbon($t)->assertOk();
        $t = $this->term();
        $roles = [...PrototypeDomain::APPROVERS_V3, 'Mgr Finance & Settlement', 'Finance'];
        $this->assertSame($roles, PrototypeDomain::approvalRoles($t));
        $this->asRole('Finance');
        $this->api('decide', [$t['id'], 'Setujui', ''])->assertForbidden();
        foreach ($roles as $i => $role) {
            $this->asRole($role);
            $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?documents_only=1')->assertOk()->assertJsonPath('documents.0.data.templateVersion', 3);
            if ($i < 5) {
                $this->getJson('/api/finance/spk/'.$t['spkId'].'/payee')->assertForbidden();
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            $this->api('decide', [$t['id'], 'Setujui', 'QA'])->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $names = array_filter($queries, fn ($q) => str_contains($q['query'], 'from "users"') && str_contains($q['query'], '"name"') && ! str_contains($q['query'], 'user_assignments'));
            $this->assertLessThanOrEqual(2, count($names), 'Batched actor names, no per-step lookup.');
            $this->assertLessThanOrEqual(80, count($queries));
            fwrite(STDERR, "\nSettlement stage ".($i + 1).': '.count($queries)." queries.\n");
            $t = $this->term();
            $this->assertSame($i === 6 ? 'Disetujui' : 'Diajukan', $t['status']);
            $this->assertSame($t['kasbon']['id'], $t['approvals'][$i]['kasbonId']);
        }
        $this->assertDatabaseHas('approval_steps', ['step_no' => 6, 'expected_role' => 'MGR_FINANCE_SETTLEMENT', 'approval_order_version' => 3]);
        $this->assertDatabaseHas('approval_steps', ['step_no' => 7, 'expected_role' => 'FINANCE', 'assigned_user_id' => $this->accounts['Finance']->getKey()]);
        $this->api('recordApm', [$t['id'], 'QA-APM-7', true])->assertUnprocessable();
        $task = $this->post('/api/finance/uploads/transfer-proof/'.$t['id'], ['file' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\nproof QA\n%%EOF"), 'revision' => (string) PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
        Auth::forgetGuards();
        Auth::forgetUser();
        $this->assertNull(Auth::user());
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(UploadQueue::class)->process($task);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(80, count($queries), 'Sessionless transfer worker must not add per-approver queries.');
        fwrite(STDERR, "\nSessionless settlement transfer worker: ".count($queries)." queries.\n");
        $this->assertNull(Auth::user());
        $this->assertDatabaseHas('upload_tasks', ['id_upload_task' => $task, 'status' => 'completed']);
        $this->assertDatabaseHas('finance_private_files', ['id_finance_file' => $task, 'kind' => 'transfer-proof', 'uploaded_by' => $this->accounts['Finance']->getKey()]);
        $this->asRole('Finance');
        $this->get('/api/finance/files/'.$task)->assertOk();
        $this->api('recordApm', [$t['id'], 'QA-APM-7', true])->assertOk();
        $t = $this->term();
        $this->assertSame('Valid', $t['apmStatus']);
        $this->assertGreaterThan(0, $t['paid']);
        $this->assertSame($archive, PrototypeWorkspace::first()->getRawOriginal('state'));
        $this->asRole('Admin Konstruksi');
        $this->api('createTerm', [$t['spkId']])->{ $final ? 'assertUnprocessable' : 'assertOk' }();
        if ($final) {
            $this->assertSame('Sah', collect($this->state()['bapps'])->firstWhere('termId', $t['id'])['status']);
        }
    }

    #[DataProvider('paymentTypes')]
    public function test_transfer_settlement_without_apm_then_apm_edits_never_repeat_payment(bool $final): void
    {
        $t = $this->draftPayment();
        if ($final) {
            $t['type'] = 'Final';
            $this->api('saveTerm', [$t, false])->assertOk();
            $t = $this->term();
            $b = collect($this->state()['bapps'])->firstWhere('termId', $t['id']);
            $this->attestWorkBarp();
            $this->api('attestBapp', [$b['id'], $b['no']])->assertOk();
        }
        $this->attestCurrentWorkBapp($t);
        $this->submitKasbon($t)->assertOk();
        $this->asRole('Finance');
        $this->api('settleFinancePayment', [$t['id'], ''])->assertUnprocessable();
        $this->api('updatePaymentApm', [$t['id'], 'APM/EARLY'])->assertUnprocessable();
        foreach ([...PrototypeDomain::APPROVERS_V3, 'Mgr Finance & Settlement', 'Finance'] as $role) {
            $this->asRole($role);
            $this->api('decide', [$t['id'], 'Setujui', ''])->assertOk();
        }
        $this->api('settleFinancePayment', [$t['id'], ''])->assertUnprocessable();
        $task = $this->post('/api/finance/uploads/transfer-proof/'.$t['id'], ['file' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\nproof QA\n%%EOF"), 'revision' => (string) PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
        $this->api('settleFinancePayment', [$t['id'], ''])->assertUnprocessable();
        app(UploadQueue::class)->process($task);
        $this->api('settleFinancePayment', [$t['id'], 'bad space'])->assertUnprocessable();
        $request = ['command' => 'settleFinancePayment', 'args' => [$t['id'], ''], 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/api/prototype/commands', $request)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(80, count($queries));
        fwrite(STDERR, "\nOptional APM settlement: ".count($queries)." queries.\n");
        $this->postJson('/api/prototype/commands', $request)->assertOk()->assertJsonPath('replayed', true);
        $this->api('settleFinancePayment', [$t['id'], ''])->assertUnprocessable();
        $paid = $this->term();
        $this->assertSame('', $paid['apm']);
        $this->assertSame('Belum Terbit', $paid['apmStatus']);
        $this->assertTrue(PrototypeDomain::settlementRecorded($paid));
        $this->assertFalse(PrototypeDomain::financeTaskPending($paid));
        $this->assertCount(1, $paid['paymentHistory']);
        $this->assertSame('TRANSFER_PROOF', $paid['paymentHistory'][0]['source']);
        $settlement = (array) DB::table('apm_settlements')->first();
        $this->assertNull($settlement['apm_no']);
        $this->assertSame($task, $settlement['transfer_file_id']);
        $this->assertEquals($paid['paid'], $settlement['amount']);
        $migration = require base_path('database/migrations/2026_11_16_000001_allow_optional_payment_apm.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse existing null APM.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rollback ditolak', $e->getMessage());
        }
        $account = app(AuthenticatedWorkspace::class)->account();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $listing = app(FinancePaymentCatalog::class)->listing($account, ['status' => 'paid']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(4, $queries);
        $this->assertSame(1, $listing['summary']['paid']);
        $this->assertNull($listing['items'][0]['apm']);
        $this->asRole('Admin Konstruksi');
        $this->api('updatePaymentApm', [$t['id'], 'APM/FORBIDDEN'])->assertForbidden();
        $this->api('createTerm', [$t['spkId']])->{ $final ? 'assertUnprocessable' : 'assertOk' }();
        $this->asRole('Finance');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->api('updatePaymentApm', [$t['id'], 'APM/AFTER/001'])->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(80, count($queries));
        fwrite(STDERR, "\nOptional APM reference edit: ".count($queries)." queries.\n");
        $this->api('updatePaymentApm', [$t['id'], 'APM/EDIT/002'])->assertOk();
        $this->api('updatePaymentApm', [$t['id'], ''])->assertUnprocessable();
        $updated = $this->term();
        $this->assertSame($paid['paymentHistory'], $updated['paymentHistory']);
        $this->assertSame($paid['approvals'], $updated['approvals']);
        $this->assertSame($paid['paid'], $updated['paid']);
        $this->assertCount(2, $updated['financePayment']['apmHistory']);
        $after = (array) DB::table('apm_settlements')->first();
        $this->assertSame('APM/EDIT/002', $after['apm_no']);
        unset($settlement['apm_no'], $after['apm_no']);
        $this->assertSame($settlement, $after);
        $this->assertDatabaseCount('apm_settlements', 1);
        $this->assertSame(1, app(FinancePaymentCatalog::class)->listing($account, ['q' => 'APM/EDIT/002'])['summary']['paid']);
        $beforeRevision = PrototypeWorkspace::first()->revision;
        $this->postJson('/api/prototype/commands', ['command' => 'updatePaymentApm', 'args' => [$t['id'], 'APM/STALE'], 'revision' => $beforeRevision - 1, 'request_id' => (string) Str::uuid()])->assertConflict();
        $this->assertSame($beforeRevision, PrototypeWorkspace::first()->revision);
        $state = $this->state();
        $other = $updated;
        $other['id'] = 'other-payment';
        $other['apm'] = 'APM/DUPLICATE';
        $state['terms'][] = $other;
        try {
            app(PrototypeDomain::class)->execute($state, 'updatePaymentApm', [$t['id'], 'apm/duplicate']);
            $this->fail('APM duplicate must fail.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Nomor APM sudah digunakan', json_encode($e->errors()));
        }
        $otherBranch = DB::table('branches')->where('name', '!=', $state['projects'][0]['branch'])->value('id_branch');
        app(AccountAccess::class)->assign($this->accounts['Finance'], 'FINANCE', [$otherBranch]);
        $this->asRole('Finance');
        $this->api('updatePaymentApm', [$t['id'], 'APM/OUTSIDE'])->assertForbidden();
        $this->assertSame($beforeRevision, PrototypeWorkspace::first()->revision);

    }

    public static function rejectionStages(): array
    {
        return array_map(fn ($n) => [$n], range(0, 6));
    }

    #[DataProvider('rejectionStages')]
    public function test_settlement_rejection_preserves_kasbon_and_decisions_then_restarts_pm_with_new_version(int $stage): void
    {
        $t = $this->draftPayment();
        $this->attestCurrentWorkBapp($t);
        $this->submitKasbon($t)->assertOk();
        $roles = [...PrototypeDomain::APPROVERS_V3, 'Mgr Finance & Settlement', 'Finance'];
        foreach (array_slice($roles, 0, $stage) as $role) {
            $this->asRole($role);
            $this->api('decide', [$t['id'], 'Setujui', ''])->assertOk();
        }
        $this->asRole($roles[$stage]);
        $this->api('decide', [$t['id'], 'Tolak', ''])->assertUnprocessable();
        $this->api('decide', [$t['id'], 'Tolak', 'Perbaiki rincian'])->assertOk();
        $old = $this->term();
        $this->assertCount($stage + 1, $old['history']);
        $this->assertSame([], $old['approvals']);
        $this->asRole('Admin Konstruksi');
        $this->attestCurrentWorkBapp($old);
        $this->submitKasbon($old)->assertOk();
        $new = $this->term();
        $this->assertSame('PM Konstruksi', PrototypeDomain::nextApprovalRole($new));
        $this->assertNotSame($old['kasbon']['id'], $new['kasbon']['id']);
        $this->assertSame($old['kasbon'], $new['kasbonHistory'][0]);
        $this->assertSame($old['history'], $new['history']);
        $this->assertDatabaseCount('approval_runs', 2);
        $this->asRole('Mgr Finance & Settlement');
        $this->api('decide', [$t['id'], 'Setujui', ''])->assertForbidden();
    }

    private function package(bool $operation = false): array
    {
        $this->withoutVite();
        Storage::fake('local');
        $this->seedWorkflow('B');
        if ($operation) {
            $region = DB::table('branches')->where('name', 'PEKALONGAN')->value('region_id');
            foreach (['Manager SS', 'General Manager Operation'] as $role) {
                DB::table('users')->where('id_user', $this->accounts[$role]->id_user)->update(['access_scope' => 'REGION', 'id_regions' => $region]);
            }
        }
        $this->asRole($operation ? 'Admin Operation' : 'Admin Konstruksi');
        $s = $this->state();
        $p = app(PrototypeWorkspaceService::class)->scenario('B')['projects'][0];
        $p['id'] = $p['rootId'] = 'pp-bundle-qa';
        $p['issueDate'] = '2026-11-01';
        $p['workStartDate'] = '2026-11-02';
        $p['branch'] = 'PEKALONGAN';
        $p['unit'] = $operation ? 'Operation' : 'Konstruksi';
        $p['riskLevel'] = 'SEDANG';
        $p['costs']['permit'] = 0;
        foreach ($operation ? WorkUnitAccess::OPS_APPROVERS : PrototypeDomain::APPROVERS_V3 as $role) {
            $p['approverUserIds'][$role] = 'db-user-'.$this->accounts[$role]->getKey();
        }
        $k = collect($s['khs'])->first(fn ($k) => $k['active'] && ($k['s'] ?? 0) >= 100);
        $p['boq'] = [['id' => 'bundle-row', 'khsId' => $k['id'], 'qty' => 10, 'm' => $k['m'], 's' => $k['s']]];
        $budget = json_decode(file_get_contents(base_path('tests/Fixtures/pp-budget-vector.json')), true)['project']['planBudget'];
        $budget['mandor'] = [['rowId' => 'bundle-row', 'qtyM' => 0, 'qtyS' => 5, 'priceM' => 0, 'priceS' => 100]];
        $p['planBudget'] = $budget;
        $this->api('saveProject', [$p, true])->assertUnprocessable();
        $this->api('saveProjectBundle', [$p, false])->assertOk();
        $p = collect($this->state()['projects'])->firstWhere('id', $p['id']);
        $this->api('submitProjectBundle', [$p['id']])->assertUnprocessable();
        $account = app(AuthenticatedWorkspace::class)->account();
        $projectId = DB::table('project_plans')->where('source_id', $p['id'])->value('project_id');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $choices = app(NegotiationProjectCatalog::class)->plans($account, $projectId, 1);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $queries, 'Draft-bundle picker remains three bounded service queries.');
        $this->assertTrue(collect($choices['items'])->firstWhere('id', $p['id'])['eligible']);
        $d = app(PrototypeWorkspaceService::class)->scenario('C')['deals'][0];
        $d['id'] = 'bakh-bundle-qa';
        $d['projectId'] = $p['id'];
        $d['date'] = $d['start'] = $d['end'] = $p['schedule'][0]['start'];
        $d['rows'] = [['rowId' => 'bundle-row', 'm' => false, 's' => true, 'qm' => 0, 'qs' => 1, 'pm' => 0, 'ps' => 100, 'sphM' => 0, 'sphS' => 100, 'offerM' => 0, 'offerS' => 100, 'capM' => $k['m'], 'capS' => $k['s']]];
        $this->api('saveDeal', [$d, 'save'])->assertOk();
        $d = collect($this->state()['deals'])->firstWhere('id', $d['id']);
        $this->api('saveDeal', [$d, 'attest'])->assertUnprocessable();
        $v = app(PrototypeWorkspaceService::class)->scenario('D')['spks'][0];
        $v['id'] = 'spk-bundle-qa';
        $v['dealId'] = $d['id'];
        $v['start'] = $v['end'] = $d['start'];
        $v['date'] = $d['start'];
        $this->api('saveSpk', [$v, 'save'])->assertOk();
        $v = collect($this->state()['spks'])->firstWhere('id', $v['id']);
        $this->assertSame($d['start'], $v['date']);
        $this->completeMandorPayee($v['id']);

        return [$p, $d, $v];
    }

    public function test_operation_package_and_payment_follow_five_then_seven_real_approvals_with_unit_isolation(): void
    {
        [$p, $d, $v] = $this->package(true);
        $this->assertSame('operation-v1', $p['approvalScheme']);
        $this->assertSame('General Manager Operation', $d['signerRole']);
        $this->assertSame('pp-gm-operation', $v['signerSource']);
        $unit = DB::table('work_units')->where('code', 'OPERATION')->value('id_work_unit');
        $this->assertDatabaseHas('project_plans', ['source_id' => $p['id'], 'work_unit_id' => $unit]);
        $archive = PrototypeWorkspace::first()->getRawOriginal('state');
        $this->asRole('Admin Konstruksi');
        $this->assertNotContains($p['id'], array_column($this->state()['projects'], 'id'));
        $this->api('submitProjectBundle', [$p['id']])->assertForbidden();
        $this->asRole('Admin Operation');
        $this->assertSame(['Operation'], array_values(array_unique(array_column($this->state()['projects'], 'unit'))));
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        foreach (WorkUnitAccess::OPS_APPROVERS as $i => $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'Operation QA'])->assertOk();
            $this->assertDatabaseHas('work_orders', ['source_id' => $v['id'], 'status' => $i === 4 ? 'Sah' : 'Draft']);
        }
        $this->assertSame(5, DB::table('approval_steps')->where('approval_order_version', 4)->count());
        $this->asRole('Admin Operation');
        $this->api('createTerm', [$v['id']])->assertOk();
        $t = $this->term();
        $t['type'] = 'Final';
        $t['actuals'] = ['bundle-row' => ['m' => 0, 's' => 1]];
        $this->api('saveTerm', [$t, false])->assertOk();
        $t = $this->term();
        $this->attestWorkBarp();
        $this->api('prepareBapp', [$t['id']])->assertOk();
        $b = collect($this->state()['bapps'])->firstWhere('termId', $t['id']);
        $this->attestWorkBarp();
        $this->api('attestBapp', [$b['id'], $b['no']])->assertOk();
        $this->attestCurrentWorkBapp($t);
        $this->submitKasbon($t)->assertOk();
        $document = $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?current_only=1')->assertOk()->json('documents.0.data');
        $this->assertSame('operation-v1', $document['approvalScheme']);
        $this->assertArrayHasKey('General Manager Operation', $document['signers']);
        $this->assertArrayNotHasKey('Head of PMO', $document['signers']);
        $roles = [...WorkUnitAccess::OPS_APPROVERS, 'Mgr Finance & Settlement', 'Finance'];
        foreach ($roles as $i => $role) {
            $this->asRole($role);
            $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?current_only=1')->assertOk();
            $this->api('decide', [$t['id'], 'Setujui', 'Operation QA'])->assertOk();
            $t = $this->term();
            $this->assertSame($i === 6 ? 'Disetujui' : 'Diajukan', $t['status']);
            $this->assertSame(array_slice($roles, 0, $i + 1), array_column($t['approvals'], 'role'));
        }
        $this->assertSame(12, DB::table('approval_steps')->where('approval_order_version', 4)->count());
        $task = $this->post('/api/finance/uploads/transfer-proof/'.$t['id'], ['file' => UploadedFile::fake()->image('operation-transfer.png'), 'revision' => (string) PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
        Auth::forgetGuards();
        Auth::forgetUser();
        app(UploadQueue::class)->process($task);
        $this->assertDatabaseHas('upload_tasks', ['id_upload_task' => $task, 'status' => 'completed']);
        $this->asRole('Finance');
        $this->api('settleFinancePayment', [$t['id'], ''])->assertOk();
        $this->assertSame($this->netAmount($t), (float) $this->term()['paid']);
        $this->asRole('Admin Operation');
        $bapp = collect($this->state()['bapps'])->firstWhere('termId', $t['id']);
        $this->assertSame('Sah', $bapp['status']);

        $this->assertSame($archive, PrototypeWorkspace::first()->getRawOriginal('state'));
        $this->asRole('Admin Konstruksi');
        $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?current_only=1')->assertNotFound();
    }

    public function test_operation_mandor_identity_queue_uses_fresh_actor_and_private_unit_scope(): void
    {
        [$p, $d, $v] = $this->package(true);
        $admin = auth()->user();
        foreach (['mandor-ktp', 'mandor-bank'] as $kind) {
            $task = $this->post('/api/finance/uploads/'.$kind.'/'.$v['id'], ['file' => UploadedFile::fake()->image('identity.png'), 'revision' => (string) PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
            Auth::forgetGuards();
            Auth::forgetUser();
            app(UploadQueue::class)->process($task);
            $this->assertNull(Auth::user());
            $this->assertDatabaseHas('upload_tasks', ['id_upload_task' => $task, 'status' => 'completed']);
            $this->assertDatabaseHas('finance_private_files', ['id_finance_file' => $task, 'uploaded_by' => $admin->id_user]);
            $this->asRole('Admin Konstruksi');
            $this->get('/api/finance/files/'.$task)->assertNotFound();
            $this->asRole('Admin Operation');
            $this->get('/api/finance/files/'.$task)->assertOk();
        }
    }

    public function test_package_rejects_reversed_or_late_issue_dates_without_changing_revision_or_ledger(): void
    {
        [$p, $d, $v] = $this->package();
        $revision = PrototypeWorkspace::first()->revision;
        $balances = DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson();
        foreach (['2026-10-31', '2026-11-03'] as $badDate) {
            $this->api('saveDeal', [array_replace($d, ['date' => $badDate, 'documentDatePolicy' => 'forged']), 'save'])->assertUnprocessable();
            $this->api('saveSpk', [array_replace($v, ['date' => $badDate, 'documentDatePolicy' => 'forged']), 'save'])->assertUnprocessable();
            $this->assertSame($revision, PrototypeWorkspace::first()->revision);
            $this->assertSame($balances, DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson());
        }
        // Equal dates are valid: the one-day spacing is guidance, not an extra gate.
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $this->assertDatabaseCount('approval_decisions', 0);
    }

    public function test_pending_unmarked_historical_package_can_finish_without_rewriting_its_saved_dates(): void
    {
        [$p, $d, $v] = $this->package();
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        foreach (array_slice(PrototypeDomain::APPROVERS_V3, 0, 4) as $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'QA'])->assertOk();
        }
        $this->asRole('Head of PMO');
        $state = $this->state();
        foreach ($state['projects'] as &$project) {
            if ($project['id'] === $p['id']) {
                unset($project['documentDatePolicy'], $project['issueDate'], $project['workStartDate']);
            }
        }
        unset($project);
        foreach (['deals', 'spks'] as $collection) {
            foreach ($state[$collection] as &$document) {
                unset($document['documentDatePolicy']);
                $document['date'] = $collection === 'deals' ? '2026-11-06' : '2026-11-09';
                $hash = hash('sha256', json_encode(Arr::sortRecursive($document), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
                foreach ($state['projects'] as &$project) {
                    if ($project['id'] === $p['id']) {
                        $project['contractBundle']['submissions'][0]['documents'][$collection][$document['id']] = $hash;
                    }
                }
                unset($project);
            }
            unset($document);
        }
        // A synthetic pre-update frozen package; no persisted/live workspace is changed here.
        $result = app(PrototypeDomain::class)->execute($state, 'decideProject', [$p['id'], 'Setujui', 'Historical QA'])['state'];
        $this->assertSame('Sah', collect($result['projects'])->firstWhere('id', $p['id'])['status']);
        $this->assertSame('2026-11-06', $result['deals'][0]['date']);
        $this->assertSame('2026-11-09', $result['spks'][0]['date']);
        $this->assertArrayNotHasKey('documentDatePolicy', $result['spks'][0]);
    }

    public function test_pp_bakh_spk_one_frozen_package_only_commits_after_five_real_approvals(): void
    {
        [$p,$d,$v] = $this->package();
        $this->api('saveSpk', [$v, 'attest'])->assertUnprocessable();
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $this->api('saveDeal', [$d, 'save'])->assertUnprocessable();
        $this->api('saveSpk', [$v, 'save'])->assertUnprocessable();
        $this->postJson('/api/finance/spk/'.$v['id'].'/payee', ['data' => ['bank' => 'Changed'], 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->api('createTerm', [$v['id']])->assertUnprocessable();
        foreach (PrototypeDomain::APPROVERS_V3 as $i => $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'QA package'])->assertOk();
            $s = $this->state();
            $spk = collect($s['spks'])->firstWhere('id', $v['id']);
            $this->assertSame($i === 4 ? 'Sah' : 'Draft', $spk['status']);
            $this->assertSame($v['date'], $spk['date']);
        }
        $this->assertDatabaseHas('work_orders', ['source_id' => $v['id'], 'status' => 'Sah']);
        $this->assertSame('Sah', json_decode(DB::table('contracts')->where('source_id', $d['id'])->value('document_data'), true)['status']);
        $this->asRole('Admin Konstruksi');
        $this->api('createTerm', [$v['id']])->assertOk();
    }

    public function test_rejected_package_can_be_corrected_then_submitted_from_pm_without_erasing_decisions(): void
    {
        [$p, $d, $v] = $this->package();
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        foreach (array_slice(PrototypeDomain::APPROVERS_V3, 0, 2) as $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'QA'])->assertOk();
        }
        $this->asRole('Mgr Project Support');
        $this->api('decideProject', [$p['id'], 'Tolak', 'Koreksi alamat Mandor'])->assertOk();
        $this->asRole('Admin Konstruksi');
        $p = collect($this->state()['projects'])->firstWhere('id', $p['id']);
        $this->assertCount(3, $p['approvalHistory']);
        $v['address'] = 'Alamat Mandor diperbaiki';
        $v['approvedWithProject'] = ['ppId' => 'FORGED'];
        $this->api('saveSpk', [$v, 'save'])->assertOk();
        $this->assertArrayNotHasKey('approvedWithProject', collect($this->state()['spks'])->firstWhere('id', $v['id']));
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $new = collect($this->state()['projects'])->firstWhere('id', $p['id']);
        $this->assertSame('PM Konstruksi', PrototypeDomain::nextApprovalRole($new, true));
        $this->assertSame($p['approvalHistory'], $new['approvalHistory']);
        $this->assertCount(2, $new['contractBundle']['submissions']);
        $this->assertNotSame($new['contractBundle']['submissions'][0]['documents']['spks'], $new['contractBundle']['submissions'][1]['documents']['spks']);
        $this->assertDatabaseCount('approval_runs', 2);
    }

    public function test_new_order_check_keeps_old_orders_and_rejects_wrong_roles_versions_and_steps(): void
    {
        $migration = require database_path('migrations/2026_11_15_000001_add_finance_settlement_approval.php');
        $condition = $migration->checkCondition();
        foreach (['PM_KONSTRUKSI', 'ADMIN_AREA', 'MGR_PROJECT_SUPPORT', 'MGR_DEPLOYMENT', 'HEAD_OF_PMO', 'MGR_FINANCE_SETTLEMENT', 'FINANCE'] as $index => $role) {
            $ok = DB::selectOne('SELECT CASE WHEN '.$condition.' THEN 1 ELSE 0 END AS allowed FROM (SELECT ? AS approval_order_version, ? AS workflow_version, ? AS step_no, ? AS expected_role)', [3, 3, $index + 1, $role])->allowed;
            $this->assertEquals(1, $ok);
        }
        foreach ([[2, 3, 3, 'MGR_PROJECT_SUPPORT'], [1, 3, 3, 'MGR_DEPLOYMENT']] as $old) {
            $this->assertEquals(1, DB::selectOne('SELECT CASE WHEN '.$condition.' THEN 1 ELSE 0 END AS allowed FROM (SELECT ? AS approval_order_version, ? AS workflow_version, ? AS step_no, ? AS expected_role)', $old)->allowed);
        }
        foreach ([[3, 3, 8, 'FINANCE'], [3, 2, 1, 'PM_KONSTRUKSI'], [3, 3, 3, 'MGR_DEPLOYMENT'], [3, 3, 6, 'MGR_BUSINESS_SUPPORT']] as $invalid) {
            $this->assertEquals(0, DB::selectOne('SELECT CASE WHEN '.$condition.' THEN 1 ELSE 0 END AS allowed FROM (SELECT ? AS approval_order_version, ? AS workflow_version, ? AS step_no, ? AS expected_role)', $invalid)->allowed);
        }
        $this->assertDatabaseHas('roles', ['code' => 'MGR_FINANCE_SETTLEMENT', 'name' => 'Mgr Finance & Settlement']);
        $before = DB::table('roles')->where('code', '!=', 'MGR_FINANCE_SETTLEMENT')->get()->toJson();
        $migration->down();
        $migration->up();
        $this->assertSame($before, DB::table('roles')->where('code', '!=', 'MGR_FINANCE_SETTLEMENT')->get()->toJson());
    }
}
