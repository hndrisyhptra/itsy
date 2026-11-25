<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\AuthenticatedWorkspace;
use App\Services\FinancePaymentCatalog;
use App\Services\KasbonSettlement;
use App\Services\NegotiationProjectCatalog;
use App\Services\OperationProjectRevision;
use App\Services\ProjectCancellation;
use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
use App\Services\RelationalWorkflowStore;
use App\Services\TelegramPendingApprovals;
use App\Services\UploadQueue;
use App\Services\WorkUnitAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
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

    public function test_returned_bapp_guard_is_loaded_only_and_keeps_legacy_paid_and_current_kasbon_locks(): void
    {
        $this->withoutVite();
        $t = ['status' => 'Dikembalikan', 'workflowVersion' => 3, 'paymentFlow' => 'kasbon-settlement-v2', 'paid' => 0, 'approvals' => [], 'kasbon' => ['id' => 'current'], 'history' => [['decision' => 'Kembalikan', 'kasbonId' => 'current']]];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertTrue(PrototypeDomain::bappStatusAllowed($t));
        foreach ([['paid' => 1], ['apmStatus' => 'Valid'], ['history' => [['decision' => 'Kembalikan', 'kasbonId' => 'old']]], ['approvals' => [['role' => 'Finance']]], ['paymentFlow' => 'kasbon-v1'], ['workflowVersion' => 2]] as $invalid) {
            $this->assertFalse(PrototypeDomain::bappStatusAllowed(array_replace($t, $invalid)));
        }
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public static function cancellablePackages(): array
    {
        $cases = [];
        foreach ([false, true] as $operation) {
            foreach (['Draft', 'Dikembalikan', 'Ditolak', 'Diajukan', 'Sah'] as $status) {
                $cases[($operation ? 'Operation' : 'Construction').'-'.$status] = [$operation, $status, false];
            }
            $cases[($operation ? 'Operation' : 'Construction').'-Superadmin'] = [$operation, 'Diajukan', true];
        }

        return $cases;
    }

    #[DataProvider('cancellablePackages')]
    public function test_cancel_package_is_terminal_preserves_history_children_money_and_ledger(bool $operation, string $status, bool $superadmin): void
    {
        [$p, $d, $v] = $this->package($operation);
        $roles = $operation ? WorkUnitAccess::OPS_APPROVERS : PrototypeDomain::APPROVERS_V3;
        if ($status !== 'Draft') {
            $this->api('submitProjectBundle', [$p['id']])->assertOk();
            $this->asRole($roles[0]);
            $this->api('decideProject', [$p['id'], $status === 'Dikembalikan' ? 'Kembalikan' : ($status === 'Ditolak' ? 'Tolak' : 'Setujui'), 'QA history'])->assertOk();
            if ($status === 'Sah') {
                foreach (array_slice($roles, 1) as $role) {
                    $this->asRole($role);
                    $this->api('decideProject', [$p['id'], 'Setujui', 'QA history'])->assertOk();
                }
            }
        }
        $this->asRole($superadmin ? 'Superadmin' : ($operation ? 'Officer 1 Operation' : 'Admin Area'));
        $before = $this->state();
        $pp = collect($before['projects'])->firstWhere('id', $p['id']);
        $expected = ProjectCancellation::plan($before, $pp)['expected'];
        $snapshot = fn ($table, $key) => DB::table($table)->orderBy($key)->get()->toJson();
        $decisions = $snapshot('approval_decisions', 'id_approval_decision');
        $steps = $snapshot('approval_steps', 'id_approval_step');
        $ledger = $snapshot('volume_balances', 'boq_component_id');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $requestId = (string) Str::uuid();
        $body = ['command' => 'cancelProject', 'args' => [$p['id'], $expected, 'Pekerjaan dibatalkan QA'], 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => $requestId];
        $this->postJson('/api/prototype/commands', $body)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(80, $count);
        fwrite(STDERR, "\nCancel ".($operation ? 'Operation' : 'Construction')." {$status}: {$count} HTTP queries.\n");
        $after = $this->state();
        $cancelled = collect($after['projects'])->firstWhere('id', $p['id']);
        $this->assertSame('Cancel', $cancelled['status']);
        $this->assertSame($status, $cancelled['cancellation']['previousStatus']);
        $this->assertSame($pp['approvals'], $cancelled['approvals']);
        $this->assertSame($pp['approvalHistory'], $cancelled['approvalHistory']);
        $this->assertSame($before['deals'], $after['deals']);
        $this->assertSame($before['spks'], $after['spks']);
        $this->assertSame($pp['budget'], $cancelled['budget']);
        $this->assertSame($decisions, $snapshot('approval_decisions', 'id_approval_decision'));
        $this->assertSame($steps, $snapshot('approval_steps', 'id_approval_step'));
        $this->assertSame($ledger, $snapshot('volume_balances', 'boq_component_id'));
        $this->assertDatabaseHas('project_plans', ['source_id' => $p['id'], 'status' => 'CANCELLED']);
        if ($status === 'Diajukan') {
            $this->assertDatabaseHas('approval_runs', ['status' => 'CANCELLED']);
        }
        $targets = [];
        app(TelegramPendingApprovals::class)->each(function ($target) use (&$targets) {
            $targets[] = $target;
        });
        $this->assertFalse(collect($targets)->contains('target_id', $p['id']));
        $revision = PrototypeWorkspace::first()->revision;
        $this->postJson('/api/prototype/commands', $body)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $body['args'][2] = 'Changed UUID payload';
        $this->postJson('/api/prototype/commands', $body)->assertConflict();
        $this->api('cancelProject', [$p['id'], $expected, 'Again'])->assertUnprocessable();
        $this->api('decideProject', [$p['id'], 'Setujui', ''])->assertUnprocessable();
        $this->api('resetProjectApproval', [$p['id'], [], 'Reset'])->assertUnprocessable();
        $this->asRole($operation ? 'Admin Operation' : 'Admin Konstruksi');
        $this->api('saveProjectBundle', [$cancelled, false])->assertUnprocessable();
        $this->api('submitProjectBundle', [$p['id']])->assertUnprocessable();
        $this->api('saveDeal', [$d, 'save'])->assertUnprocessable();
        $this->api('saveSpk', [$v, 'save'])->assertUnprocessable();
        $this->api('createTerm', [$v['id']])->assertUnprocessable();
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        // Full projection cannot revive a pending run or overwrite prior decisions.
        app(RelationalWorkflowStore::class)->write(PrototypeWorkspace::first(), $after);
        $this->assertSame($decisions, $snapshot('approval_decisions', 'id_approval_decision'));
    }

    public function test_header_only_zero_budget_draft_can_cancel_without_fabricating_boq_or_approval(): void
    {
        $this->withoutVite();
        $this->seedWorkflow('B');
        $this->asRole('Admin Konstruksi');
        $p = app(PrototypeWorkspaceService::class)->scenario('B')['projects'][0];
        $p['id'] = $p['rootId'] = 'header-only-cancel-qa';
        $p['unit'] = 'Konstruksi';
        $p['branch'] = 'PEKALONGAN';
        $p['boq'] = $p['schedule'] = $p['approverUserIds'] = [];
        $p['budget'] = 0;
        unset($p['planBudget'], $p['riskLevel'], $p['signatureRows']);
        $this->api('saveProjectBundle', [$p, false])->assertOk();
        $this->asRole('Admin Area');
        $s = $this->state();
        $p = collect($s['projects'])->firstWhere('id', $p['id']);
        $this->api('cancelProject', [$p['id'], ProjectCancellation::plan($s, $p)['expected'], 'Cancel header-only draft'])->assertOk();
        $this->assertDatabaseHas('project_plans', ['source_id' => $p['id'], 'status' => 'CANCELLED', 'budget' => 0]);
        $saved = collect($this->state()['projects'])->firstWhere('id', $p['id']);
        $this->assertSame([], $saved['boq']);
        $this->assertSame([], $saved['approvals']);
        $this->assertSame([], $saved['approvalHistory']);
    }

    public function test_existing_work_document_blocks_http_cancellation_without_mutating_paid_history(): void
    {
        $this->withoutVite();
        $this->seedWorkflow('E');
        $this->asRole('Admin Area');
        $state = $this->state();
        $term = $state['terms'][0];
        $spk = collect($state['spks'])->firstWhere('id', $term['spkId']);
        $deal = collect($state['deals'])->firstWhere('id', $spk['dealId']);
        $pp = collect($state['projects'])->firstWhere('id', $deal['projectId']);
        $revision = PrototypeWorkspace::first()->revision;
        $this->api('cancelProject', [$pp['id'], ProjectCancellation::plan($state, $pp)['expected'], 'Not allowed'])->assertUnprocessable()->assertJsonPath('message', 'Paket sudah memiliki BA RP/pengajuan pembayaran dan tidak dapat di-Cancel.');
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertSame($state, $this->state());
    }

    public function test_cancel_keeps_role_scope_stale_request_pending_upload_and_work_document_guards(): void
    {
        [$p, $d, $v] = $this->package();
        foreach (['Admin Konstruksi', 'PM Konstruksi', 'Mgr Project Support', 'Finance', 'Officer 1 Operation'] as $role) {
            $this->asRole($role);
            $this->api('cancelProject', [$p['id'], [], 'Unauthorized'])->assertForbidden();
        }
        $this->asRole('Admin Area');
        $state = $this->state();
        $pp = collect($state['projects'])->firstWhere('id', $p['id']);
        $plan = ProjectCancellation::plan($state, $pp);
        $revision = PrototypeWorkspace::first()->revision;
        $this->api('cancelProject', [$p['id'], $plan['expected'], ''])->assertUnprocessable();
        $bad = $plan['expected'];
        $bad['status'] = 'Sah';
        $this->api('cancelProject', [$p['id'], $bad, 'Stale'])->assertConflict();
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $work = $state;
        $work['terms'][] = ['id' => 'exact-work', 'spkId' => $v['id']];
        $this->assertNotSame('', ProjectCancellation::plan($work, $pp)['reason']);
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        // A genuinely pending private upload, not a fake paid status, blocks cancellation.
        $this->asRole('Admin Konstruksi');
        $upload = $this->post('/api/finance/uploads/mandor-ktp/'.$v['id'], ['file' => UploadedFile::fake()->image('qa-ktp.png'), 'request_id' => (string) Str::uuid(), 'revision' => PrototypeWorkspace::first()->revision], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
        $this->asRole('Admin Area');
        $this->api('cancelProject', [$p['id'], $plan['expected'], 'Wait uploads'])->assertUnprocessable();
        $this->assertDatabaseHas('upload_tasks', ['id_upload_task' => $upload, 'status' => 'queued']);
    }

    public static function returnedWorkUnits(): array
    {
        return ['construction' => [false], 'operation' => [true]];
    }

    #[DataProvider('returnedWorkUnits')]
    public function test_finance_return_repairs_same_bapp_and_reissues_kasbon_from_first_unit_approver(bool $operation): void
    {
        [$p, $d, $v] = $this->package($operation);
        $roles = $operation ? WorkUnitAccess::OPS_APPROVERS : PrototypeDomain::APPROVERS_V3;
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        foreach ($roles as $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'QA package'])->assertOk();
        }
        $admin = $operation ? 'Admin Operation' : 'Admin Konstruksi';
        $this->asRole($admin);
        $this->api('createTerm', [$v['id']])->assertOk();
        $t = $this->term();
        $t['actuals'] = ['bundle-row' => ['m' => 0, 's' => 1]];
        $this->api('attestBarp', [$t])->assertOk();
        $this->api('prepareBapp', [$t['id']])->assertOk();
        $b = $this->state()['bapps'][0];
        $this->api('attestBapp', [$b['id'], $b['no']])->assertOk();
        $this->submitKasbon($this->term())->assertOk();
        foreach ([...$roles, 'Mgr Finance & Settlement'] as $role) {
            $this->asRole($role);
            $this->api('decide', [$t['id'], 'Setujui', 'QA payment'])->assertOk();
        }
        $this->asRole('Finance');
        $this->api('decide', [$t['id'], 'Kembalikan', 'Perbaiki tanggal rekonsiliasi'])->assertOk();
        $returned = $this->term();
        $this->assertSame('Dikembalikan', $returned['status']);
        $this->assertCount(7, $returned['history']);
        $decisions = DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->toJson();
        $balances = DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson();
        $this->asRole($admin);
        // Always change the source date, including when the suite runs on 25 November.
        $returned['barp']['date'] = Carbon::parse($returned['barp']['date'], 'UTC')->addDay()->toDateString();
        $this->api('saveTerm', [$returned, false])->assertOk();
        $this->assertSame('Draft', $this->state()['bapps'][0]['status']);
        $this->assertFalse(PrototypeDomain::barpCurrent($this->term()));
        $revision = PrototypeWorkspace::first()->revision;
        $this->api('attestBapp', [$b['id'], $b['no']])->assertUnprocessable();
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->api('attestBarp', [$this->term()])->assertOk();
        $this->assertSame('Dikembalikan', $this->term()['status']);
        $this->submitKasbon($this->term())->assertUnprocessable();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->api('prepareBapp', [$t['id']])->assertOk();
        $prepareQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->api('attestBapp', [$b['id'], $b['no'], '', '2026-11-26'])->assertOk();
        $attestQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        fwrite(STDERR, "\nReturned BAPP ".($operation ? 'Operation' : 'Construction').": prepare {$prepareQueries}, attest {$attestQueries} HTTP queries.\n");
        $this->assertLessThanOrEqual(80, $prepareQueries);
        $this->assertLessThanOrEqual(80, $attestQueries);
        $this->assertDatabaseCount('ba_completions', 1);
        $current = $this->state()['bapps'][0];
        $this->assertSame($b['id'], $current['id']);
        $this->assertCount(1, $current['attestationHistory']);
        $this->assertTrue(PrototypeDomain::bappCurrent($current, $this->term()));
        $this->assertSame($returned['history'], $this->term()['history']);
        $this->assertSame($returned['kasbon']['id'], $this->term()['kasbon']['id']);
        $this->assertSame($decisions, DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->toJson());
        $this->assertSame($balances, DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson());
        $this->submitKasbon($this->term())->assertOk();
        $new = $this->term();
        $this->assertNotSame($returned['kasbon']['id'], $new['kasbon']['id']);
        $this->assertSame('Diajukan', $new['status']);
        $this->assertCount(0, $new['approvals']);
        $this->assertSame($roles[0], PrototypeDomain::nextApprovalRole($new));
        $this->assertSame($returned['history'], $new['history']);
        $this->assertSame(0, $new['paid']);
        $this->assertSame($decisions, DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->toJson());
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

    private function scanUpload(string $id, ?string $uuid = null)
    {
        return $this->post('/api/prototype/payments/'.$id.'/scan', ['revision' => PrototypeWorkspace::first()->revision, 'request_id' => $uuid ?? (string) Str::uuid(),
            'file' => UploadedFile::fake()->createWithContent('finance-scan.pdf', "%PDF-1.4\nsynthetic scan QA\n%%EOF")], ['Accept' => 'application/json']);
    }

    private function scanAtFinanceReview(string $id): void
    {
        $before = $this->term();
        $this->assertTrue(PrototypeDomain::scanReviewReady($before));
        foreach (['Admin Konstruksi', 'Admin Operation', 'Superadmin', 'Mgr Finance & Settlement'] as $role) {
            $this->asRole($role);
            $this->scanUpload($id)->assertForbidden();
        }
        $this->asRole('Finance');
        $decisionRows = DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->toJson();
        foreach (['kasbon', 'user', 'order'] as $wrong) {
            $bad = $before;
            if ($wrong === 'kasbon') {
                $bad['approvals'][0]['kasbonId'] = 'old-version';
            }
            if ($wrong === 'user') {
                $bad['approvals'][0]['userId'] = 'wrong-user';
            }
            if ($wrong === 'order') {
                $bad['approvals'] = array_reverse($bad['approvals']);
            }
            $this->assertFalse(PrototypeDomain::scanReviewReady($bad));
        }
        $uuid = (string) Str::uuid();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $saved = $this->scanUpload($id, $uuid)->assertOk()->json('state.terms.0');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(75, count($queries));
        fwrite(STDERR, "\nFinance scan HTTP ".($before['approvalScheme'] ?? 'Construction').': '.count($queries)." queries.\n");
        $this->assertSame('db-user-'.$this->accounts['Finance']->getKey(), $saved['scan']['uploadedBy']);
        $this->assertSame('Menunggu Verifikasi', $saved['scan']['status']);
        $this->scanUpload($id, $uuid)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('state.terms.0.scan.id', $saved['scan']['id']);
        $this->assertCount(1, Storage::disk('local')->files('prototype-scans/'.PrototypeWorkspace::first()->id.'/'.$id));
        $this->assertSame($before['approvals'], $saved['approvals']);
        $this->assertSame($before['financePayment'], $saved['financePayment']);
        $this->assertSame('Diajukan', $saved['status']);
        $this->getJson('/api/finance/payments')->assertOk()->assertJsonPath('items.0.documents', 'OK');
        $this->api('decide', [$id, 'Setujui', ''])->assertUnprocessable();
        $this->api('verifyTermScan', [$id, 'Perlu Koreksi', ''])->assertUnprocessable();
        $this->api('verifyTermScan', [$id, 'Perlu Koreksi', 'Perbaiki halaman'])->assertOk();
        $this->scanUpload($id)->assertOk()->assertJsonCount(1, 'state.terms.0.scanHistory');
        $verified = $this->api('verifyTermScan', [$id, 'Valid', 'Berkas lengkap'])->assertOk()->json('state.terms.0');
        $this->assertSame($before['approvals'], $verified['approvals']);
        $this->assertSame($before['financePayment'], $verified['financePayment']);
        $this->assertArrayNotHasKey('financeApproval', $verified);
        $this->assertSame($before['financeHistory'] ?? [], $verified['financeHistory'] ?? []);
        $this->assertSame($before['paid'], $verified['paid']);
        $this->assertSame($before['kasbon'], $verified['kasbon']);
        $this->assertSame($decisionRows, DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->toJson());
        $this->assertDatabaseCount('apm_settlements', 0);
        $this->scanUpload($id)->assertUnprocessable();
        $file = DB::table('document_files')->where('original_name', 'finance-scan.pdf')->first();
        $this->assertEquals($this->accounts['Finance']->getKey(), $file->uploaded_by);
    }

    #[DataProvider('paymentTypes')]
    public function test_finance_uploads_scan_at_its_turn_without_approving_or_paying_automatically(bool $final): void
    {
        $t = $this->draftPayment();
        if ($final) {
            $t['type'] = 'Final';
            $this->api('saveTerm', [$t, false])->assertOk();
            $t = $this->term();
        }
        $this->attestCurrentWorkBapp($t);
        $this->submitKasbon($this->term())->assertOk();
        $this->asRole('Finance');
        $this->scanUpload($t['id'])->assertUnprocessable();
        foreach ([...PrototypeDomain::APPROVERS_V3, 'Mgr Finance & Settlement'] as $role) {
            $this->asRole($role);
            $this->api('decide', [$t['id'], 'Setujui', ''])->assertOk();
        }
        $this->scanAtFinanceReview($t['id']);
        $this->api('decide', [$t['id'], 'Setujui', ''])->assertOk()->assertJsonCount(7, 'state.terms.0.approvals')->assertJsonPath('state.terms.0.financePayment.status', 'Diverifikasi');
        $this->assertDatabaseCount('apm_settlements', 0);
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
        $split = $this->kasbon($t);
        $source = $this->state();
        $order = collect($source['spks'])->firstWhere('id', $t['spkId']);
        $quote = KasbonSettlement::quote(collect($source['deals'])->firstWhere('id', $order['dealId']), $t);
        $split['rows'] = [];
        foreach (KasbonSettlement::components($quote) as $component => $amount) {
            $split['rows'][] = ['component' => $component, 'accountCode' => 'QA-'.$component, 'accountName' => 'QA '.$component, 'text' => 'Keperluan '.$component, 'amount' => $amount];
        }
        $this->submitKasbon($t, $split)->assertOk();
        $this->getJson('/api/finance/terms/'.$t['id'].'/kasbon?documents_only=1')->assertOk()->assertJsonPath('documents.0.data.rows.0.component', 'service')->assertJsonPath('documents.0.data.rows.1.component', 'material');
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
        $budget['materials'][0]['qty'] = 1;
        $budget['materials'][0]['price'] = 25.03; // Same amount with a whole input quantity.
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

    private function operationRevisionFixture(bool $legacy): array
    {
        [$p, $d, $v] = $this->package(! $legacy);
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $roles = $legacy ? PrototypeDomain::APPROVERS_V3 : WorkUnitAccess::OPS_APPROVERS;
        $this->asRole($roles[0]);
        $this->api('decideProject', [$p['id'], 'Setujui', 'Keputusan asli'])->assertOk();
        $this->asRole($roles[1]);
        $this->api('decideProject', [$p['id'], 'Kembalikan', 'Perbaiki uraian Operation'])->assertOk();
        if ($legacy) {
            // Synthetic pre-Operation saved PP only; production never rewrites a unit/approval via SQL.
            $w = PrototypeWorkspace::first();
            $store = app(RelationalWorkflowStore::class);
            $before = $store->read($w);
            $after = $before;
            foreach ($after['projects'] as &$row) {
                if ($row['id'] === $p['id']) {
                    $row['unit'] = 'Operation';
                }
            }
            unset($row);
            DB::transaction(fn () => $store->write($w, $after, $before));
            $region = DB::table('branches')->where('name', $p['branch'])->value('region_id');
            foreach (['Manager SS', 'General Manager Operation'] as $role) {
                DB::table('users')->where('id_user', $this->accounts[$role]->id_user)->update(['access_scope' => 'REGION', 'id_regions' => $region]);
            }
        }
        $this->asRole('Admin Operation');
        $p = collect($this->state()['projects'])->firstWhere('id', $p['id']);

        return [$p, $d, $v];
    }

    public static function operationRevisionModes(): array
    {
        return ['marked-operation' => [false], 'legacy-construction-cycle' => [true]];
    }

    #[DataProvider('operationRevisionModes')]
    public function test_returned_operation_revision_keeps_old_sql_cycle_and_resubmits_to_operation(bool $legacy): void
    {
        [$p, $d, $v] = $this->operationRevisionFixture($legacy);
        $oldRuns = DB::table('approval_runs')->get()->toJson();
        $oldSteps = DB::table('approval_steps')->get()->toJson();
        $oldDecisions = DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->toJson();
        $archive = PrototypeWorkspace::first()->getRawOriginal('state');
        $history = $p['approvalHistory'];
        $p['approverUserIds'] = [];
        foreach (WorkUnitAccess::OPS_APPROVERS as $role) {
            $p['approverUserIds'][$role] = 'db-user-'.$this->accounts[$role]->id_user;
        }
        $p['approvalScheme'] = 'business-support'; // Server owns routing and provenance, not the client.
        $p['operationApprovalRevision'] = ['firstCycleNo' => 999, 'before' => ['approvalScheme' => 'operation-v1']];
        $p['name'] = 'Uraian Operation setelah revisi';
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->api('saveProjectBundle', [$p, false])->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(110, count($queries));
        fwrite(STDERR, "\nOperation revised PP ".($legacy ? 'legacy' : 'marked').': '.count($queries)." HTTP queries.\n");
        $saved = collect($response->json('state.projects'))->firstWhere('id', $p['id']);
        $this->assertSame('operation-v1', $saved['approvalScheme']);
        $this->assertSame($history, $saved['approvalHistory']);
        $this->assertSame([], $saved['approvals']);
        $this->assertSame(WorkUnitAccess::OPS_APPROVERS, array_keys($saved['approverUserIds']));
        $this->assertSame($oldRuns, DB::table('approval_runs')->get()->toJson());
        $this->assertSame($oldSteps, DB::table('approval_steps')->get()->toJson());
        $this->assertSame($oldDecisions, DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->toJson());
        if ($legacy) {
            $this->assertSame(2, $saved['operationApprovalRevision']['firstCycleNo']);
            $this->assertSame('project-support-first', $saved['operationApprovalRevision']['before']['approvalScheme']);
            $again = $saved;
            $again['operationApprovalRevision']['firstCycleNo'] = 999;
            $againResponse = $this->api('saveProjectBundle', [$again, false])->assertOk();
            $this->assertSame($saved['operationApprovalRevision'], collect($againResponse->json('state.projects'))->firstWhere('id', $p['id'])['operationApprovalRevision']);
            $w = PrototypeWorkspace::first();
            $store = app(RelationalWorkflowStore::class);
            $full = $store->read($w);
            DB::transaction(fn () => $store->write($w, $full));
            $this->assertSame($oldRuns, DB::table('approval_runs')->get()->toJson());
            $this->assertSame($oldSteps, DB::table('approval_steps')->get()->toJson());
            $this->assertSame($oldDecisions, DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->toJson());
        } else {
            $this->assertArrayNotHasKey('operationApprovalRevision', $saved);
        }
        $revision = PrototypeWorkspace::first()->revision;
        $bad = $saved;
        $bad['unit'] = 'Konstruksi';
        $this->api('saveProjectBundle', [$bad, false])->assertForbidden();
        $bad = $saved;
        $bad['approverUserIds']['Officer 1 Operation'] = 'db-user-'.$this->accounts['PM Konstruksi']->id_user;
        $this->api('saveProjectBundle', [$bad, false])->assertUnprocessable();
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $latest = DB::table('approval_runs')->orderByDesc('cycle_no')->first();
        $this->assertSame(WorkUnitAccess::OPS_APPROVERS, array_map(fn ($code) => AccountAccess::ROLES[$code], DB::table('approval_steps')->where('run_id', $latest->id_approval_run)->orderBy('step_no')->pluck('expected_role')->all()));
        $this->assertSame([4], DB::table('approval_steps')->where('run_id', $latest->id_approval_run)->distinct()->pluck('approval_order_version')->all());
        $new = collect($this->state()['projects'])->firstWhere('id', $p['id']);
        $this->assertSame('Officer 1 Operation', PrototypeDomain::nextApprovalRole($new, true));
        $this->assertSame($history, $new['approvalHistory']);
        $this->asRole('PM Konstruksi');
        $this->api('decideProject', [$p['id'], 'Setujui', 'Bukan giliran'])->assertForbidden();
        foreach (WorkUnitAccess::OPS_APPROVERS as $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', 'Revisi Operation'])->assertOk();
        }
        $this->asRole('Admin Operation');
        $final = collect($this->state()['projects'])->firstWhere('id', $p['id']);
        $this->assertSame('Sah', $final['status']);
        $this->assertSame($history, array_slice($final['approvalHistory'], 0, count($history)));
        $this->assertSame($oldDecisions, DB::table('approval_decisions')->whereIn('id_approval_decision', collect(json_decode($oldDecisions, true))->pluck('id_approval_decision'))->orderBy('id_approval_decision')->get()->toJson());
        $this->assertSame($archive, PrototypeWorkspace::first()->getRawOriginal('state'));
        $this->assertDatabaseHas('contracts', ['source_id' => $d['id'], 'signer_name' => $this->accounts['General Manager Operation']->name]);
        $this->api('createSettlementTerm', [$v['id']])->assertOk();
        $term = collect($this->state()['terms'])->firstWhere('spkId', $v['id']);
        $this->assertSame([...WorkUnitAccess::OPS_APPROVERS, 'Mgr Finance & Settlement', 'Finance'], PrototypeDomain::approvalRoles($term));
    }

    public function test_legacy_operation_revision_with_downstream_payment_is_blocked_without_sql_or_history_mutation(): void
    {
        [$p, $d, $v] = $this->operationRevisionFixture(true);
        $s = $this->state();
        $s['terms'][] = ['id' => 'paid-history-qa', 'spkId' => $v['id'], 'paid' => 100];
        $before = $s;
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(OperationProjectRevision::class)->marker($p, 'operation-v1', $s['actorUserId'], $s);
            $this->fail('A historical payment must prevent routing conversion.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('proses lanjutan', $error->getMessage());
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, $queries);
        $this->assertSame($before, $s);
    }

    public function test_pending_legacy_operation_is_not_converted_by_get_or_a_locked_save(): void
    {
        [$p] = $this->package();
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $w = PrototypeWorkspace::first();
        $store = app(RelationalWorkflowStore::class);
        $before = $store->read($w);
        $after = $before;
        foreach ($after['projects'] as &$row) {
            if ($row['id'] === $p['id']) {
                $row['unit'] = 'Operation';
            }
        }
        unset($row);
        DB::transaction(fn () => $store->write($w, $after, $before));
        $original = DB::table('project_plans')->where('source_id', $p['id'])->value('document_data');
        $this->asRole('Admin Operation');
        $saved = collect($this->state()['projects'])->firstWhere('id', $p['id']);
        $this->assertSame('project-support-first', $saved['approvalScheme']);
        $this->assertArrayNotHasKey('operationApprovalRevision', $saved);
        $this->api('saveProjectBundle', [$saved, false])->assertUnprocessable();
        $this->assertSame($original, DB::table('project_plans')->where('source_id', $p['id'])->value('document_data'));
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
            if ($role === 'Finance') {
                $this->scanAtFinanceReview($t['id']);
            }
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

    public function test_pending_unmarked_historical_package_can_finish_without_rewriting_its_saved_dates_or_fractional_volume(): void
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
                if ($collection === 'deals') {
                    $document['rows'][0]['qs'] = 1.5; // Simulated pre-update frozen source, not a new submission.
                }
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
        $this->assertEquals(1.5, $result['deals'][0]['rows'][0]['qs']);
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
