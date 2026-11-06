<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ApprovalStepConstraintTest extends TestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_11_06_000002_allow_project_support_approval_steps.php');
    }

    public function test_old_constraint_reproduces_failure_and_new_constraint_preserves_versioned_role_positions(): void
    {
        $migration = $this->migration();
        foreach (['old' => false, 'new' => true] as $table => $expanded) {
            DB::statement('CREATE TABLE '.$table.'_steps (workflow_version INTEGER NOT NULL, step_no INTEGER NOT NULL, expected_role TEXT NOT NULL, CHECK ('.$migration->checkCondition($expanded).'))');
        }
        $support = ['workflow_version' => 3, 'step_no' => 4, 'expected_role' => 'MGR_PROJECT_SUPPORT'];
        try {
            DB::table('old_steps')->insert($support);
            $this->fail('The old database rule must reproduce the reported failure.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('CHECK constraint failed', $e->getMessage());
        }
        $allowed = [2 => ['PM_KONSTRUKSI', 'MANAGER_KONSTRUKSI'], 3 => ['PM_KONSTRUKSI', 'ADMIN_AREA', 'MGR_DEPLOYMENT', 'MGR_PROJECT_SUPPORT', 'HEAD_OF_PMO', 'FINANCE']];
        foreach ($allowed as $version => $roles) {
            foreach ($roles as $step => $role) {
                $this->assertTrue(DB::table('new_steps')->insert(['workflow_version' => $version, 'step_no' => $step + 1, 'expected_role' => $role]));
            }
        }
        $this->assertTrue(DB::table('new_steps')->insert(['workflow_version' => 3, 'step_no' => 4, 'expected_role' => 'MGR_BUSSINESS_SUPPORT']));
        foreach ([[2, 4, 'MGR_PROJECT_SUPPORT'], [3, 2, 'MGR_PROJECT_SUPPORT'], [3, 4, 'FINANCE'], [3, 4, 'SUPERADMIN'], [3, 7, 'FINANCE'], [4, 4, 'MGR_PROJECT_SUPPORT']] as [$version, $step, $role]) {
            try {
                DB::table('new_steps')->insert(['workflow_version' => $version, 'step_no' => $step, 'expected_role' => $role]);
                $this->fail('An invalid role/version/step must remain rejected.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('CHECK constraint failed', $e->getMessage());
            }
        }
        $this->assertSame(9, DB::table('new_steps')->count());
    }

    public function test_mysql_and_mariadb_replace_the_check_in_one_statement_without_updating_rows(): void
    {
        foreach (['8.0.40' => 'DROP CHECK', '10.4.28-MariaDB' => 'DROP CONSTRAINT'] as $version => $drop) {
            $connection = Mockery::mock(Connection::class);
            $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
            $connection->shouldReceive('getDatabaseName')->once()->andReturn('its');
            DB::shouldReceive('connection')->once()->andReturn($connection);
            DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
            DB::shouldReceive('selectOne')->with('SELECT VERSION() AS version')->once()->andReturn((object) ['version' => $version]);
            $migration = $this->migration();
            DB::shouldReceive('statement')->with('ALTER TABLE approval_steps '.$drop.' two_approval_steps, ADD CONSTRAINT two_approval_steps CHECK ('.$migration->checkCondition(true).')')->once()->andReturn(true);
            $migration->up();
        }
        $this->addToAssertionCount(2);
    }

    public function test_rollback_refuses_to_remove_a_role_already_used_in_approval_history(): void
    {
        DB::statement('CREATE TABLE approval_steps (expected_role TEXT)');
        DB::table('approval_steps')->insert(['expected_role' => 'MGR_PROJECT_SUPPORT']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback ditolak');
        $this->migration()->down();
    }
}
