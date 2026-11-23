<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProjectCancellationMigrationTest extends TestCase
{
    public function test_mysql_mariadb_checks_allow_cancelled_without_rewriting_records(): void
    {
        foreach (['8.0.40' => 'DROP CHECK', '10.4.28-MariaDB' => 'DROP CONSTRAINT'] as $version => $drop) {
            $connection = Mockery::mock(Connection::class);
            $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
            $connection->shouldReceive('getDatabaseName')->once()->andReturn('its');
            DB::shouldReceive('connection')->once()->andReturn($connection);
            DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
            DB::shouldReceive('selectOne')->with('SELECT VERSION() AS version')->once()->andReturn((object) ['version' => $version]);
            DB::shouldReceive('statement')->with("ALTER TABLE project_plans {$drop} pp_status, {$drop} pp_budget_positive, ADD CONSTRAINT pp_status CHECK (status IN ('DRAFT','SUBMITTED','RETURNED','REJECTED','APPROVED', 'CANCELLED')), ADD CONSTRAINT pp_budget_positive CHECK (budget > 0 OR (budget = 0 AND status IN ('DRAFT', 'CANCELLED') AND workflow_version = 3))")->once()->andReturn(true);
            DB::shouldReceive('statement')->with("ALTER TABLE approval_runs {$drop} approval_run_status, ADD CONSTRAINT approval_run_status CHECK (status IN ('PENDING','APPROVED','RETURNED','REJECTED', 'CANCELLED'))")->once()->andReturn(true);
            $migration = require database_path('migrations/2026_11_23_000001_allow_cancelled_project_packages.php');
            $migration->up();
        }
        $this->addToAssertionCount(4);
    }

    public function test_zero_header_draft_can_cancel_without_allowing_zero_approved_or_negative_budget(): void
    {
        $migration = require database_path('migrations/2026_11_23_000001_allow_cancelled_project_packages.php');
        DB::statement('CREATE TABLE cancel_budget (budget DECIMAL NOT NULL, status TEXT NOT NULL, workflow_version INTEGER NOT NULL, CHECK ('.$migration->budgetCondition().'))');
        $this->assertTrue(DB::table('cancel_budget')->insert(['budget' => 0, 'status' => 'CANCELLED', 'workflow_version' => 3]));
        foreach ([['budget' => 0, 'status' => 'APPROVED', 'workflow_version' => 3], ['budget' => 0, 'status' => 'CANCELLED', 'workflow_version' => 2], ['budget' => -1, 'status' => 'CANCELLED', 'workflow_version' => 3]] as $bad) {
            try {
                DB::table('cancel_budget')->insert($bad);
                $this->fail('Invalid historical/approved/negative budget must remain blocked.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('CHECK constraint failed', $e->getMessage());
            }
        }
    }

    public function test_rollback_refuses_existing_cancellation_without_mutating_it(): void
    {
        DB::statement('CREATE TABLE project_plans (status TEXT NOT NULL)');
        DB::statement('CREATE TABLE approval_runs (status TEXT NOT NULL)');
        DB::table('project_plans')->insert(['status' => 'CANCELLED']);
        $migration = require database_path('migrations/2026_11_23_000001_allow_cancelled_project_packages.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve cancellation history.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('rollback ditolak', $e->getMessage());
        }
        $this->assertSame('CANCELLED', DB::table('project_plans')->value('status'));
    }
}
