<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AuthenticatedWorkspace;
use App\Services\RelationalWorkflowStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class WorkflowPerformanceTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    public function test_incremental_write_avoids_rewriting_unrelated_documents_and_balances_with_large_query_reduction(): void
    {
        $this->withoutVite();
        $this->seedWorkflow('B');
        $workspace = PrototypeWorkspace::firstOrFail();
        $store = app(RelationalWorkflowStore::class);
        $state = app(AuthenticatedWorkspace::class)->bind($store->read($workspace));
        $source = $state['projects'][0];
        $state['projects'] = [$source];
        $state['deals'] = $state['spks'] = $state['terms'] = $state['bapps'] = [];
        for ($i = 1; $i < 20; $i++) {
            $p = $source;
            $p['id'] = $p['rootId'] = 'perf-pp-'.$i;
            $p['no'] = 'PERF-'.$i;
            $p['projectCode'] = 'PERF-PROJECT-'.$i;
            $state['projects'][] = $p;
        }
        DB::transaction(fn () => $store->write($workspace, $state));
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        DB::transaction(fn () => $store->write($workspace, $state));
        $fullCount = count($queries);
        $before = DB::table('project_plans')->orderBy('id_pp')->get()->toJson();
        $balances = DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson();
        $queries = [];
        $next = $state;
        $next['settings']['ppNumberFormat'] = '{SEQ}/PERF/{BRANCH}/{YYYY}';
        DB::transaction(fn () => $store->write($workspace, $next, $state));
        $incrementalCount = count($queries);
        $this->assertLessThan($fullCount / 10, $incrementalCount, 'Incremental metadata writes must avoid N-document rewrites.');
        $this->assertSame($before, DB::table('project_plans')->orderBy('id_pp')->get()->toJson());
        $this->assertSame($balances, DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson());
        $this->assertSame($next, $store->read($workspace));
        fwrite(STDERR, "\nPerformance fixture (20 PP): full write {$fullCount} queries; incremental {$incrementalCount} queries.\n");
    }

    public function test_runtime_workspace_projection_omits_archive_and_keeps_audit_hashes_equal(): void
    {
        $this->withoutVite();
        $this->seedWorkflow('B');
        $workspace = PrototypeWorkspace::firstOrFail();
        $archive = ['preserved' => str_repeat('x', 512 * 1024)];
        $workspace->update(['state' => $archive]);
        $raw = $workspace->getRawOriginal('state');
        $runtime = PrototypeWorkspace::runtimeQuery()->findOrFail($workspace->id);
        $this->assertArrayNotHasKey('state', $runtime->getAttributes());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/prototype/state')->assertOk()->assertJsonCount(1, 'state.projects');
        $this->postJson('/api/prototype/commands', ['command' => 'setNumberFormat', 'args' => ['{SEQ}/PERFORMANCE/{YYYY}'], 'revision' => $workspace->revision, 'request_id' => (string) Str::uuid()])->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $workspaceReads = array_filter($queries, fn ($q) => str_starts_with($q['query'], 'select') && str_contains($q['query'], 'from "prototype_workspaces"'));
        $this->assertNotEmpty($workspaceReads);
        foreach ($workspaceReads as $query) {
            $this->assertDoesNotMatchRegularExpression('/select\s+\*/i', $query['query']);
            $this->assertStringNotContainsString('"state"', $query['query']);
        }
        $this->assertSame($raw, $workspace->fresh()->getRawOriginal('state'));
        $audit = DB::table('prototype_audits')->latest('id')->first();
        $receipt = DB::table('command_receipts')->where('request_uuid', $audit->request_id)->first();
        $this->assertSame($audit->before_hash, $receipt->before_sha256);
        $this->assertSame($audit->after_hash, $receipt->after_sha256);
        fwrite(STDERR, "\nRuntime workspace: ".strlen($raw).' archive bytes excluded from '.count($workspaceReads)." runtime SELECTs.\n");
    }

    public function test_five_hundred_boq_rows_write_in_bounded_batches_and_preserve_exact_projection_on_update(): void
    {
        $this->seedWorkflow('B');
        $workspace = PrototypeWorkspace::firstOrFail();
        $store = app(RelationalWorkflowStore::class);
        $before = app(AuthenticatedWorkspace::class)->bind($store->read($workspace));
        $p = $before['projects'][0];
        $p['id'] = $p['rootId'] = 'batch-500';
        $p['projectCode'] = 'BATCH-PROJECT-500';
        $p['no'] = 'BATCH-500';
        $row = $p['boq'][0];
        $p['boq'] = [];
        for ($i = 0; $i < 500; $i++) {
            $p['boq'][] = [...$row, 'id' => 'batch-row-'.$i, 'qty' => 1.125, 'm' => 0, 's' => 12345];
        }
        $after = $before;
        $after['projects'][] = $p;
        DB::enableQueryLog();
        DB::flushQueryLog();
        DB::transaction(fn () => $store->write($workspace, $after, $before));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(90, count($queries));
        $this->assertSame($p, collect($store->read($workspace)['projects'])->firstWhere('id', $p['id']));
        $pp = $store->id($workspace->id, 'projects', $p['id']);
        $this->assertSame(500, DB::table('boq_lines')->where('root_pp_id', $pp)->count());
        $id = $store->id($workspace->id, 'boq-component', $p['id'].'|batch-row-0|m');
        $this->assertEquals(0, DB::table('boq_components')->where('id_boq_component', $id)->value('official_unit_price'));
        $this->assertEquals(1.125, DB::table('volume_balances')->where('boq_component_id', $id)->value('available'));
        $originalId = DB::table('project_plan_schedule')->where('pp_id', $pp)->where('stage_no', 1)->value('id_pp_schedule');
        $updated = $after;
        $updated['projects'][1]['boq'][0]['qty'] = 2.125;
        array_pop($updated['projects'][1]['boq']);
        DB::transaction(fn () => $store->write($workspace, $updated, $after));
        $this->assertSame($originalId, DB::table('project_plan_schedule')->where('pp_id', $pp)->where('stage_no', 1)->value('id_pp_schedule'));
        $this->assertEquals(2.125, DB::table('volume_balances')->where('boq_component_id', $id)->value('available'));
        $this->assertSame(499, DB::table('boq_lines')->where('root_pp_id', $pp)->count());
        $this->assertSame($updated['projects'][1], collect($store->read($workspace)['projects'])->firstWhere('id', $p['id']));
        fwrite(STDERR, "\n500 BOQ rows /1000 components: ".count($queries)." queries for explicit PP write.\n");
    }

    public function test_conflicting_component_identity_fails_and_rolls_back_prior_child_updates(): void
    {
        $this->seedWorkflow('B');
        $workspace = PrototypeWorkspace::firstOrFail();
        $store = app(RelationalWorkflowStore::class);
        $state = app(AuthenticatedWorkspace::class)->bind($store->read($workspace));
        $p = $state['projects'][0];
        $p['id'] = $p['rootId'] = 'batch-conflict';
        $p['no'] = 'BATCH-CONFLICT';
        $p['projectCode'] = 'BATCH-CONFLICT-PROJECT';
        $initial = $state;
        $state['projects'][] = $p;
        DB::transaction(fn () => $store->write($workspace, $state, $initial));
        $component = $store->id($workspace->id, 'boq-component', $p['id'].'|'.$p['boq'][0]['id'].'|m');
        DB::table('volume_balances')->where('boq_component_id', $component)->delete();
        DB::table('boq_components')->where('id_boq_component', $component)->update(['id_boq_component' => (string) Str::uuid()]);
        $before = DB::table('project_plans')->where('source_id', $p['id'])->value('document_data');
        $childRows = DB::table('project_plan_schedule')->orderBy('id_pp_schedule')->get()->toJson();
        $next = $state;
        $next['projects'][1]['schedule'][0]['days']++;
        try {
            DB::transaction(fn () => $store->write($workspace, $next, $state));
            $this->fail('Conflicting projection must not be silently remapped by MySQL upsert.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Identitas proyeksi komponen BOQ', $e->getMessage());
        }
        $this->assertSame($before, DB::table('project_plans')->where('source_id', $p['id'])->value('document_data'));
        $this->assertSame($childRows, DB::table('project_plan_schedule')->orderBy('id_pp_schedule')->get()->toJson());
    }

    public function test_branch_scoping_uses_each_pp_when_one_project_code_is_shared_between_branches(): void
    {
        $this->withoutVite();
        $this->seedWorkflow('B');
        $workspace = PrototypeWorkspace::firstOrFail();
        $store = app(RelationalWorkflowStore::class);
        $state = app(AuthenticatedWorkspace::class)->bind($store->read($workspace));
        $copy = $state['projects'][0];
        $copy['id'] = $copy['rootId'] = 'pp-other-branch';
        $copy['no'] = 'PERF-OTHER-BRANCH';
        $copy['branch'] = 'KUPANG';
        $state['projects'][] = $copy;
        DB::transaction(fn () => $store->write($workspace, $state));
        $kupang = DB::table('branches')->where('name', 'KUPANG')->value('id_branch');
        $visible = $store->read($workspace, [$kupang]);
        $this->assertCount(1, $visible['projects']);
        $this->assertSame('pp-other-branch', $visible['projects'][0]['id']);
        $this->assertSame(1, DB::table('projects')->where('project_code', $copy['projectCode'])->count());
        $this->assertSame($kupang, DB::table('project_plans')->where('source_id', 'pp-other-branch')->value('branch_id'));
    }

    public function test_large_barp_actuals_use_bounded_batches_keep_row_ids_and_do_not_touch_other_workflow_data(): void
    {
        $this->withoutVite();
        $this->seedWorkflow('D');
        $workspace = PrototypeWorkspace::firstOrFail();
        $store = app(RelationalWorkflowStore::class);
        $state = app(AuthenticatedWorkspace::class)->bind($store->read($workspace));
        $p = $state['projects'][0];
        $deal = $state['deals'][0];
        $spk = $state['spks'][0];
        $p['id'] = $p['rootId'] = 'audit-pp-actuals';
        $p['no'] = 'AUDIT-PP-ACTUALS';
        $p['projectCode'] = 'AUDIT-PID-ACTUALS';
        $deal['id'] = 'audit-deal-actuals';
        $deal['projectId'] = $p['id'];
        $deal['no'] = 'AUDIT-BAKH-ACTUALS';
        $spk['id'] = 'audit-spk-actuals';
        $spk['dealId'] = $deal['id'];
        $spk['no'] = 'AUDIT-SPK-ACTUALS';
        $boq = $p['boq'][0];
        $row = $deal['rows'][0];
        $p['boq'] = $deal['rows'] = [];
        $actuals = [];
        for ($i = 0; $i < 500; $i++) {
            $id = 'audit-actual-'.$i;
            $p['boq'][] = [...$boq, 'id' => $id, 'qty' => 10, 'm' => 100, 's' => 200];
            $deal['rows'][] = [...$row, 'rowId' => $id, 'm' => true, 's' => true, 'qm' => 10, 'qs' => 10,
                'capM' => 100, 'capS' => 200, 'sphM' => 1, 'sphS' => 2, 'offerM' => 1, 'offerS' => 2, 'pm' => 1, 'ps' => 2];
            $actuals[$id] = ['m' => 1, 's' => 2];
        }
        $state['projects'][] = $p;
        $state['deals'][] = $deal;
        $state['spks'][] = $spk;
        DB::transaction(fn () => $store->write($workspace, $state));
        $this->asRole('Admin Konstruksi');
        $this->postJson('/api/prototype/commands', ['command' => 'createTerm', 'args' => [$spk['id']],
            'revision' => $workspace->fresh()->revision, 'request_id' => (string) Str::uuid()])->assertOk();
        $term = $this->getJson('/api/prototype/state')->assertOk()->json('state.terms.0');
        $term['actuals'] = $actuals;
        $contractBefore = DB::table('contract_components')->orderBy('id_contract_component')->get()->toJson();
        $balancesBefore = DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/api/prototype/commands', ['command' => 'saveTerm', 'args' => [$term, false],
            'revision' => $workspace->fresh()->revision, 'request_id' => (string) Str::uuid()])->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $actualQueries = array_filter($queries, fn ($q) => str_contains($q['query'], '"term_actuals"') || str_contains($q['query'], '"contract_components"'));
        fwrite(STDOUT, "\n500 BARP rows/1000 actuals: ".count($queries).' HTTP queries; actual component queries '.count($actualQueries).".\n");
        $this->assertLessThanOrEqual(20, count($actualQueries), 'Actual details should be selected/written in bounded batches.');
        $this->assertDatabaseCount('term_actuals', 1000);
        $ids = DB::table('term_actuals')->pluck('id_term_actual', 'contract_component_id')->all();
        $stored = collect($store->read($workspace)['terms'])->firstWhere('id', $term['id']);
        $this->assertSame($actuals, $stored['actuals']);
        $this->assertSame($contractBefore, DB::table('contract_components')->orderBy('id_contract_component')->get()->toJson());
        $this->assertSame($balancesBefore, DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson());
        $term['actuals']['audit-actual-0'] = ['m' => 0, 's' => 3];
        unset($term['actuals']['audit-actual-499']);
        $this->postJson('/api/prototype/commands', ['command' => 'saveTerm', 'args' => [$term, false],
            'revision' => $workspace->fresh()->revision, 'request_id' => (string) Str::uuid()])->assertOk();
        $this->assertDatabaseCount('term_actuals', 998);
        foreach (DB::table('term_actuals')->get() as $actual) {
            $this->assertSame($ids[$actual->contract_component_id], $actual->id_term_actual);
        }
        $this->assertEquals(0, DB::table('term_actuals')->where('contract_component_id', $store->id($workspace->id, 'contract-component', $deal['id'].'|audit-actual-0|m'))->value('actual_quantity'));
        $this->assertDatabaseCount('approval_decisions', 0);
        $this->assertDatabaseCount('apm_settlements', 0);
        $beforeDetails = DB::table('term_actuals')->orderBy('id_term_actual')->get()->toJson();
        $beforeTerm = DB::table('payment_terms')->get()->toJson();
        $revision = $workspace->fresh()->revision;
        $receipts = DB::table('command_receipts')->count();
        $last = $store->id($workspace->id, 'contract-component', $deal['id'].'|audit-actual-499|s');
        DB::statement("CREATE TRIGGER audit_actual_failure BEFORE INSERT ON term_actuals WHEN NEW.contract_component_id = '{$last}' BEGIN SELECT RAISE(ABORT, 'isolated audit rollback'); END");
        $term['actuals']['audit-actual-0'] = ['m' => 3, 's' => 3];
        $term['actuals']['audit-actual-499'] = ['m' => 1, 's' => 7];
        $this->postJson('/api/prototype/commands', ['command' => 'saveTerm', 'args' => [$term, false],
            'revision' => $revision, 'request_id' => (string) Str::uuid()])->assertServerError();
        DB::statement('DROP TRIGGER audit_actual_failure');
        $this->assertSame($beforeDetails, DB::table('term_actuals')->orderBy('id_term_actual')->get()->toJson());
        $this->assertSame($beforeTerm, DB::table('payment_terms')->get()->toJson());
        $this->assertSame($revision, $workspace->fresh()->revision);
        $this->assertDatabaseCount('command_receipts', $receipts);
        $this->assertSame($contractBefore, DB::table('contract_components')->orderBy('id_contract_component')->get()->toJson());
        $this->assertSame($balancesBefore, DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson());
    }
}
