<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\BudgetPidMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class BudgetPidReferenceTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->asRole();
    }

    private function pid(string $code = 'PID-TEST', string $unit = 'Konstruksi', ?int $branch = null): array
    {
        $id = (string) Str::uuid();
        $import = (string) Str::uuid();
        DB::table('budget_pid_imports')->insert(['id_budget_import' => $import, 'actor_user_id' => auth()->id(), 'unit' => $unit, 'filename' => 'synthetic.xlsx', 'sha256' => str_repeat('a', 64), 'preview_hash' => str_repeat('b', 64), 'version' => 0, 'summary' => '{}', 'created_at' => now()]);
        $row = ['id_budget_pid' => $id, 'pid' => $code, 'name' => 'Nama Master '.$code, 'unit' => $unit, 'branch_id' => $branch, 'last_import_id' => $import, 'created_at' => now(), 'updated_at' => now()];
        DB::table('budget_pids')->insert($row);
        foreach (['TOTAL', 'Material', 'Non Material', 'Operasional', 'Tenaga Kerja', 'Depresiasi'] as $i => $category) {
            DB::table('budget_pid_items')->insert(['id_budget_item' => (string) Str::uuid(), 'budget_pid_id' => $id, 'category' => $category, 'source_title' => $code.'-'.$i, 'source_row' => $i + 2, 'release' => 100000, 'commitment' => 30000, 'actual' => 20000, 'available' => 50000]);
        }

        return $row;
    }

    private function reference(string $pid, string $unit, string $branch)
    {
        return $this->getJson('/api/budgeting-pid/reference?'.http_build_query(compact('pid', 'unit', 'branch')));
    }

    private function map(array $ids, int $branch, string $unit = 'Konstruksi', ?int $version = null, ?string $request = null)
    {
        return $this->postJson('/api/budgeting-pid/branch', ['ids' => $ids, 'branch_id' => $branch, 'unit' => $unit, 'version' => $version ?? DB::table('budget_pid_controls')->value('version'), 'request_id' => $request ?? (string) Str::uuid()]);
    }

    public function test_reference_requires_explicit_branch_mapping_and_unit_and_never_exposes_other_branch_values(): void
    {
        $branches = DB::table('branches')->orderBy('id_branch')->limit(2)->get();
        $this->pid('26KT03R501-0001'); // Prefix is deliberately unrelated to chosen Branch.
        $mapped = $this->pid('PID-MAPPED', 'Konstruksi', $branches[0]->id_branch);
        $this->pid('PID-OPS', 'Operation', $branches[0]->id_branch);
        $this->asRole('Admin Konstruksi');
        $this->reference('26KT03R501-0001', 'Konstruksi', $branches[0]->name)->assertOk()->assertJsonPath('status', 'branch_unmapped')->assertJsonMissingPath('name')->assertJsonMissingPath('available');
        $this->reference('pid-mapped', 'Konstruksi', $branches[0]->name)->assertOk()->assertJsonPath('name', $mapped['name'])->assertJsonPath('available.TOTAL', 50000);
        $this->reference('PID-MAPPED', 'Konstruksi', $branches[1]->name)->assertOk()->assertJsonPath('status', 'branch_conflict')->assertJsonMissingPath('name')->assertJsonMissingPath('available');
        $this->reference('PID-OPS', 'Konstruksi', $branches[0]->name)->assertOk()->assertJsonPath('status', 'unit_conflict')->assertJsonMissingPath('name');
        $this->reference('UNKNOWN', 'Konstruksi', $branches[0]->name)->assertOk()->assertJsonPath('status', 'not_found')->assertJsonMissingPath('available');
        $this->reference('PID-MAPPED', 'Operation', $branches[0]->name)->assertForbidden();
        $this->reference('PID-MAPPED', 'Konstruksi', 'OUTSIDE')->assertForbidden();
        $this->asRole('Finance');
        $this->reference('PID-MAPPED', 'Konstruksi', $branches[0]->name)->assertForbidden();
    }

    public function test_mapping_is_atomic_audited_actor_bound_replay_and_master_import_version_conflict(): void
    {
        $one = $this->pid('ONE');
        $two = $this->pid('TWO');
        $branch = (int) DB::table('branches')->value('id_branch');
        $this->asRole('Finance');
        $uuid = (string) Str::uuid();
        $this->map([$one['id_budget_pid'], $two['id_budget_pid']], $branch, 'Konstruksi', 0, $uuid)->assertOk()->assertJsonPath('mapped', 2);
        $this->map([$two['id_budget_pid'], $one['id_budget_pid']], $branch, 'Konstruksi', 0, $uuid)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('budget_pid_branch_changes', 1);
        $this->assertDatabaseCount('budget_pid_items', 12);
        $this->assertDatabaseHas('budget_pids', ['id_budget_pid' => $one['id_budget_pid'], 'branch_id' => $branch, 'last_import_id' => $one['last_import_id']]);
        $this->map([$one['id_budget_pid']], $branch, 'Konstruksi', 0)->assertConflict();
        $this->asRole();
        $this->map([$one['id_budget_pid'], $two['id_budget_pid']], $branch, 'Konstruksi', 0, $uuid)->assertConflict();
        $this->getJson('/api/budgeting-pid/branch?unit=Konstruksi')->assertOk()->assertJsonCount(2, 'rows.0.after_data');
        $third = $this->pid('THREE');
        $this->map([$third['id_budget_pid'], (string) Str::uuid()], $branch)->assertUnprocessable();
        $this->assertDatabaseHas('budget_pids', ['id_budget_pid' => $third['id_budget_pid'], 'branch_id' => null]);
        $this->asRole('Admin Operation');
        $this->map([$third['id_budget_pid']], $branch)->assertForbidden();
    }

    public function test_new_pp_name_is_canonical_unknown_is_manual_and_saved_pp_does_not_auto_adopt_master(): void
    {
        $s = $this->seedWorkflow('D');
        $p = $s['projects'][0];
        $branch = (int) DB::table('branches')->where('name', $p['branch'])->value('id_branch');
        // Seed helper uses uppercase Branch in its returned state.
        if (! $branch) {
            $branch = (int) DB::table('branches')->whereRaw('UPPER(name) = ?', [strtoupper($p['branch'])])->value('id_branch');
        }
        $master = $this->pid('NEW-PID', 'Konstruksi', $branch);
        $this->asRole('Admin Konstruksi');
        $p['id'] = $p['rootId'] = 'pp-pid-reference-test';
        $p['unit'] = 'Konstruksi';
        $p['parentId'] = null;
        $p['sourceBapp'] = null;
        $p['projectCode'] = 'NEW-PID';
        $p['name'] = 'Forged name';
        $p['boq'] = $p['schedule'] = $p['approverUserIds'] = [];
        unset($p['planBudget'], $p['riskLevel'], $p['signatureRows'], $p['boqUpload']);
        $p['budgetPidSelection'] = ['pid' => 'NEW-PID', 'unit' => 'Konstruksi', 'branch' => $p['branch'], 'importId' => $master['last_import_id']];
        $send = fn ($project) => $this->postJson('/api/prototype/commands', ['command' => 'saveProjectBundle', 'args' => [$project, false], 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()]);
        $response = $send($p);
        $this->assertSame(200, $response->status(), $response->getContent());
        $saved = collect($response->json('state.projects'))->firstWhere('id', $p['id']);
        $this->assertSame($master['name'], $saved['name']);
        $this->assertArrayNotHasKey('budgetPidSelection', $saved);
        DB::table('budget_pids')->where('id_budget_pid', $master['id_budget_pid'])->update(['name' => 'Master changed', 'last_import_id' => $this->pid('OTHER')['last_import_id']]);
        $send($saved)->assertOk();
        $this->assertSame($saved['name'], collect($this->getJson('/api/prototype/state')->json('state.projects'))->firstWhere('id', $saved['id'])['name']);
        $send($p)->assertConflict();
        $p['id'] = $p['rootId'] = 'pp-manual-pid';
        $p['projectCode'] = 'UNKNOWN-PID';
        $p['name'] = 'Nama manual';
        unset($p['budgetPidSelection']);
        $r = $send($p)->assertOk();
        $manual = collect($r->json('state.projects'))->firstWhere('id', $p['id']);
        $this->assertSame('Nama manual', $manual['name']);
        $this->pid('OTHER-UNIT', 'Operation', $branch);
        $manual['projectCode'] = 'OTHER-UNIT';
        $send($manual)->assertUnprocessable();
        $this->asRole();
        $otherBranch = (int) DB::table('branches')->where('id_branch', '<>', $branch)->value('id_branch');
        $this->map([$master['id_budget_pid']], $otherBranch)->assertUnprocessable();
    }

    public function test_reference_is_constant_query_count_and_mapping_scopes_bulk_without_workflow_writes(): void
    {
        $branch = (int) DB::table('branches')->value('id_branch');
        $name = DB::table('branches')->where('id_branch', $branch)->value('name');
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = $this->pid('PID-'.$i, 'Operation');
        }
        $this->asRole('Finance');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->map(array_column($rows, 'id_budget_pid'), $branch, 'Operation')->assertOk()->assertJsonPath('mapped', 100);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertLessThanOrEqual(14, $count);
        $this->asRole('Admin Operation');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->reference('PID-0', 'Operation', $name)->assertOk()->assertJsonPath('available.Material', 50000);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertLessThanOrEqual(6, count($queries));
        foreach ($queries as $q) {
            $this->assertDoesNotMatchRegularExpression('/project_plans|private_data|prototype_workspaces|payment_terms/', $q['query']);
        }
        fwrite(STDOUT, "\nBudget PID reference: ".count($queries)." HTTP queries; mapping100: {$count}.\n");
    }

    public function test_finance_branch_scope_filters_list_and_blocks_cross_branch_mapping_and_import(): void
    {
        $branches = DB::table('branches')->orderBy('id_branch')->limit(2)->get();
        $outside = $this->pid('OUTSIDE', 'Konstruksi', $branches[1]->id_branch);
        $this->pid('UNMAPPED');
        $actor = $this->asRole('Finance');
        app(AccountAccess::class)->assign($actor, 'FINANCE', [$branches[0]->id_branch]);
        $actor->refresh();
        $this->getJson('/api/budgeting-pid?unit=Konstruksi')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.pid', 'UNMAPPED');
        $this->map([$outside['id_budget_pid']], (int) $branches[0]->id_branch)->assertForbidden();
        try {
            app(BudgetPidMaster::class)->import((int) $actor->getKey(), ['OUTSIDE' => []], ['unit' => 'Konstruksi', 'stage' => 'preview'], str_repeat('a', 64), 'test.xlsx');
            $this->fail('Outside-Branch import must fail before row processing.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('budget_pid_branch_changes', 0);
        $this->assertDatabaseHas('budget_pids', ['id_budget_pid' => $outside['id_budget_pid'], 'branch_id' => $branches[1]->id_branch]);
    }

    public function test_mapping_migration_refuses_to_drop_saved_mapping_or_history(): void
    {
        $pid = $this->pid();
        $this->map([$pid['id_budget_pid']], (int) DB::table('branches')->value('id_branch'))->assertOk();
        $migration = require database_path('migrations/2026_11_24_000002_map_budget_pid_branches.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback ditolak');
        $migration->down();
    }
}
