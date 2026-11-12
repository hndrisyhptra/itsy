<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\ProjectPlanBudget;
use App\Services\PrototypeDomain;
use App\Services\RelationalWorkflowStore;
use App\Support\WholeRupiah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class WholeRupiahTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    public function test_operational_money_columns_are_bigint_and_quantity_source_and_null_semantics_remain(): void
    {
        foreach (WholeRupiah::COLUMNS as $table => $columns) {
            $metadata = collect(Schema::getColumns($table))->keyBy('name');
            foreach ($columns as $name => $nullable) {
                $this->assertStringContainsString('int', $metadata[$name]['type_name']);
                $this->assertSame($nullable, $metadata[$name]['nullable']);
            }
        }
        foreach (['boq_components' => 'quantity', 'term_actuals' => 'actual_quantity', 'boq_import_rows' => 'excel_material_price'] as $table => $column) {
            $type = collect(Schema::getColumns($table))->firstWhere('name', $column)['type_name'];
            $this->assertStringNotContainsString('int', $type);
        }
        $this->assertSame(1030, DB::table('designator_package_prices')->count());
        $this->assertNotEmpty(DB::table('sqlite_master')->where('name', 'partial_settlement_update')->value('sql'));
    }

    public function test_preflight_is_bounded_read_only_and_refuses_fraction_before_any_ddl(): void
    {
        DB::enableQueryLog();
        WholeRupiah::assertIntegralStorage();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(11, $queries); // One exists per table plus the bounded pending-invoice query.
        $this->assertEmpty(array_filter($queries, fn ($q) => ! preg_match('/^select\b/i', $q['query'])));
        $migration = require database_path('migrations/2026_11_11_000002_use_whole_rupiah_columns.php');
        $migration->down();
        $id = DB::table('designator_package_prices')->value('id_designator_package_price');
        DB::table('designator_package_prices')->where('id_designator_package_price', $id)->update(['price' => 12.25]);
        $before = DB::table('sqlite_master')->where('type', 'table')->pluck('sql', 'name')->all();
        try {
            $migration->up();
            $this->fail('Fractional historical money must stop conversion.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('designator_package_prices', $e->getMessage());
        }
        $this->assertEquals(12.25, DB::table('designator_package_prices')->where('id_designator_package_price', $id)->value('price'));
        $this->assertSame($before, DB::table('sqlite_master')->where('type', 'table')->pluck('sql', 'name')->all());
        DB::table('designator_package_prices')->where('id_designator_package_price', $id)->update(['price' => 12]);
        $triggers = DB::table('sqlite_master')->where('type', 'trigger')->pluck('sql', 'name')->all();
        $migration->up();
        $this->assertEquals($triggers, DB::table('sqlite_master')->where('type', 'trigger')->pluck('sql', 'name')->all());
    }

    public function test_whole_policy_rounds_independent_components_and_preserves_historical_cent_calculations(): void
    {
        $deal = ['id' => 'd', 'rows' => [['rowId' => 'r', 'm' => true, 's' => true, 'qm' => .015, 'qs' => .015, 'pm' => 100, 'ps' => 100]]];
        $term = ['spkId' => 's', 'actuals' => ['r' => ['m' => .015, 's' => .015]]];
        $pp = ['boq' => [['qty' => .015, 'm' => 100, 's' => 100]]];
        $domain = app(PrototypeDomain::class)->load(['deals' => [$deal], 'spks' => [['id' => 's', 'dealId' => 'd']]]);
        $this->assertEquals(3, $domain->boqTotal($pp));
        $this->assertEquals(3, $domain->dealTotal($deal));
        $this->assertEquals(3, $domain->termTotal($term));
        $pp['moneyPolicy'] = $deal['moneyPolicy'] = $term['moneyPolicy'] = WholeRupiah::POLICY;
        $this->assertEquals(2, $domain->boqTotal($pp));
        $this->assertEquals(2, $domain->dealTotal($deal));
        $this->assertEquals(2, $domain->termTotal($term));
        $this->assertEquals(-1, WholeRupiah::round('-1.5'));
        $this->assertEquals(1, WholeRupiah::round('1.499999'));
        $vector = json_decode(file_get_contents(base_path('tests/Fixtures/pp-budget-vector.json')), true);
        $legacy = ProjectPlanBudget::apply($vector['project'], $vector['owners'], true);
        $current = ProjectPlanBudget::apply($vector['project'] + ['moneyPolicy' => WholeRupiah::POLICY], $vector['owners'], true);
        $this->assertEquals(195.05, $legacy['planBudgetTotals']['total']);
        $this->assertEquals(195, $current['planBudgetTotals']['total']);
        $this->assertEquals(7, $current['planBudget']['permit']);
    }

    public function test_pending_legacy_bill_is_checked_even_when_sql_approved_amount_is_null(): void
    {
        $s = $this->seedWorkflow('D');
        $d = $s['deals'][0];
        $row = $d['rows'][0];
        $d['rows'] = [array_replace($row, ['m' => true, 's' => false, 'pm' => 1])];
        unset($d['moneyPolicy']);
        $s['deals'][0] = $d;
        $s['terms'] = [['id' => 'pending-cents', 'spkId' => $s['spks'][0]['id'], 'number' => 1, 'type' => 'Parsial', 'status' => 'Diajukan', 'actuals' => [$row['rowId'] => ['m' => .5, 's' => 0]], 'workflowVersion' => 2, 'pmUserId' => $s['projects'][0]['pmUserId'], 'managerUserId' => $s['projects'][0]['managerUserId'], 'approvals' => [], 'history' => [], 'paid' => 0, 'apm' => '', 'apmStatus' => 'Belum Terbit', 'paymentHistory' => []]];
        DB::transaction(fn () => app(RelationalWorkflowStore::class)->write(PrototypeWorkspace::firstOrFail(), $s));
        $this->assertNull(DB::table('payment_terms')->value('approved_amount'));
        $before = DB::table('payment_terms')->value('document_data');
        try {
            WholeRupiah::assertIntegralStorage();
            $this->fail('A fractional pending historical bill must stop conversion.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tagihan snapshot historis', $e->getMessage());
        }
        $this->assertSame($before, DB::table('payment_terms')->value('document_data'));
    }

    public function test_operator_preflight_command_is_read_only_and_reports_a_conflict(): void
    {
        DB::enableQueryLog();
        $this->artisan('area3:check-whole-rupiah')->expectsOutputToContain('Lulus')->assertSuccessful();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertEmpty(array_filter($queries, fn ($q) => ! preg_match('/^select\b/i', $q['query'])));
        $id = DB::table('designator_package_prices')->value('id_designator_package_price');
        DB::table('designator_package_prices')->where('id_designator_package_price', $id)->update(['price' => 12.5]);
        $this->artisan('area3:check-whole-rupiah')->expectsOutputToContain('nominal pecahan')->assertFailed();
        $this->assertEquals(12.5, DB::table('designator_package_prices')->where('id_designator_package_price', $id)->value('price'));
    }
}
