<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ProjectDraftBudgetConstraintTest extends TestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_11_09_000001_allow_zero_budget_v3_project_drafts.php');
    }

    public function test_zero_is_allowed_only_for_v3_drafts_and_negative_values_are_always_rejected(): void
    {
        DB::statement('CREATE TABLE budget_checks (budget DECIMAL NOT NULL, status TEXT NOT NULL, workflow_version INTEGER NOT NULL, CHECK ('.$this->migration()->checkCondition().'))');
        foreach ([1, 2, 3] as $version) {
            foreach (['DRAFT', 'SUBMITTED', 'RETURNED', 'REJECTED', 'APPROVED'] as $status) {
                $this->assertTrue(DB::table('budget_checks')->insert(['budget' => 1, 'status' => $status, 'workflow_version' => $version]));
                foreach ([0, -1] as $budget) {
                    $row = ['budget' => $budget, 'status' => $status, 'workflow_version' => $version];
                    if ($version === 3 && $status === 'DRAFT' && $budget === 0) {
                        $this->assertTrue(DB::table('budget_checks')->insert($row));
                    } else {
                        try {
                            DB::table('budget_checks')->insert($row);
                            $this->fail('A zero submitted/historical or negative budget must remain rejected.');
                        } catch (QueryException $e) {
                            $this->assertStringContainsString('CHECK constraint failed', $e->getMessage());
                        }
                    }
                }
            }
        }
    }

    public function test_both_mysql_dialects_replace_the_check_atomically_without_rewriting_rows(): void
    {
        foreach (['8.0.40' => 'DROP CHECK', '10.4.28-MariaDB' => 'DROP CONSTRAINT'] as $version => $drop) {
            $connection = Mockery::mock(Connection::class);
            $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
            $connection->shouldReceive('getDatabaseName')->once()->andReturn('its');
            DB::shouldReceive('connection')->once()->andReturn($connection);
            DB::shouldReceive('getDriverName')->once()->andReturn('mysql');
            DB::shouldReceive('selectOne')->with('SELECT VERSION() AS version')->once()->andReturn((object) ['version' => $version]);
            $migration = $this->migration();
            DB::shouldReceive('statement')->with('ALTER TABLE project_plans '.$drop.' pp_budget_positive, ADD CONSTRAINT pp_budget_positive CHECK ('.$migration->checkCondition().')')->once()->andReturn(true);
            $migration->up();
        }
        $this->addToAssertionCount(2);
    }

    public function test_rollback_preserves_existing_zero_budget_drafts(): void
    {
        DB::statement('CREATE TABLE project_plans (budget DECIMAL)');
        DB::table('project_plans')->insert(['budget' => 0]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback ditolak');
        $this->migration()->down();
    }
}
