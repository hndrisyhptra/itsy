<?php

namespace Tests\Feature;

use App\Services\BudgetPidAllocation;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BudgetPidAllocationMysqlTest extends TestCase
{
    public function test_ledger_capacity_and_identity_use_current_locking_reads_in_bounded_pages(): void
    {
        // Actual MySQL query grammar with a recording, PDO-free connection: not a server concurrency test.
        $connection = new class(null, 'its', '', ['driver' => 'mysql']) extends MySqlConnection
        {
            public array $queries = [];

            public function select($query, $bindings = [], $useReadPdo = true): array
            {
                $this->queries[] = [$query, $bindings];
                if (! str_contains($query, '`material`')) {
                    return [];
                }
                $second = str_contains($query, '`id_budget_allocation` >');

                return array_map(fn ($i) => (object) ['id_budget_allocation' => sprintf('%04d', $i), 'material' => '100', 'service' => '200', 'bop' => '10'], $second ? [501] : range(1, 500));
            }
        };
        DB::shouldReceive('table')->with('budget_pid_allocations')->andReturnUsing(fn () => $connection->table('budget_pid_allocations'));
        $service = new BudgetPidAllocation;
        $usage = $service->usage('PID-X', 'Operation', 1, 'own-pp-uuid', true);
        $this->assertSame(['Material' => 50100, 'Non Material' => 100200, 'Operasional' => 5010, 'TOTAL' => 150300], $usage);
        $service->identity('PID-X', 'Operation', 1, 'own-pp-uuid', true);
        $this->assertCount(3, $connection->queries);
        foreach ($connection->queries as [$sql, $bindings]) {
            $this->assertStringContainsString('for update', strtolower($sql));
            $this->assertContains('PID-X', $bindings);
            $this->assertContains('own-pp-uuid', $bindings);
            $this->assertStringNotContainsString('sum(', strtolower($sql));
            $this->assertStringNotContainsString('document_data', $sql);
        }
        $this->assertStringContainsString('limit 500', $connection->queries[0][0]);
        $this->assertStringContainsString('`id_budget_allocation` >', $connection->queries[1][0]);
    }
}
