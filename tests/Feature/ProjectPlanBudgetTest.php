<?php

namespace Tests\Feature;

use App\Services\ProjectPlanBudget;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectPlanBudgetTest extends TestCase
{
    public function test_selected_owner_revenue_and_automatic_nonstock_count_material_cost_once(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/pp-budget-vector.json')), true);
        $p = $fixture['project'];
        $p['planBudget']['materials'] = [['id' => 'forged-material', 'name' => 'Forged', 'unit' => 'bad', 'qty' => 999, 'price' => 999]];
        $p['planBudget']['marginPolicy'] = ProjectPlanBudget::MATERIAL_REVENUE_POLICY;
        DB::enableQueryLog();
        DB::flushQueryLog();
        $current = ProjectPlanBudget::apply($p, $fixture['owners'], true, true);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        foreach ($fixture['expectedSelectedOwnerRevenue'] as $key => $value) {
            $this->assertEquals($value, $current['planBudgetTotals'][$key], $key);
        }
        $this->assertSame(ProjectPlanBudget::OWNER_MATERIAL_POLICY, $current['planBudget']['marginPolicy']);
        $this->assertSame([['id' => 'material-mandor-1', 'sourceRowId' => 'boq-qa', 'name' => 'Material dan Jasa QA', 'unit' => 'Pcs', 'qty' => 4.0, 'price' => 10.0]], $current['planBudget']['materials']);
        $this->assertEquals($fixture['selectedMaterialUsage'], $current['planBudget']['usage']);
        $this->assertEquals(['mandor' => 100, 'material' => 40, 'bop' => 30.02, 'permit' => 7.03], $current['costs']);
        $this->assertEquals($current, ProjectPlanBudget::apply($current, $fixture['owners'], true));
        foreach (['qtyM', 'priceM'] as $key) {
            $empty = $current;
            $empty['planBudget']['mandor'][0][$key] = 0;
            $result = ProjectPlanBudget::apply($empty, $fixture['owners'], true, true);
            $this->assertSame([], $result['planBudget']['materials']);
            $this->assertEquals(2000, $result['planBudgetTotals']['revenue']);
            $this->assertEquals(0, $result['planBudgetTotals']['material']);
            $this->assertEquals(130.02, $result['planBudgetTotals']['cogs']);
        }
        // A positive-volume, zero-price default is not an Admin-selected material item.
        $owner = $fixture['owners'];
        $owner[] = ['rowId' => 'unselected', 'name' => 'Not selected', 'unit' => 'Pcs', 'm' => 1000, 's' => null, 'qm' => 10, 'qs' => 0];
        $current['planBudget']['mandor'][] = ['rowId' => 'unselected', 'qtyM' => 10, 'qtyS' => 0, 'priceM' => 0, 'priceS' => 0];
        $next = ProjectPlanBudget::apply($current, $owner, true, true);
        $this->assertCount(1, $next['planBudget']['materials']);
        $this->assertEquals(2400, $next['planBudgetTotals']['revenue']);
        $this->assertEquals(11000, $next['planBudgetTotals']['ownerM']);
    }

    public function test_automatic_materials_support_all_500_boq_rows_without_manual_row_cap_or_queries(): void
    {
        $p = ['costs' => ['permit' => 0], 'riskLevel' => 'SEDANG', 'planBudget' => ['mandor' => []]];
        $owners = [];
        for ($i = 0; $i < 500; $i++) {
            $owners[] = ['rowId' => 'boq-'.$i, 'name' => 'Material '.$i, 'unit' => 'Pcs', 'm' => 100, 's' => null, 'qm' => 1, 'qs' => 0];
            $p['planBudget']['mandor'][] = ['rowId' => 'boq-'.$i, 'qtyM' => 1, 'priceM' => 50, 'qtyS' => 0, 'priceS' => 0];
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = ProjectPlanBudget::apply($p, $owners, true, true);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(500, $result['planBudget']['materials']);
        $this->assertCount(500, array_unique(array_column($result['planBudget']['materials'], 'id')));
        $this->assertEquals(50000, $result['planBudgetTotals']['revenue']);
        $this->assertEquals(25000, $result['planBudgetTotals']['cogs']);
        // Monetary maintenance has saved material metadata even without a current master lookup.
        foreach ($owners as &$owner) {
            unset($owner['name'], $owner['unit']);
        }
        $this->assertSame($result, ProjectPlanBudget::apply($result, $owners, true));
    }

    public function test_material_revenue_policy_uses_mandor_material_plus_owner_service_and_preserves_costs(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/pp-budget-vector.json')), true);
        $before = $fixture['project'];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $legacy = $fixture['project'];
        $legacy['planBudget']['marginPolicy'] = ProjectPlanBudget::MATERIAL_REVENUE_POLICY;
        $current = ProjectPlanBudget::apply($legacy, $fixture['owners'], true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $queries);
        foreach ($fixture['expectedMaterialRevenue'] as $key => $expected) {
            $this->assertEquals($expected, $current['planBudgetTotals'][$key], $key);
        }
        $this->assertSame(ProjectPlanBudget::MATERIAL_REVENUE_POLICY, $current['planBudget']['marginPolicy']);
        $this->assertSame($before, $fixture['project']);
        // Maintenance retains a saved new policy, but does not adopt it for historical records.
        $this->assertEquals(2040, ProjectPlanBudget::apply($current, $fixture['owners'], false)['planBudgetTotals']['revenue']);
        $this->assertEquals(2000, ProjectPlanBudget::apply($fixture['project'], $fixture['owners'], false)['planBudgetTotals']['revenue']);
        $current['planBudget']['mandor'][0]['qtyM'] = 0;
        $this->assertEquals(2000, ProjectPlanBudget::apply($current, $fixture['owners'], false)['planBudgetTotals']['revenue']);
        $owner = $fixture['owners'];
        $owner[0]['s'] = null;
        $owner[0]['qs'] = 0;
        $p = $fixture['project'];
        $p['planBudget']['mandor'][0]['qtyS'] = $p['planBudget']['mandor'][0]['priceS'] = 0;
        $p['planBudget']['marginPolicy'] = ProjectPlanBudget::MATERIAL_REVENUE_POLICY;
        $onlyMaterial = ProjectPlanBudget::apply($p, $owner, true)['planBudgetTotals'];
        $this->assertEquals(40, $onlyMaterial['revenue']);
        $this->assertNotNull($onlyMaterial['margin']);
    }

    public function test_shared_budget_vector_uses_service_revenue_half_up_rounding_and_no_double_counting(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/pp-budget-vector.json')), true);
        $p = $fixture['project'];
        $p['planBudgetTotals'] = ['total' => 999999];
        $p['communication'] = $p['quality'] = $p['closing'] = 'Client tampering';
        $result = ProjectPlanBudget::apply($p, $fixture['owners'], true);
        foreach ($fixture['expected'] as $key => $expected) {
            $this->assertEquals($expected, $result['planBudgetTotals'][$key], $key);
        }
        $this->assertEquals(['mandor' => 140, 'material' => 25.03, 'bop' => 30.02, 'permit' => 7.03], $result['costs']);
        $this->assertSame(2, $result['planBudget']['version']);
        $this->assertEquals($fixture['automaticUsage'], $result['planBudget']['usage']);
        $this->assertStringContainsString('Lensa Pro/Group Chat', $result['communication']);
        $this->assertStringContainsString('Commissioning Test dan Uji Terima', $result['quality']);
        $this->assertStringStartsWith('Project Plan ini disusun dengan sebenar-benarnya', $result['closing']);
        $p['planBudget']['mandor'][0]['qtyM'] = $p['planBudget']['mandor'][0]['qtyS'] = 0;
        $p['planBudget']['materials'] = [];
        $p['planBudget']['permit'] = 0;
        $p['planBudget']['bop'] = [['id' => 'bop-negative-margin', 'description' => 'QA', 'unit' => 'Ls', 'qty' => 1, 'total' => 2246.9]];
        $p['planBudget']['bopLimit'] = 3000;
        $p['planBudget']['usage'] = [['id' => 'usage-negative-margin', 'category' => 'bop', 'term' => '1', 'date' => '2026-11-04', 'amount' => 2246.9]];
        $this->assertEquals(-12.35, ProjectPlanBudget::apply($p, $fixture['owners'], true)['planBudgetTotals']['margin']);
    }

    public function test_zero_service_revenue_has_no_invented_percentage_and_absent_components_remain_excluded(): void
    {
        $p = ['costs' => ['permit' => 0], 'riskLevel' => 'RENDAH'];
        $owner = [['rowId' => 'qa-material-only', 'm' => 0, 's' => null, 'qm' => 1, 'qs' => 0]];
        $result = ProjectPlanBudget::apply($p, $owner, true);
        $this->assertSame(null, $result['planBudgetTotals']['margin']);
        $this->assertEquals(0, $result['planBudgetTotals']['revenue']);
        $this->assertEquals(0, $result['planBudget']['mandor'][0]['qtyS']);
        $this->assertEquals(0, $result['planBudget']['mandor'][0]['priceS']);
        $this->assertCount(4, $result['signatureRows']);
        $this->assertSame([], $result['planBudget']['usage']);
    }

    public function test_invalid_prices_components_source_ids_and_risk_are_rejected_without_mutating_input(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/pp-budget-vector.json')), true);
        $variants = [];
        foreach ([['priceM', 101], ['qtyM', 11], ['priceS', -1], ['priceM', 0.001], ['qtyS', 0.0001]] as [$key, $value]) {
            $p = $fixture['project'];
            $p['planBudget']['mandor'][0][$key] = $value;
            $variants[] = $p;
        }
        $p = $fixture['project'];
        $p['planBudget']['bop'][0]['total'] = -1;
        $variants[] = $p;
        $p = $fixture['project'];
        $p['planBudget']['materials'][0]['price'] = -1;
        $variants[] = $p;
        $p = $fixture['project'];
        $p['planBudget']['materials'][] = $p['planBudget']['materials'][0];
        $variants[] = $p;
        $p = $fixture['project'];
        $p['riskLevel'] = '';
        $variants[] = $p;
        $p = $fixture['project'];
        $p['planBudget']['mandor'][0]['rowId'] = 'unknown';
        $variants[] = $p;
        foreach ($variants as $input) {
            $before = $input;
            try {
                ProjectPlanBudget::apply($input, $fixture['owners'], true);
                $this->fail('Invalid budget must fail');
            } catch (ValidationException $error) {
                $this->assertSame($before, $input);
            }
        }
    }

    public function test_recap_is_server_owned_ignores_stale_client_installments_and_needs_no_dates(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/pp-budget-vector.json')), true);
        foreach ([[], 'invalid client recap', [['id' => 'client', 'category' => '__proto__', 'amount' => -1, 'date' => '2026-02-30', 'term' => '']]] as $usage) {
            $p = $fixture['project'];
            $p['planBudget']['usage'] = $usage;
            $p['planBudget']['version'] = 1;
            $before = $p;
            $result = ProjectPlanBudget::apply($p, $fixture['owners'], true);
            $this->assertSame($before, $p);
            $this->assertSame(2, $result['planBudget']['version']);
            $this->assertEquals($fixture['automaticUsage'], $result['planBudget']['usage']);
            $this->assertEquals(195.05, $result['planBudgetTotals']['cogs']);
            $this->assertEquals(7.03, $result['planBudgetTotals']['usage']['permit']);
            // Header and point IV.5 use these canonical details, never the incoming recap.
            $this->assertEquals(202.08, array_sum(array_column($result['planBudget']['usage'], 'amount')));
            foreach ($result['planBudget']['usage'] as $row) {
                $this->assertArrayNotHasKey('term', $row);
                $this->assertArrayNotHasKey('date', $row);
            }
        }
    }

    public function test_recap_rebuilds_from_source_edits_and_hides_zero_categories_without_changing_margin_rules(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/pp-budget-vector.json')), true);
        $p = $fixture['project'];
        $p['planBudget']['mandor'][0]['priceS'] = 30;
        $p['planBudget']['materials'] = $p['planBudget']['bop'] = [];
        $p['planBudget']['permit'] = 0;
        $result = ProjectPlanBudget::apply($p, $fixture['owners'], true);
        $this->assertEquals([
            ['id' => 'usage-mandor-s', 'category' => 'mandor', 'component' => 's', 'amount' => 150],
            ['id' => 'usage-mandor-m', 'category' => 'mandor', 'component' => 'm', 'amount' => 40],
        ], $result['planBudget']['usage']);
        $this->assertEquals(190, $result['planBudgetTotals']['total']);
        $this->assertEquals(190, $result['planBudgetTotals']['cogs']);
        $this->assertEquals(190, $result['planBudgetTotals']['usage']['mandor']);
        $this->assertEquals(90.5, $result['planBudgetTotals']['margin']);
    }
}
