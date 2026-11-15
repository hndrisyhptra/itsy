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
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/kasbon-pph.json')), true) as $v) {
            $deal = ['id' => 'd', 'rows' => [['rowId' => 'r', 'm' => true, 's' => true, 'pm' => $v['material'], 'ps' => $v['service']]]];
            $term = ['spkId' => 's', 'moneyPolicy' => 'idr-trunc-v1', 'actuals' => ['r' => ['m' => 1, 's' => 1]]];
            DB::enableQueryLog();
            DB::flushQueryLog();
            $quote = KasbonSettlement::quote($deal, $term);
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
}
