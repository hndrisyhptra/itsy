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
        $this->assertVectors('kasbon-pph-round.json', KasbonSettlement::POLICY);
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
        $this->assertEquals(37, KasbonSettlement::quote($d, $t)['net']);
        $this->assertTrue(KasbonSettlement::matches($d, $t, $summary, 37));
        $this->assertEquals(36, KasbonSettlement::due(['kasbon' => $summary], 37));
        $this->assertSame($before, json_encode([$d, $t, $summary]));
        $summary['settlement']['policy'] = KasbonSettlement::POLICY;
        $this->assertFalse(KasbonSettlement::matches($d, $t, $summary, 37));
        $summary['settlement']['policy'] = 'forged-policy';
        $this->assertFalse(KasbonSettlement::matches($d, $t, $summary, 37));
    }
}
