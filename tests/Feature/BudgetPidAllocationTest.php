<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\BudgetPidAllocation;
use App\Services\BudgetPidMaster;
use App\Services\BudgetPidWorkbook;
use App\Services\KasbonSettlement;
use App\Services\ProjectCancellation;
use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
use App\Services\RelationalWorkflowStore;
use App\Services\UploadQueue;
use App\Services\WorkUnitAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class BudgetPidAllocationTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->seedWorkflow('B');
        $this->asRole('Admin Konstruksi');
    }

    private function api(string $command, array $args, ?string $request = null, ?int $revision = null)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args,
            'revision' => $revision ?? PrototypeWorkspace::first()->revision, 'request_id' => $request ?? (string) Str::uuid()]);
    }

    private function state(): array
    {
        return $this->getJson('/api/prototype/state')->assertOk()->json('state');
    }

    private function project(string $id): array
    {
        return collect($this->state()['projects'])->firstWhere('id', $id);
    }

    private function allocation(string $id): object
    {
        $pp = app(RelationalWorkflowStore::class)->id(PrototypeWorkspace::first()->id, 'projects', $id);

        return DB::table('budget_pid_allocations')->where('pp_id', $pp)->first();
    }

    private function package(string $pid = 'NEW-BUDGET-PID', bool $operation = false, bool $legacy = false): array
    {
        if ($operation) {
            $region = DB::table('branches')->where('name', 'PEKALONGAN')->value('region_id');
            foreach (['Manager SS', 'General Manager Operation'] as $role) {
                DB::table('users')->where('id_user', $this->accounts[$role]->id_user)->update(['access_scope' => 'REGION', 'id_regions' => $region]);
            }
        }
        $this->asRole($operation ? 'Admin Operation' : 'Admin Konstruksi');
        $s = $this->state();
        $p = app(PrototypeWorkspaceService::class)->scenario('B')['projects'][0];
        $p['id'] = $p['rootId'] = 'pp-'.Str::uuid();
        $p['projectCode'] = $pid;
        $p['date'] = '2026-11-01';
        $p['workStartDate'] = '2026-11-02';
        $p['branch'] = 'PEKALONGAN';
        $p['unit'] = $operation ? 'Operation' : 'Konstruksi';
        $p['riskLevel'] = 'SEDANG';
        $p['approverUserIds'] = [];
        foreach ($operation ? WorkUnitAccess::OPS_APPROVERS : PrototypeDomain::APPROVERS_V3 as $role) {
            $p['approverUserIds'][$role] = 'db-user-'.$this->accounts[$role]->id_user;
        }
        $k = collect($s['khs'])->first(fn ($k) => $k['active'] && ($k['s'] ?? 0) >= 100);
        $m = collect($s['khs'])->first(fn ($k) => $k['active'] && ($k['m'] ?? 0) >= 100);
        $p['boq'] = [['id' => 'budget-row', 'khsId' => $k['id'], 'qty' => 10, 'm' => $k['m'], 's' => $k['s']],
            ['id' => 'material-row', 'khsId' => $m['id'], 'qty' => 10, 'm' => $m['m'], 's' => $m['s']]];
        $budget = json_decode(file_get_contents(base_path('tests/Fixtures/pp-budget-vector.json')), true)['project']['planBudget'];
        $budget['materials'][0]['qty'] = 1;
        $budget['mandor'] = [['rowId' => 'budget-row', 'qtyM' => 0, 'qtyS' => 5, 'priceM' => 0, 'priceS' => 100],
            ['rowId' => 'material-row', 'qtyM' => 0, 'qtyS' => 0, 'priceM' => 0, 'priceS' => 0]];
        $p['planBudget'] = $budget;
        if ($legacy) {
            // Trusted pre-stage3 fixture in the fail-closed memory test database only.
            $w = PrototypeWorkspace::first();
            $before = app(RelationalWorkflowStore::class)->read($w);
            $next = app(PrototypeDomain::class)->execute($s, 'saveProjectBundle', [$p, false])['state'];
            DB::transaction(fn () => app(RelationalWorkflowStore::class)->write($w, $next, $before));
        } else {
            $this->api('saveProjectBundle', [$p, false])->assertOk();
        }
        $p = $this->project($p['id']);
        $d = app(PrototypeWorkspaceService::class)->scenario('C')['deals'][0];
        $d['id'] = 'bakh-'.Str::uuid();
        $d['projectId'] = $p['id'];
        $d['date'] = $d['start'] = $d['end'] = $p['schedule'][0]['start'];
        $d['rows'] = [['rowId' => 'budget-row', 'm' => false, 's' => true, 'qm' => 0, 'qs' => 5, 'pm' => 0, 'ps' => 100, 'sphM' => 0, 'sphS' => 100, 'offerM' => 0, 'offerS' => 100, 'capM' => $k['m'], 'capS' => $k['s']]];
        $this->api('saveDeal', [$d, 'save'])->assertOk();
        $d = collect($this->state()['deals'])->firstWhere('id', $d['id']);
        $v = app(PrototypeWorkspaceService::class)->scenario('D')['spks'][0];
        $v['id'] = 'spk-'.Str::uuid();
        $v['dealId'] = $d['id'];
        $v['date'] = $v['start'] = $v['end'] = $d['start'];
        $this->api('saveSpk', [$v, 'save'])->assertOk();
        $this->completeMandorPayee($v['id']);

        return [$this->project($p['id']), $d, $v];
    }

    private function master(array $p, array $overrides = [], bool $map = true): void
    {
        $this->asRole('Finance');
        $values = ['TOTAL' => $p['planBudgetTotals']['ownerTotal'], 'Material' => $p['planBudgetTotals']['ownerM'], 'Non Material' => $p['planBudgetTotals']['ownerS'], 'Operasional' => $p['planBudgetTotals']['bop'], 'Tenaga Kerja' => 0, 'Depresiasi' => 0];
        $values = array_replace($values, $overrides);
        $items = [];
        foreach (['TOTAL', ...BudgetPidWorkbook::CATEGORIES] as $i => $category) {
            $items[$category] = ['source_title' => $p['projectCode'].'-'.$i, 'source_row' => $i + 2, 'release' => (int) $values[$category], 'commitment' => 0, 'actual' => 0, 'available' => (int) $values[$category]];
        }
        $records = [$p['projectCode'] => ['name' => 'Master '.$p['projectCode'], 'items' => $items]];
        $service = app(BudgetPidMaster::class);
        $hash = hash('sha256', json_encode($records));
        $input = ['unit' => $p['unit'], 'stage' => 'preview'];
        $preview = $service->import((int) auth()->id(), $records, $input, $hash, 'synthetic.xlsx');
        $service->import((int) auth()->id(), $records, ['unit' => $p['unit'], 'stage' => 'commit', 'request_id' => (string) Str::uuid(), 'version' => $preview['version'], 'preview_hash' => $preview['preview_hash']], $hash, 'synthetic.xlsx');
        $master = DB::table('budget_pids')->where('pid', $p['projectCode'])->first();
        if ($map && $master->branch_id === null) {
            $branch = (int) DB::table('branches')->where('name', $p['branch'])->value('id_branch');
            $this->postJson('/api/budgeting-pid/branch', ['ids' => [$master->id_budget_pid], 'unit' => $p['unit'], 'branch_id' => $branch, 'version' => DB::table('budget_pid_controls')->value('version'), 'request_id' => (string) Str::uuid()])->assertOk();
        }
        $this->asRole($p['unit'] === 'Operation' ? 'Admin Operation' : 'Admin Konstruksi');
    }

    private function reference(array $p, bool $own = false)
    {
        $query = ['pid' => $p['projectCode'], 'unit' => $p['unit'], 'branch' => $p['branch']];
        if ($own) {
            $query['pp_id'] = $p['id'];
        }

        return $this->getJson('/api/budgeting-pid/reference?'.http_build_query($query));
    }

    public static function limits(): array
    {
        return array_map(fn ($c) => [$c], ['TOTAL', 'Material', 'Non Material', 'Operasional']);
    }

    #[DataProvider('limits')]
    public function test_each_bound_rejects_formal_submission_atomically_but_allows_draft(string $category): void
    {
        [$p] = $this->package();
        $key = ['TOTAL' => 'ownerTotal', 'Material' => 'ownerM', 'Non Material' => 'ownerS', 'Operasional' => 'bop'][$category];
        $this->master($p, [$category => $p['planBudgetTotals'][$key] - 1]);
        $revision = PrototypeWorkspace::first()->revision;
        $allocation = (array) $this->allocation($p['id']);
        $changes = DB::table('budget_pid_allocation_changes')->count();
        $this->api('submitProjectBundle', [$p['id']])->assertUnprocessable()->assertSee('Budget PID '.$category, false);
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertSame($allocation, (array) $this->allocation($p['id']));
        $this->assertDatabaseCount('budget_pid_allocation_changes', $changes);
        $this->assertSame('Draft', $this->project($p['id'])['status']);
        $p['planBudgetTotals'] = ['ownerM' => 0, 'ownerS' => 0, 'bop' => 0];
        $this->api('saveProjectBundle', [$p, false])->assertOk();
        $this->assertSame('pid-budget-v1', $this->project($p['id'])['budgetPidPolicy']);
        $this->assertSame('draft', $this->allocation($p['id'])->status);
        $this->api('submitProjectBundle', [$p['id']])->assertUnprocessable();
    }

    public function test_exact_limit_two_pps_replay_and_cancel_do_not_double_reserve(): void
    {
        [$p] = $this->package();
        $this->master($p);
        $this->reference($p)->assertOk()->assertJsonPath('application_used.TOTAL', 0);
        $request = (string) Str::uuid();
        $revision = PrototypeWorkspace::first()->revision;
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->api('submitProjectBundle', [$p['id']], $request, $revision)->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertLessThanOrEqual(110, count($queries));
        fwrite(STDERR, "\nBudget PID formal submission: ".count($queries)." HTTP queries.\n");
        $first = (array) $this->allocation($p['id']);
        $this->assertSame((int) $p['planBudgetTotals']['ownerM'], (int) $first['material']);
        $this->reference($p)->assertOk()->assertJsonPath('remaining.TOTAL', 0);
        $this->reference($p, true)->assertOk()->assertJsonPath('remaining.TOTAL', (int) $p['planBudgetTotals']['ownerTotal']);
        $events = DB::table('budget_pid_allocation_changes')->count();
        $this->api('submitProjectBundle', [$p['id']], $request, $revision)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('budget_pid_allocation_changes', $events);
        [$other] = $this->package();
        $this->api('submitProjectBundle', [$other['id']], $request, $revision)->assertConflict();
        $this->api('submitProjectBundle', [$other['id']])->assertUnprocessable();
        $this->asRole('Admin Area');
        $s = $this->state();
        $current = collect($s['projects'])->firstWhere('id', $p['id']);
        $this->api('cancelProject', [$p['id'], ProjectCancellation::plan($s, $current)['expected'], 'Budget dipakai paket pengganti'])->assertOk();
        $this->assertSame('cancelled', $this->allocation($p['id'])->status);
        $this->assertSame(0, (int) $this->allocation($p['id'])->material);
        $this->asRole('Admin Konstruksi');
        $this->api('submitProjectBundle', [$other['id']])->assertOk();
        $this->reference($other)->assertOk()->assertJsonPath('remaining.TOTAL', 0);
    }

    public function test_return_revision_keeps_prior_reservation_until_resubmission_and_excludes_self(): void
    {
        [$p] = $this->package();
        $this->master($p);
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $before = (array) $this->allocation($p['id']);
        $this->asRole('PM Konstruksi');
        $this->api('decideProject', [$p['id'], 'Kembalikan', 'Perbaiki BOP'])->assertOk();
        $this->assertSame($before, (array) $this->allocation($p['id']));
        $this->asRole('Admin Konstruksi');
        $revised = $this->project($p['id']);
        $revised['planBudget']['bop'] = [];
        $this->api('saveProjectBundle', [$revised, false])->assertOk();
        $this->assertSame($before, (array) $this->allocation($p['id']));
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $after = $this->allocation($p['id']);
        $this->assertSame(0, (int) $after->bop);
        $this->assertSame($before['material'], $after->material);
        $this->assertCount(1, $this->project($p['id'])['approvalHistory']);
        $this->reference($p, true)->assertOk()->assertJsonPath('application_used.TOTAL', 0);
    }

    public function test_unknown_pid_tracks_new_commitment_and_later_import_keeps_it_and_can_show_deficit(): void
    {
        [$p] = $this->package('UNREGISTERED-PID');
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $this->assertNull($this->allocation($p['id'])->budget_pid_id);
        $before = (array) $this->allocation($p['id']);
        $this->master($p, ['TOTAL' => 1]);
        $this->assertSame($before, (array) $this->allocation($p['id']));
        $this->reference($p)->assertOk()->assertJsonPath('remaining.TOTAL', 1 - (int) $p['planBudgetTotals']['ownerTotal']);
        $this->asRole('Finance');
        $this->getJson('/api/budgeting-pid?unit=Konstruksi')->assertOk()->assertJsonPath('rows.0.application_used.TOTAL', (int) $p['planBudgetTotals']['ownerTotal']);
    }

    public function test_old_pp_is_not_tagged_or_counted_even_if_client_forges_policy(): void
    {
        $old = $this->state()['projects'][0];
        [$p] = $this->package($old['projectCode']);
        $this->master($p);
        $this->assertDatabaseCount('budget_pid_allocations', 1);
        $this->assertArrayNotHasKey('budgetPidPolicy', $this->project($old['id']));
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $this->reference($p)->assertOk()->assertJsonPath('application_used.TOTAL', (int) $p['planBudgetTotals']['ownerTotal']);
        $old['budgetPidPolicy'] = BudgetPidAllocation::POLICY;
        $old['unit'] = 'Konstruksi';
        $this->api('saveProjectBundle', [$old, false])->assertUnprocessable();
        $this->assertArrayNotHasKey('budgetPidPolicy', $this->project($old['id']));
        $this->assertDatabaseCount('budget_pid_allocations', 1);
    }

    public function test_operation_has_own_approvers_and_new_pid_cannot_mix_units_or_branches(): void
    {
        [$p] = $this->package('OPERATION-PID', true);
        $this->master($p);
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $this->assertSame('Operation', $this->allocation($p['id'])->unit);
        $this->assertSame('operation-v1', $this->project($p['id'])['approvalScheme']);
        $this->asRole('Officer 1 Operation');
        $this->api('decideProject', [$p['id'], 'Setujui', ''])->assertOk();
        $this->asRole('Admin Konstruksi');
        $query = ['pid' => $p['projectCode'], 'unit' => 'Konstruksi', 'branch' => $p['branch']];
        $this->getJson('/api/budgeting-pid/reference?'.http_build_query($query))->assertOk()->assertJsonPath('status', 'unit_conflict')->assertJsonMissingPath('remaining');
        $this->getJson('/api/budgeting-pid/reference?'.http_build_query(array_replace($query, ['unit' => 'Operation', 'pp_id' => $p['id']])))->assertForbidden();
    }

    public function test_failed_ledger_write_rolls_back_entire_submission_and_audit(): void
    {
        [$p] = $this->package();
        $this->master($p);
        $before = $this->state();
        $revision = PrototypeWorkspace::first()->revision;
        $a = (array) $this->allocation($p['id']);
        DB::unprepared("CREATE TRIGGER fail_budget_reserve BEFORE INSERT ON budget_pid_allocation_changes WHEN NEW.action = 'reserve' BEGIN SELECT RAISE(ABORT, 'synthetic ledger failure'); END");
        $this->api('submitProjectBundle', [$p['id']])->assertServerError();
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertSame($a, (array) $this->allocation($p['id']));
        $this->assertSame($before, $this->state());
    }

    public function test_unknown_new_pid_cannot_mix_units_and_manual_branches_require_reconciliation_before_mapping(): void
    {
        [$p] = $this->package('UNMAPPED-IDENTITY');
        $first = $p;
        $before = DB::table('budget_pid_allocation_changes')->count();
        $p['id'] = $p['rootId'] = 'pp-'.Str::uuid();
        unset($p['signatureRows']);
        $p['approverUserIds'] = [];
        $p['unit'] = 'Operation';
        $this->asRole('Admin Operation');
        $this->api('saveProjectBundle', [$p, false])->assertUnprocessable()->assertSee('unit atau Branch lain', false);
        $this->assertDatabaseMissing('project_plans', ['source_id' => $p['id']]);
        $this->asRole('Admin Konstruksi');
        $p['unit'] = 'Konstruksi';
        $p['branch'] = 'SEMARANG';
        $this->api('saveProjectBundle', [$p, false])->assertOk();
        $this->assertDatabaseCount('budget_pid_allocation_changes', $before + 1);
        $this->api('submitProjectBundle', [$first['id']])->assertOk();
        $this->master($p, [], false);
        $this->asRole('Finance');
        $master = DB::table('budget_pids')->where('pid', $p['projectCode'])->first();
        $branch = (int) DB::table('branches')->where('name', $p['branch'])->value('id_branch');
        $this->postJson('/api/budgeting-pid/branch', ['ids' => [$master->id_budget_pid], 'unit' => 'Konstruksi', 'branch_id' => $branch,
            'version' => DB::table('budget_pid_controls')->value('version'), 'request_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertNull(DB::table('budget_pids')->where('id_budget_pid', $master->id_budget_pid)->value('branch_id'));
    }

    public function test_reference_current_pp_requires_exact_authorized_source_and_does_not_read_archive(): void
    {
        [$p] = $this->package();
        $this->master($p);
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->reference($p, true)->assertOk()->assertJsonPath('application_used.TOTAL', 0);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(8, count($queries));
        foreach ($queries as $q) {
            $this->assertDoesNotMatchRegularExpression('/select.*document_data|select.*private_data|select.*[`\"]state[`\"]/i', $q['query']);
        }
        $query = ['pid' => $p['projectCode'], 'unit' => $p['unit'], 'branch' => $p['branch'], 'pp_id' => 'not-the-existing-pp'];
        $this->getJson('/api/budgeting-pid/reference?'.http_build_query($query))->assertForbidden();
        fwrite(STDERR, "\nBudget PID own-reference: ".count($queries)." HTTP queries.\n");
    }

    public function test_paid_retains_consumption_release_frees_only_unused_owner_volume_and_continuation_is_new(): void
    {
        [$p, $d, $v] = $this->package();
        $this->master($p);
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $reserved = (array) $this->allocation($p['id']);
        foreach (PrototypeDomain::APPROVERS_V3 as $role) {
            $this->asRole($role);
            $this->api('decideProject', [$p['id'], 'Setujui', ''])->assertOk();
        }
        $this->asRole('Admin Konstruksi');
        $this->api('createTerm', [$v['id']])->assertOk();
        $getTerm = fn () => collect($this->state()['terms'])->firstWhere('spkId', $v['id']);
        $t = $getTerm();
        $t['type'] = 'Final';
        $t['actuals'] = ['budget-row' => ['m' => 0, 's' => 1]];
        $this->api('saveTerm', [$t, false])->assertOk();
        $this->api('attestBarp', [$getTerm()])->assertOk();
        $this->api('prepareBapp', [$t['id']])->assertOk();
        $b = collect($this->state()['bapps'])->firstWhere('termId', $t['id']);
        $this->api('attestBapp', [$b['id'], $b['no']])->assertOk();
        $t = $getTerm();
        $form = ['settlementUserId' => 'db-user-'.$this->accounts['Mgr Finance & Settlement']->id_user, 'payeeVersion' => 1,
            'costCenter' => 'QA CC', 'wbs' => 'QA WBS', 'activity' => 'QA kasbon', 'place' => 'Surabaya', 'date' => '2026-11-15', 'evp' => 'I GEDE WAHYU DANYNDRA',
            'rows' => [['accountCode' => 'QA1', 'accountName' => 'QA Account', 'text' => 'QA Work', 'amount' => (string) KasbonSettlement::quote($d, $t)['net']]]];
        $this->postJson('/api/finance/terms/'.$t['id'].'/kasbon', ['data' => $form, 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()])->assertOk();
        foreach ([...PrototypeDomain::APPROVERS_V3, 'Mgr Finance & Settlement', 'Finance'] as $role) {
            $this->asRole($role);
            $this->api('decide', [$t['id'], 'Setujui', ''])->assertOk();
        }
        $upload = $this->post('/api/finance/uploads/transfer-proof/'.$t['id'], ['file' => UploadedFile::fake()->image('proof.png'), 'revision' => (string) PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
        app(UploadQueue::class)->process($upload);
        $this->api('settleFinancePayment', [$t['id'], ''])->assertOk();
        $this->assertSame($reserved, (array) $this->allocation($p['id']), 'Paid must never release planned PID budget.');
        $this->asRole('Admin Konstruksi');
        $this->reference($p)->assertOk()->assertJsonPath('remaining.TOTAL', 0);
        $this->api('releaseVolume', [$b['id'], ['budget-row' => ['m' => 0, 's' => 2]]])->assertOk();
        $rootRow = collect($p['boq'])->firstWhere('id', 'budget-row');
        $credit = 2 * $rootRow['s'];
        $afterRelease = $this->allocation($p['id']);
        $this->assertSame((int) $reserved['service'] - $credit, (int) $afterRelease->service);
        $this->assertSame((int) $reserved['material'], (int) $afterRelease->material);
        $this->assertSame((int) $reserved['bop'], (int) $afterRelease->bop);
        $this->reference($p)->assertOk()->assertJsonPath('remaining.TOTAL', $credit);
        $this->api('createContinuation', [$b['id'], '', ['budget-row' => ['m' => 0, 's' => 2]], 'QA continuation'])->assertOk();
        $child = collect($this->state()['projects'])->firstWhere('parentId', $p['id']);
        $this->assertSame(BudgetPidAllocation::POLICY, $child['budgetPidPolicy']);
        $this->assertSame('draft', $this->allocation($child['id'])->status);
        $this->reference($p)->assertOk()->assertJsonPath('remaining.TOTAL', $credit);
        $this->assertSame($credit, (int) $child['planBudgetTotals']['ownerTotal']);
        $this->assertSame('Sah', $this->project($p['id'])['status']);
        $this->assertGreaterThan(0, $getTerm()['paid']);
    }

    public function test_allocation_schema_has_named_keys_and_populated_rollback_is_refused(): void
    {
        foreach (['budget_pid_allocations' => 'id_budget_allocation', 'budget_pid_allocation_changes' => 'id_budget_allocation_change'] as $table => $key) {
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true);
            $this->assertSame([$key], $primary['columns']);
        }
        [$p] = $this->package();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Alokasi Budgeting PID sudah berisi data/riwayat');
        (require database_path('migrations/2026_11_24_000003_create_budget_pid_allocations.php'))->down();
    }

    public function test_new_continuation_of_legacy_root_is_tracked_without_adopting_old_pps(): void
    {
        $s = $this->seedWorkflow('E');
        $this->asRole('Admin Konstruksi');
        $b = $this->state()['bapps'][0];
        $this->api('attestBapp', [$b['id'], $b['no']])->assertOk();
        $spk = $this->state()['spks'][0];
        $d = collect($this->state()['deals'])->firstWhere('id', $spk['dealId']);
        $row = collect($d['rows'])->first(fn ($r) => $r['s'] && $r['qs'] > 1);
        $this->assertNotNull($row);
        $this->api('releaseVolume', [$b['id'], [$row['rowId'] => ['m' => 0, 's' => 1]]])->assertOk();
        $this->assertDatabaseCount('budget_pid_allocations', 0);
        $this->api('createContinuation', [$b['id'], '', [$row['rowId'] => ['m' => 0, 's' => 1]], 'Legacy parent, genuinely new child'])->assertOk();
        $child = collect($this->state()['projects'])->first(fn ($p) => ! empty($p['parentId']));
        $this->assertSame(BudgetPidAllocation::POLICY, $child['budgetPidPolicy']);
        $this->assertSame('draft', $this->allocation($child['id'])->status);
        $this->assertArrayNotHasKey('budgetPidPolicy', $this->project($child['rootId']));
        $this->assertDatabaseCount('budget_pid_allocations', 1);
    }

    public function test_unmarked_v3_draft_and_submission_stay_exempt_even_with_forged_policy_and_over_budget(): void
    {
        [$p] = $this->package('PRE-STAGE3-V3', false, true);
        $this->master($p, ['TOTAL' => 1, 'Material' => 1, 'Non Material' => 1, 'Operasional' => 1]);
        $p['budgetPidPolicy'] = BudgetPidAllocation::POLICY;
        $this->api('saveProjectBundle', [$p, false])->assertOk();
        $this->assertArrayNotHasKey('budgetPidPolicy', $this->project($p['id']));
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $this->assertDatabaseCount('budget_pid_allocations', 0);
        $this->assertDatabaseCount('budget_pid_allocation_changes', 0);
        $this->reference($p)->assertOk()->assertJsonPath('application_used.TOTAL', 0);
    }

    public function test_ledger_refuses_fractional_negative_exponent_and_unsafe_numbers(): void
    {
        foreach (['1.5', '-1', '1e3', '9007199254740991.1', '9007199254740992', 1.25, INF] as $invalid) {
            try {
                BudgetPidAllocation::integer($invalid);
                $this->fail('Invalid allocation amount must not be silently converted.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $this->assertSame(123, BudgetPidAllocation::integer('123.00'));
    }

    public function test_import_auto_mapping_cannot_reassign_reserved_unmapped_pid_to_another_branch(): void
    {
        [$p] = $this->package('26KT03R409-0099');
        $this->api('submitProjectBundle', [$p['id']])->assertOk();
        $before = $this->state();
        $allocation = (array) $this->allocation($p['id']);
        try {
            $this->master($p, [], false);
            $this->fail('Import must refuse a conflicting auto Branch mapping.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseCount('budget_pid_imports', 0);
        $this->assertDatabaseCount('budget_pids', 0);
        $this->assertSame($allocation, (array) $this->allocation($p['id']));
        $this->asRole('Admin Konstruksi');
        $this->assertSame($before, $this->state());
    }
}
