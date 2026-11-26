<?php

namespace Tests\Feature;

use App\Services\AccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class DashboardBudgetTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function master(int $count, string $unit = 'Konstruksi', ?int $branch = null): array
    {
        $actor = $this->asRole();
        $import = (string) Str::uuid();
        DB::table('budget_pid_imports')->insert(['id_budget_import' => $import, 'actor_user_id' => $actor->id_user, 'unit' => $unit, 'filename' => 'synthetic.xlsx', 'sha256' => str_repeat('a', 64), 'preview_hash' => str_repeat('b', 64), 'version' => 1, 'summary' => '{}', 'created_at' => now()]);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::uuid();
            $pid = 'QA-'.$unit.'-'.$import.'-'.$i;
            DB::table('budget_pids')->insert(['id_budget_pid' => $id, 'pid' => $pid, 'name' => 'Dashboard Budget '.$i, 'unit' => $unit, 'branch_id' => $branch, 'last_import_id' => $import, 'created_at' => now(), 'updated_at' => now()]);
            foreach (['TOTAL' => 1000, 'Material' => 600, 'Non Material' => 400, 'Operasional' => 100, 'Tenaga Kerja' => 0, 'Depresiasi' => 0] as $category => $value) {
                DB::table('budget_pid_items')->insert(['id_budget_item' => (string) Str::uuid(), 'budget_pid_id' => $id, 'category' => $category, 'source_title' => $pid, 'source_row' => 2, 'release' => $value, 'commitment' => 0, 'actual' => 0, 'available' => $value]);
            }
            $ids[] = ['id' => $id, 'pid' => $pid];
        }

        return $ids;
    }

    public function test_aggregate_and_detail_are_bounded_read_only_and_do_not_expand_master_access(): void
    {
        $this->withoutVite();
        $this->asRole();
        $branch = (int) DB::table('branches')->where('name', 'SURABAYA')->value('id_branch');
        $this->master(105, 'Konstruksi', $branch);
        $this->master(1); // Unmapped never contributes to a Branch dashboard.
        $counts = [];
        foreach ([false, true] as $detail) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $r = $this->getJson('/api/dashboard/budget?unit=Konstruksi&branch=SURABAYA&detail='.(int) $detail)->assertOk()->assertJsonPath('total', 105)->assertJsonPath('remaining', 105000);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $counts[] = count($queries);
            $this->assertCount($detail ? 20 : 0, $r->json('rows'));
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
            foreach ($queries as $q) {
                $this->assertStringStartsWith('select', strtolower($q['query']));
                $this->assertStringNotContainsString('private_data', $q['query']);
            }
        }
        $this->assertSame($counts[0] + 1, $counts[1]);
        $this->getJson('/api/dashboard/budget?unit=Konstruksi&branch=SURABAYA&detail=1&page=6')->assertOk()->assertJsonCount(5, 'rows');
        $this->getJson('/api/dashboard/budget?unit=Konstruksi&branch=SURABAYA&detail=1&q=not-found')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'rows');
        fwrite(STDERR, "\nDashboard Budget105PID summary/detail HTTP queries: {$counts[0]}/{$counts[1]}.\n");
        $admin = $this->asRole('Admin Konstruksi');
        app(AccountAccess::class)->assign($admin, 'ADMIN_KONSTRUKSI', [$branch]);
        $this->getJson('/api/dashboard/budget?unit=Konstruksi')->assertOk()->assertJsonPath('total', 105);
        $this->getJson('/api/budgeting-pid?unit=Konstruksi')->assertForbidden();
        $this->getJson('/api/dashboard/budget?unit=Operation')->assertForbidden();
        $this->getJson('/api/dashboard/budget?unit=Konstruksi&branch=KUPANG')->assertForbidden();
        foreach (['unit=unknown', 'unit=Konstruksi&page=0', 'unit=Konstruksi&q='.str_repeat('a', 101)] as $query) {
            $this->getJson('/api/dashboard/budget?'.$query)->assertUnprocessable();
        }
        $this->assertDatabaseCount('budget_pids', 106);
        $this->post('/logout');
        $this->getJson('/api/dashboard/budget?unit=Konstruksi')->assertUnauthorized();
    }

    public function test_remaining_uses_reservations_once_retains_paid_consumption_and_has_independent_category_deficits(): void
    {
        $this->seedWorkflow('D');
        $branch = (int) DB::table('branches')->where('name', 'SURABAYA')->value('id_branch');
        $record = $this->master(1, 'Konstruksi', $branch)[0];
        $pp = DB::table('project_plans')->value('id_pp');
        DB::table('budget_pid_allocations')->insert(['id_budget_allocation' => (string) Str::uuid(), 'pp_id' => $pp, 'pid_code' => $record['pid'], 'unit' => 'Konstruksi', 'branch_id' => $branch, 'budget_pid_id' => $record['id'], 'status' => 'reserved', 'material' => 650, 'service' => 150, 'bop' => 20, 'planned_material' => 650, 'planned_service' => 150, 'planned_bop' => 20, 'source_hash' => str_repeat('c', 64), 'source_revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('budget_pid_allocations')->first();
        $this->getJson('/api/dashboard/budget?unit=Konstruksi&branch=SURABAYA&detail=1')->assertOk()->assertJsonPath('remaining', 200)->assertJsonPath('deficit', 1)->assertJsonPath('rows.0.used', 800)->assertJsonPath('rows.0.remaining', 200);
        $this->assertEquals($before, DB::table('budget_pid_allocations')->first());
        DB::table('budget_pid_allocations')->update(['material' => 1000, 'service' => 200]);
        $this->getJson('/api/dashboard/budget?unit=Konstruksi')->assertOk()->assertJsonPath('remaining', -200)->assertJsonPath('exhausted', 1);
        DB::table('budget_pid_allocations')->update(['status' => 'cancelled']);
        $this->getJson('/api/dashboard/budget?unit=Konstruksi')->assertOk()->assertJsonPath('remaining', 1000)->assertJsonPath('deficit', 0);
    }

    public function test_operation_scope_inactive_branches_literal_search_and_missing_schema(): void
    {
        $this->asRole();
        $branch = (int) DB::table('branches')->where('name', 'SURABAYA')->value('id_branch');
        $record = $this->master(1, 'Operation', $branch)[0];
        DB::table('budget_pids')->where('id_budget_pid', $record['id'])->update(['name' => 'Literal_%!']);
        $this->master(1, 'Konstruksi', $branch);
        $this->asRole('Officer 1 Operation');
        $this->getJson('/api/dashboard/budget?unit=Operation&q=Literal_%25!')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('remaining', 1000);
        $this->getJson('/api/dashboard/budget?unit=Konstruksi')->assertForbidden();
        DB::table('branches')->where('id_branch', $branch)->update(['is_active' => false]);
        $this->getJson('/api/dashboard/budget?unit=Operation')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/dashboard/budget?unit=Operation&branch=SURABAYA')->assertForbidden();
        Schema::drop('budget_pid_allocations');
        $this->getJson('/api/dashboard/budget?unit=Operation')->assertStatus(503)->assertSee('Ringkasan Budget PID belum tersedia');
    }
}
