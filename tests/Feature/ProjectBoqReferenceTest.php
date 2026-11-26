<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\ProjectBoqIdentity;
use App\Services\PrototypeWorkspaceService;
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

class ProjectBoqReferenceTest extends TestCase
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

    private function api(string $command, array $args, ?string $uuid = null)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => $uuid ?? (string) Str::uuid()]);
    }

    private function upload(string $id): array
    {
        $task = $this->post('/api/uploads/boq', ['file' => UploadedFile::fake()->createWithContent('synthetic.xlsx', file_get_contents(base_path('tests/Fixtures/boq/osp-fo-paket10.xlsx'))), 'document_id' => $id, 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
        app(UploadQueue::class)->process($task);

        return $this->getJson('/api/uploads/'.$task.'?result=1')->assertOk()->assertJsonPath('task.status', 'completed')->json('result');
    }

    private function package(bool $operation = false): array
    {
        $headRole = $operation ? 'General Manager Operation' : 'Head of PMO';
        $head = $this->asRole($headRole);
        if ($operation) {
            DB::table('users')->where('id_user', $head->id_user)->update(['access_scope' => 'REGION', 'id_regions' => DB::table('branches')->where('name', 'DENPASAR')->value('region_id')]);
        }
        $this->asRole($operation ? 'Admin Operation' : 'Admin Konstruksi');
        $p = app(PrototypeWorkspaceService::class)->scenario('B')['projects'][0];
        $p['id'] = $p['rootId'] = 'pp-ref-'.Str::uuid();
        $p['projectCode'] = 'PID-'.$p['id'];
        $p['branch'] = 'DENPASAR';
        $p['unit'] = $operation ? 'Operation' : 'Konstruksi';
        $p['date'] = '2026-11-01';
        $p['workStartDate'] = '2026-11-02';
        unset($p['boqUpload'], $p['approverUserIds'], $p['pmUserId'], $p['managerUserId']);
        $p['approverUserIds'] = [$headRole => 'db-user-'.$head->id_user];
        $source = $this->upload($p['id']);
        $p['boq'] = $source['rows'];
        $p['boqUpload'] = $source['source'];
        $p = collect($this->api('saveProjectBundle', [$p, false])->assertOk()->json('state.projects'))->firstWhere('id', $p['id']);
        $row = $p['boq'][0];
        $d = app(PrototypeWorkspaceService::class)->scenario('C')['deals'][0];
        $d['id'] = 'bakh-ref-'.Str::uuid();
        $d['projectId'] = $p['id'];
        $d['date'] = '2026-11-01';
        $d['start'] = $p['schedule'][0]['start'];
        $d['end'] = $p['schedule'][4]['end'];
        $d['rows'] = [['rowId' => $row['id'], 'm' => $row['m'] !== null, 's' => $row['s'] !== null, 'qm' => $row['m'] !== null ? 1 : 0, 'qs' => $row['s'] !== null ? 1 : 0, 'pm' => 0, 'ps' => 0, 'sphM' => 0, 'sphS' => 0, 'offerM' => 0, 'offerS' => 0, 'capM' => $row['m'], 'capS' => $row['s']]];
        $d = collect($this->api('saveDeal', [$d, 'save'])->assertOk()->json('state.deals'))->firstWhere('id', $d['id']);
        $v = app(PrototypeWorkspaceService::class)->scenario('D')['spks'][0];
        $v['id'] = 'spk-ref-'.Str::uuid();
        $v['dealId'] = $d['id'];
        $v['date'] = $d['date'];
        $v['start'] = $d['start'];
        $v['end'] = $d['end'];
        $this->api('saveSpkFinance', [$v, 'save'])->assertOk();

        return [$p, $d, $v];
    }

    public function test_stabilization_remaps_only_current_budget_identity_fields_without_changing_prices_or_manual_rows(): void
    {
        $old = ['id' => 'root', 'boq' => [['id' => 'saved-row', 'khsId' => 'exact-khs']]];
        $rows = [['id' => 'new-upload-row', 'khsId' => 'exact-khs', 'qty' => 10, 'm' => 1200, 's' => null]];
        $budget = ['mandor' => [['rowId' => 'new-upload-row', 'qtyM' => 10, 'qtyS' => 0, 'priceM' => 500, 'priceS' => 0]], 'materials' => [['id' => 'derived', 'sourceRowId' => 'new-upload-row', 'price' => 500], ['id' => 'manual', 'price' => 77]], 'excludedMaterialRowIds' => ['new-upload-row'], 'bop' => [['total' => 250]], 'permit' => 125];
        [$stableRows, $stableBudget] = ProjectBoqIdentity::stabilize($rows, $old, $budget);
        $this->assertSame(array_replace($rows[0], ['id' => 'saved-row']), $stableRows[0]);
        $this->assertSame(array_replace($budget['mandor'][0], ['rowId' => 'saved-row']), $stableBudget['mandor'][0]);
        $this->assertSame('saved-row', $stableBudget['materials'][0]['sourceRowId']);
        $this->assertSame($budget['materials'][1], $stableBudget['materials'][1]);
        $this->assertSame(['saved-row'], $stableBudget['excludedMaterialRowIds']);
        $this->assertSame($budget['bop'], $stableBudget['bop']);
        $this->assertSame(125, $stableBudget['permit']);
        $this->assertSame('new-upload-row', $rows[0]['id']);
        $this->assertSame('new-upload-row', $budget['mandor'][0]['rowId']);
    }

    public function test_reupload_same_workbook_after_bakh_and_spk_keeps_boq_component_identity_and_child_snapshots_in_both_units(): void
    {
        foreach ([false, true] as $operation) {
            [$p, $d] = $this->package($operation);
            $before = DB::table('contract_components')->where('contract_id', app(RelationalWorkflowStore::class)->id(PrototypeWorkspace::first()->id, 'deals', $d['id']))->orderBy('id_contract_component')->get()->toJson();
            $children = [DB::table('contracts')->orderBy('id_contract')->get()->toJson(), DB::table('work_orders')->orderBy('id_spk')->get()->toJson()];
            $oldIds = array_column($p['boq'], 'id');
            $source = $this->upload($p['id']);
            $this->assertNotSame($oldIds, array_column($source['rows'], 'id'), 'Parser creates fresh IDs on each real upload.');
            $p['boq'] = array_reverse($source['rows']);
            $p['boqUpload'] = $source['source'];
            // Browser budget rows now point at incoming IDs; they must follow the same canonicalization.
            $p['planBudget']['mandor'] = array_map(fn ($r) => ['rowId' => $r['id'], 'qtyM' => $r['m'] !== null ? $r['qty'] : 0, 'qtyS' => $r['s'] !== null ? $r['qty'] : 0, 'priceM' => 0, 'priceS' => 0], $p['boq']);
            $uuid = (string) Str::uuid();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $result = $this->api('saveProjectBundle', [$p, false], $uuid)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
            $saved = collect($result->json('state.projects'))->firstWhere('id', $p['id']);
            $this->assertSame(array_reverse($oldIds), array_column($saved['boq'], 'id'));
            $this->assertSame(array_column($saved['boq'], 'id'), array_column($saved['planBudget']['mandor'], 'rowId'));
            $this->assertSame($source['source']['id'], $saved['boqUpload']['id']);
            $this->assertSame($before, DB::table('contract_components')->where('contract_id', app(RelationalWorkflowStore::class)->id(PrototypeWorkspace::first()->id, 'deals', $d['id']))->orderBy('id_contract_component')->get()->toJson());
            $this->assertSame($children, [DB::table('contracts')->orderBy('id_contract')->get()->toJson(), DB::table('work_orders')->orderBy('id_spk')->get()->toJson()]);
            $this->assertLessThanOrEqual(80, $queries);
            fwrite(STDERR, "\nBOQ reupload with existing BAKH/SPK ".($operation ? 'Operation' : 'Construction').": {$queries} HTTP queries.\n");
            $this->api('saveProjectBundle', [$p, false], $uuid)->assertOk()->assertJsonPath('replayed', true);
        }
    }

    public function test_removing_used_item_or_reusing_id_for_other_catalog_is_validation_and_rolls_back_all_data(): void
    {
        [$p, $d] = $this->package();
        $snapshot = fn () => array_map(fn ($t) => DB::table($t)->orderByRaw('1')->get()->toJson(), ['project_plans', 'boq_lines', 'boq_components', 'contracts', 'contract_components', 'work_orders', 'volume_balances', 'volume_movements', 'prototype_audits', 'command_receipts']);
        $before = $snapshot();
        $revision = PrototypeWorkspace::first()->revision;
        $bad = $p;
        $bad['boq'] = array_slice($p['boq'], 1);
        $bad['planBudget']['mandor'] = array_slice($p['planBudget']['mandor'], 1);
        $this->api('saveProjectBundle', [$bad, false])->assertUnprocessable()->assertJsonValidationErrors('boq');
        $this->assertSame($before, $snapshot());
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $bad = $p;
        $bad['boq'][0]['khsId'] = $p['boq'][1]['khsId'];
        $bad['boq'] = [$bad['boq'][0], ...array_slice($p['boq'], 2)];
        $this->api('saveProjectBundle', [$bad, false])->assertUnprocessable()->assertJsonValidationErrors('boq');
        $this->assertSame($before, $snapshot());
        $row = $p['boq'][0];
        if ($row['qty'] > 1) {
            $d['rows'][0][$row['m'] !== null ? 'qm' : 'qs'] = $row['qty'];
            $this->api('saveDeal', [$d, 'save'])->assertOk();
            $p['boq'][0]['qty'] = $row['qty'] - 1;
            $this->api('saveProjectBundle', [$p, false])->assertUnprocessable();
        }
    }

    public function test_unreferenced_item_can_still_be_removed_while_referenced_component_and_history_stay_intact(): void
    {
        [$p, $d] = $this->package();
        $removed = array_pop($p['boq']);
        array_pop($p['planBudget']['mandor']);
        $this->api('saveProjectBundle', [$p, false])->assertOk();
        $this->assertDatabaseMissing('boq_lines', ['root_pp_id' => app(RelationalWorkflowStore::class)->id(PrototypeWorkspace::first()->id, 'projects', $p['id']), 'source_row_id' => $removed['id']]);
        $this->assertDatabaseCount('contracts', 1);
        $this->assertGreaterThan(0, DB::table('contract_components')->count());
    }

    public function test_persisted_reference_missing_from_document_graph_fails_closed_before_delete_instead_of_fk_500(): void
    {
        [$p] = $this->package();
        $w = PrototypeWorkspace::first();
        $store = app(RelationalWorkflowStore::class);
        $before = $store->read($w);
        $next = $before;
        $next['projects'][0]['boq'][0]['id'] = 'synthetic-new-row';
        $hash = fn () => DB::table('boq_components')->orderBy('id_boq_component')->get()->toJson();
        $old = $hash();
        // The actual FK remains enabled; directly deleting a referenced component is still forbidden.
        $component = DB::table('contract_components')->value('boq_component_id');
        try {
            DB::transaction(fn () => DB::table('boq_components')->where('id_boq_component', $component)->delete());
            $this->fail('FK was disabled');
        } catch (QueryException $e) {
            $this->assertStringContainsString('FOREIGN KEY', $e->getMessage());
        }
        try {
            DB::transaction(fn () => $store->write($w, $next, $before));
            $this->fail('Referenced BOQ deleted');
        } catch (ValidationException $e) {
            $this->assertSame(ProjectBoqIdentity::MESSAGE, $e->errors()['boq'][0]);
        }
        $this->assertSame($old, $hash());
        $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
    }
}
