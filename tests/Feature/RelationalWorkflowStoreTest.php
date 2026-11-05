<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\PrototypeWorkspaceService;
use App\Services\RelationalWorkflowStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class RelationalWorkflowStoreTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function command(string $command, array $args, ?string $request = null, ?int $revision = null)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args,
            'request_id' => $request ?? (string) Str::uuid(), 'revision' => $revision ?? $this->getJson('/api/prototype/state')->json('revision')]);
    }

    private function newProject(string $id): array
    {
        $state = $this->getJson('/api/prototype/state')->assertOk()->json('state');
        $p = app(PrototypeWorkspaceService::class)->scenario('B')['projects'][0];
        $p['id'] = $p['rootId'] = $id;
        $p['branch'] = 'DENPASAR';
        $p['unit'] = 'Konstruksi';
        $p['projectCode'] = 'MULTI-PP-PROJECT';
        unset($p['parentId'], $p['sourceBapp']);
        $p['approverUserIds'] = [];
        $k = collect($state['khs'])->firstWhere('active', true);
        $p['boq'] = [['id' => 'row-'.$id, 'khsId' => $k['id'], 'qty' => 1.125]];

        return $p;
    }

    public function test_runtime_reads_business_rows_and_does_not_update_or_fall_back_to_the_workspace_archive(): void
    {
        $original = $this->seedWorkflow('B');
        $workspace = PrototypeWorkspace::firstOrFail();
        $store = app(RelationalWorkflowStore::class);
        $this->assertEquals($original['projects'], $store->read($workspace)['projects']);
        $this->assertSame(count($original['projects']), DB::table('project_plans')->count());
        $this->assertGreaterThan(0, DB::table('boq_components')->count());
        $this->command('setNumberFormat', ['LIVE/{SEQ}'])->assertOk();
        $this->assertSame($original, $workspace->fresh()->state);
        $workspace->update(['state' => ['archived' => true]]);
        $this->getJson('/api/prototype/state')->assertOk()->assertJsonPath('state.settings.ppNumberFormat', 'LIVE/{SEQ}');
        $this->assertFalse($store->activate($workspace->fresh()));
        $this->assertSame(count($original['projects']), DB::table('project_plans')->count());
    }

    public function test_new_pp_is_saved_to_business_tables_with_unique_ids_numbering_and_one_project_for_many_pps(): void
    {
        $this->seedWorkflow('B');
        $this->asRole('Admin Konstruksi');
        $first = $this->newProject('pp-live-first');
        $second = $this->newProject('pp-live-second');
        foreach ([$first, $second] as $p) {
            $this->command('saveProject', [$p, false])->assertOk();
            $row = DB::table('project_plans')->where('source_id', $p['id'])->first();
            $this->assertTrue(Str::isUuid($row->id_pp));
            $this->assertSame('DRAFT', $row->status);
            $this->assertNull($row->pm_user_id);
            $this->assertNull($row->manager_user_id);
            $this->assertNotNull($row->numbering_rule_id);
            $this->assertSame('R502', $row->kode_branch_snapshot);
            $this->assertDatabaseCount('project_plan_schedule', DB::table('project_plans')->count() * 5);
            $this->assertDatabaseHas('boq_lines', ['root_pp_id' => $row->id_pp, 'source_row_id' => $p['boq'][0]['id']]);
        }
        $this->assertSame(1, DB::table('projects')->where('project_code', 'MULTI-PP-PROJECT')->count());
        $rows = DB::table('project_plans')->whereIn('source_id', [$first['id'], $second['id']])->get();
        $this->assertSame($rows[0]->project_id, $rows[1]->project_id);
        $this->assertNotSame($rows[0]->pp_no, $rows[1]->pp_no);
        $this->assertSame($rows[0]->number_sequence + 1, $rows[1]->number_sequence);
        $this->assertCount(2, collect($this->getJson('/api/prototype/state')->json('state.projects'))->where('projectCode', 'MULTI-PP-PROJECT'));
    }

    public function test_sql_failure_rolls_back_pp_children_sequence_revision_and_audit_together(): void
    {
        $this->seedWorkflow('B');
        $this->asRole('Admin Konstruksi');
        $before = $this->getJson('/api/prototype/state')->json();
        $count = DB::table('project_plans')->count();
        $p = $this->newProject('pp-rollback-check');
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed) {
            if ($armed && str_contains($query->sql, 'insert into "project_plan_schedule"')) {
                $armed = false;
                throw new RuntimeException('Isolated SQL failure after PP insert');
            }
        });
        $this->command('saveProject', [$p, false])->assertServerError();
        $this->assertFalse($armed, 'Fault must occur after writing the new header.');
        $this->assertDatabaseCount('project_plans', $count);
        $this->assertDatabaseMissing('project_plans', ['source_id' => $p['id']]);
        $this->assertDatabaseMissing('projects', ['project_code' => $p['projectCode']]);
        $this->assertDatabaseCount('prototype_audits', 0);
        $after = $this->getJson('/api/prototype/state')->assertOk()->json();
        $this->assertSame($before['revision'], $after['revision']);
        $this->assertSame($before['state'], $after['state']);
    }

    public function test_existing_unmigrated_workspace_fails_closed_instead_of_using_demo_or_archive(): void
    {
        $this->seedWorkflow('B');
        PrototypeWorkspace::first()->update(['relational_migrated_at' => null]);
        $this->getJson('/api/prototype/state')->assertStatus(503);
    }

    public function test_removing_a_draft_actual_also_removes_its_sql_detail_without_changing_contract_volume(): void
    {
        $this->seedWorkflow('D');
        $state = $this->getJson('/api/prototype/state')->json('state');
        $spk = $state['spks'][0];
        $deal = collect($state['deals'])->firstWhere('id', $spk['dealId']);
        $row = $deal['rows'][0];
        $other = $deal['rows'][1];
        $created = $this->command('createTerm', [$spk['id']])->assertOk()->json('state');
        $term = collect($created['terms'])->firstWhere('spkId', $spk['id']);
        $term['actuals'] = [
            $row['rowId'] => ['m' => $row['m'] ? 0.125 : 0, 's' => $row['s'] ? 0.125 : 0],
            $other['rowId'] => ['m' => $other['m'] ? 0.125 : 0, 's' => $other['s'] ? 0.125 : 0],
        ];
        $this->command('saveTerm', [$term, false])->assertOk();
        $this->assertGreaterThan(0, DB::table('term_actuals')->count());
        $contract = DB::table('contract_components')->orderBy('id_contract_component')->get();
        unset($term['actuals'][$row['rowId']]);
        $this->command('saveTerm', [$term, false])->assertOk();
        $store = app(RelationalWorkflowStore::class);
        foreach (['m', 's'] as $component) {
            $this->assertDatabaseMissing('term_actuals', ['contract_component_id' => $store->id(PrototypeWorkspace::first()->id, 'contract-component', $deal['id'].'|'.$row['rowId'].'|'.$component)]);
        }
        $this->assertGreaterThan(0, DB::table('term_actuals')->count());
        $this->assertEquals($contract, DB::table('contract_components')->orderBy('id_contract_component')->get());
        $this->assertDatabaseCount('command_receipts', 3);
    }
}
