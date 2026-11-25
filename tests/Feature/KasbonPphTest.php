<?php

namespace Tests\Feature;

use App\Services\KasbonSettlement;
use App\Services\PrototypeDomain;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KasbonPphTest extends TestCase
{
    public function test_saved_mandor_service_only_withholding_has_php_ts_parity_and_no_queries(): void
    {
        $this->assertVectors('kasbon-pph.json', KasbonSettlement::LEGACY_POLICY);
    }

    public function test_final_net_rounds_half_up_once_without_rounding_tax_or_cutting_material(): void
    {
        $this->assertVectors('kasbon-pph-round.json', KasbonSettlement::FINAL_ROUND_POLICY);
    }

    public function test_new_quotes_round_total_service_net_only_and_keep_material_intact(): void
    {
        $this->assertVectors('kasbon-pph-service-round.json', KasbonSettlement::SERVICE_NET_ROUND_POLICY);
    }

    public function test_current_quotes_round_only_total_jasa_pph(): void
    {
        $this->assertVectors('kasbon-pph-tax-round.json', KasbonSettlement::POLICY);
    }

    public function test_pph_round_is_once_per_total_jasa_and_preserves_other_values(): void
    {
        $d = ['rows' => array_map(fn ($id) => ['rowId' => $id, 'm' => true, 's' => true, 'pm' => 1, 'ps' => 20], ['a', 'b'])];
        $t = ['moneyPolicy' => 'idr-trunc-v1', 'actuals' => ['a' => ['m' => 1, 's' => 1], 'b' => ['m' => 1, 's' => 1]]];
        $q = KasbonSettlement::quote($d, $t);
        $this->assertEquals(1, $q['withheld'], 'ROUND(40 * 2.5%) once, not two rounded taxes of1.');
        $this->assertEquals(41, $q['net']);
        $d['rows'] = [['rowId' => 'a', 'm' => true, 's' => true, 'pm' => 0.75, 'ps' => 20]];
        unset($t['moneyPolicy']);
        $q = KasbonSettlement::quote($d, $t);
        $this->assertEquals(0.75, $q['material']);
        $this->assertEquals(20, $q['service']);
        $this->assertEquals(1, $q['withheld']);
        $this->assertEquals(19.75, $q['net'], 'The tax step never rounds Material or the final sum.');
    }

    public function test_service_net_rounding_is_once_for_total_jasa_and_never_rounds_material(): void
    {
        $d = ['rows' => array_map(fn ($id) => ['rowId' => $id, 'm' => true, 's' => true, 'pm' => 1, 'ps' => 20], ['a', 'b'])];
        $t = ['moneyPolicy' => 'idr-trunc-v1', 'actuals' => ['a' => ['m' => 1, 's' => 1], 'b' => ['m' => 1, 's' => 1]]];
        $quote = KasbonSettlement::quote($d, $t, KasbonSettlement::SERVICE_NET_ROUND_POLICY);
        $this->assertEquals(1, $quote['withheld']);
        $this->assertEquals(41, $quote['net']);
        // Historical Material cent precision is not rounded with the total either.
        $d['rows'] = [['rowId' => 'a', 'm' => true, 's' => true, 'pm' => 0.75, 'ps' => 20]];
        unset($t['moneyPolicy']);
        $quote = KasbonSettlement::quote($d, $t, KasbonSettlement::SERVICE_NET_ROUND_POLICY);
        $this->assertEquals(0.75, $quote['material']);
        $this->assertEquals(20.75, $quote['net']);
        $this->assertEquals(0, $quote['withheld']);
    }

    private function assertVectors(string $file, string $policy): void
    {
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/'.$file)), true) as $v) {
            $deal = ['id' => 'd', 'rows' => [['rowId' => 'r', 'm' => true, 's' => true, 'pm' => $v['material'], 'ps' => $v['service']]]];
            $term = ['spkId' => 's', 'moneyPolicy' => 'idr-trunc-v1', 'actuals' => ['r' => ['m' => 1, 's' => 1]]];
            DB::enableQueryLog();
            DB::flushQueryLog();
            $quote = KasbonSettlement::quote($deal, $term, $policy);
            $this->assertSame([], DB::getQueryLog());
            DB::disableQueryLog();
            foreach (['material', 'service', 'gross', 'net', 'withheld'] as $key) {
                $this->assertEquals($v[$key], $quote[$key], $v['id'].'/'.$key);
            }
            $this->assertEquals($v['gross'], app(PrototypeDomain::class)->load(['deals' => [$deal], 'spks' => [['id' => 's', 'dealId' => 'd']]])->termTotal($term));
            $summary = ['amount' => $quote['net'], 'settlement' => $quote];
            $this->assertEquals($v['net'], KasbonSettlement::due(['kasbon' => $summary], $v['gross']));
            $this->assertSame($v['net'] > 0, KasbonSettlement::matches($deal, $term, $summary, $v['gross']));
            $summary['settlement']['material']++;
            $this->assertFalse(KasbonSettlement::matches($deal, $term, $summary, $v['gross']));
            $this->assertEquals($v['gross'], KasbonSettlement::due(['kasbon' => ['amount' => $v['gross']]], $v['gross']), 'Historical gross remains unchanged.');
        }
    }

    public function test_old_issued_quote_still_validates_with_old_policy_but_forged_rounding_or_unknown_policy_fails(): void
    {
        $d = ['rows' => [['rowId' => 'r', 'm' => true, 's' => true, 'pm' => 17, 'ps' => 20]]];
        $t = ['moneyPolicy' => 'idr-trunc-v1', 'actuals' => ['r' => ['m' => 1, 's' => 1]]];
        $old = KasbonSettlement::quote($d, $t, KasbonSettlement::LEGACY_POLICY);
        $summary = ['amount' => $old['net'], 'settlement' => $old];
        $before = json_encode([$d, $t, $summary]);
        $this->assertEquals(36, $old['net']);
        $this->assertEquals(36, KasbonSettlement::quote($d, $t)['net']);
        $this->assertEquals(37, KasbonSettlement::quote($d, $t, KasbonSettlement::FINAL_ROUND_POLICY)['net']);
        $this->assertTrue(KasbonSettlement::matches($d, $t, $summary, 37));
        $this->assertEquals(36, KasbonSettlement::due(['kasbon' => $summary], 37));
        $this->assertSame($before, json_encode([$d, $t, $summary]));
        $summary['settlement']['policy'] = KasbonSettlement::FINAL_ROUND_POLICY;
        $this->assertFalse(KasbonSettlement::matches($d, $t, $summary, 37));
        $summary['settlement']['policy'] = 'forged-policy';
        $this->assertFalse(KasbonSettlement::matches($d, $t, $summary, 37));
    }
}
