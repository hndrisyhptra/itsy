<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class BudgetPidMigrationRecoveryTest extends TestCase
{
    use AuthenticatesArea3;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'pid_recovery_test', 'database.connections.pid_recovery_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    private function migration(int $stage)
    {
        return require database_path('migrations/'.($stage === 1 ? '2026_11_24_000001_create_budgeting_pid_master.php' : '2026_11_24_000002_map_budget_pid_branches.php'));
    }

    private function unrecord(): void
    {
        DB::table('migrations')->where('migration', 'like', '2026_11_24_00000%')->delete();
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['budget_pid_controls', 'budget_pid_imports', 'budget_pids', 'budget_pid_items', 'budget_pid_changes', 'budget_pid_branch_changes', 'prototype_workspaces', 'project_plans', 'contracts', 'contract_documents', 'payment_terms', 'apm_settlements', 'approval_runs', 'approval_steps', 'approval_decisions', 'volume_balances', 'volume_movements', 'volume_releases'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true)['columns'];
            $query = DB::table($table);
            foreach ($primary as $column) {
                $query->orderBy($column);
            }
            $snapshot[$table] = $query->get()->map(fn ($r) => (array) $r)->all();
        }

        return $snapshot;
    }

    private function populated(): void
    {
        $this->asRole();
        $this->seedWorkflow('D');
        $batch = (string) Str::uuid();
        $pid = (string) Str::uuid();
        $branch = DB::table('branches')->value('id_branch');
        DB::table('budget_pid_controls')->update(['version' => 12]);
        DB::table('budget_pid_imports')->insert(['id_budget_import' => $batch, 'actor_user_id' => auth()->id(), 'unit' => 'Konstruksi', 'filename' => 'synthetic.xlsx', 'sha256' => str_repeat('a', 64), 'preview_hash' => str_repeat('b', 64), 'version' => 11, 'summary' => '{}', 'created_at' => now()]);
        DB::table('budget_pids')->insert(['id_budget_pid' => $pid, 'pid' => 'RECOVERY-TEST', 'name' => 'Nama dipertahankan', 'unit' => 'Konstruksi', 'branch_id' => $branch, 'last_import_id' => $batch, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('budget_pid_items')->insert(['id_budget_item' => (string) Str::uuid(), 'budget_pid_id' => $pid, 'category' => 'TOTAL', 'source_title' => 'RECOVERY-TEST', 'source_row' => 2, 'release' => 100, 'commitment' => 20, 'actual' => 10, 'available' => 70]);
        DB::table('budget_pid_changes')->insert(['id_budget_change' => (string) Str::uuid(), 'import_id' => $batch, 'budget_pid_id' => $pid, 'action' => 'created', 'before_data' => null, 'after_data' => '{}']);
        DB::table('budget_pid_branch_changes')->insert(['id_budget_branch_change' => (string) Str::uuid(), 'actor_user_id' => auth()->id(), 'unit' => 'Konstruksi', 'branch_id' => $branch, 'version' => 12, 'payload_hash' => str_repeat('c', 64), 'before_data' => '[]', 'after_data' => '[]', 'created_at' => now()]);
    }

    public function test_retry_after_only_control_table_survived_preserves_version_and_completes_both_migrations(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 3, '--force' => true])->assertSuccessful();
        Schema::create('budget_pid_controls', function (Blueprint $t) {
            $t->unsignedTinyInteger('id_budget_control')->primary();
            $t->unsignedBigInteger('version')->default(0);
        });
        DB::table('budget_pid_controls')->insert(['id_budget_control' => 1, 'version' => 27]);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame(27, (int) DB::table('budget_pid_controls')->value('version'));
        $this->assertTrue(Schema::hasColumn('budget_pids', 'branch_id'));
        $this->assertCount(2, Schema::getForeignKeys('budget_pid_branch_changes'));
        $this->assertSame(3, DB::table('migrations')->where('migration', 'like', '2026_11_24_00000%')->count());
        $this->migration(1)->up();
        $this->migration(2)->up();
        $this->assertSame(27, (int) DB::table('budget_pid_controls')->value('version'));
    }

    public function test_missing_control_seed_after_interruption_is_initialized_once_on_empty_master(): void
    {
        DB::table('budget_pid_controls')->delete();
        $this->unrecord();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->migration(1)->up();
        $this->assertSame(1, DB::table('budget_pid_controls')->count());
        $this->assertSame(0, (int) DB::table('budget_pid_controls')->value('version'));
    }

    public function test_completed_unrecorded_tables_keep_all_master_mapping_and_workflow_data_exactly(): void
    {
        $this->populated();
        $before = $this->snapshot();
        $this->unrecord();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_partial_missing_index_and_foreign_key_are_restored_without_changing_rows(): void
    {
        $this->populated();
        Schema::table('budget_pid_imports', function (Blueprint $t) {
            $t->dropForeign(['actor_user_id']);
            $t->dropIndex(['unit', 'version']);
        });
        Schema::table('budget_pid_branch_changes', fn (Blueprint $t) => $t->dropForeign(['branch_id']));
        Schema::table('budget_pids', function (Blueprint $t) {
            $t->dropForeign(['branch_id']);
            $t->dropIndex(['unit', 'branch_id', 'pid']);
        });
        $before = $this->snapshot();
        $this->unrecord();
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $this->assertCount(1, Schema::getForeignKeys('budget_pid_imports'));
        $this->assertCount(2, Schema::getForeignKeys('budget_pids'));
        $this->assertCount(2, Schema::getForeignKeys('budget_pid_branch_changes'));
        $this->assertTrue(Schema::hasIndex('budget_pid_imports', ['unit', 'version']));
        $this->assertTrue(Schema::hasIndex('budget_pids', ['unit', 'branch_id', 'pid']));
    }

    public function test_branch_column_survived_without_mapping_audit_table_or_constraints(): void
    {
        $this->migration(2)->down();
        Schema::table('budget_pids', fn (Blueprint $t) => $t->unsignedBigInteger('branch_id')->nullable());
        $this->migration(2)->up();
        $this->assertTrue(Schema::hasTable('budget_pid_branch_changes'));
        $this->assertCount(2, Schema::getForeignKeys('budget_pids'));
        $this->assertTrue(Schema::hasIndex('budget_pids', ['unit', 'branch_id', 'pid']));
    }

    public function test_incompatible_existing_table_is_not_skipped_or_reset_and_preflight_creates_nothing(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 3, '--force' => true])->assertSuccessful();
        Schema::create('budget_pid_controls', function (Blueprint $t) {
            $t->unsignedTinyInteger('id_budget_control')->primary();
            $t->string('version'); // A different schema must not be adopted by hasTable alone.
        });
        DB::table('budget_pid_controls')->insert(['id_budget_control' => 1, 'version' => '8']);
        try {
            $this->migration(1)->up();
            $this->fail('Incompatible table must fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('budget_pid_controls.version', $e->getMessage());
        }
        $this->assertFalse(Schema::hasTable('budget_pid_imports'));
        $this->assertSame('8', DB::table('budget_pid_controls')->value('version'));
        $this->assertSame(0, DB::table('migrations')->where('migration', 'like', '2026_11_24_00000%')->count());
    }

    public function test_populated_master_without_control_refuses_to_invent_version(): void
    {
        $this->populated();
        DB::table('budget_pid_controls')->delete();
        $before = $this->snapshot();
        try {
            $this->migration(1)->up();
            $this->fail('Must not reset an existing master version.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('data/riwayat sudah terisi', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }
}
