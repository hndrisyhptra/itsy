<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AuthenticatedWorkspace;
use App\Services\RelationalWorkflowStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
