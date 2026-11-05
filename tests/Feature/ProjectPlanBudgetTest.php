<?php

namespace Tests\Feature;

use App\Services\ProjectPlanBudget;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectPlanBudgetTest extends TestCase
{
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
        $this->assertCount(6, $result['signatureRows']);
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
