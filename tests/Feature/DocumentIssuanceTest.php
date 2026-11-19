<?php

namespace Tests\Feature;

use App\Services\DocumentIssuance;
use App\Services\ProjectPlanTemplate;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentIssuanceTest extends TestCase
{
    public function test_shared_chronology_vectors_allow_equality_without_mutation_or_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/document-issuance.json')), true) as $v) {
            $p = ['documentDatePolicy' => DocumentIssuance::POLICY, 'issueDate' => $v['pp'], 'workStartDate' => $v['start'], 'date' => '2099-01-01', 'schedule' => []];
            $d = ['date' => $v['bakh']];
            $spk = ['date' => $v['spk']];
            $before = [$p, $d, $spk];
            $this->assertSame($v['valid'], DocumentIssuance::spkError($p, $d, $spk, true) === '', $v['name']);
            $this->assertSame($before, [$p, $d, $spk]);
        }
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_independent_schedule_is_inclusive_and_ignores_usage_and_forged_stage_dates(): void
    {
        $p = ['documentDatePolicy' => DocumentIssuance::POLICY, 'date' => '2099-01-01', 'workStartDate' => '2028-02-28', 'schedule' => array_map(fn ($days) => ['days' => $days, 'start' => '2000-01-01', 'end' => '2100-01-01'], [2, 0, 3, 1, 1])];
        $result = ProjectPlanTemplate::apply($p);
        $this->assertSame('2099-01-01', $result['date']);
        $this->assertSame('2028-02-28', $result['schedule'][0]['start']);
        $this->assertSame('2028-02-29', $result['schedule'][0]['end']);
        $this->assertSame('', $result['schedule'][1]['start']);
        $this->assertSame('2028-03-01', $result['schedule'][2]['start']);
        $this->assertSame('2028-03-05', $result['schedule'][4]['end']);
        $this->assertSame(7, $result['durationDays']);
        $p['workStartDate'] = '';
        $draft = ProjectPlanTemplate::apply($p, false);
        $this->assertSame(['', '', '', '', ''], array_column($draft['schedule'], 'start'));
        $this->assertSame('', DocumentIssuance::projectError($p));
        $this->assertNotSame('', DocumentIssuance::projectError($p, true));
    }

    public function test_historical_unmarked_dates_are_not_reinterpreted_but_new_documents_on_old_pp_are_checked(): void
    {
        $p = ['date' => '2026-11-01', 'schedule' => [['days' => 2, 'start' => '2026-11-04', 'end' => '2026-11-05']]];
        $d = ['date' => '2026-11-06'];
        $spk = ['date' => '2026-11-07'];
        $this->assertSame('', DocumentIssuance::spkError($p, $d, $spk, true));
        $spk['documentDatePolicy'] = DocumentIssuance::POLICY;
        $this->assertNotSame('', DocumentIssuance::spkError($p, $d, $spk, true));
        $d['date'] = '2026-11-03';
        $spk['date'] = '2026-11-04';
        $this->assertSame('', DocumentIssuance::spkError($p, $d, $spk, true));
        $this->assertArrayNotHasKey('issueDate', $p);
        $this->assertSame('', DocumentIssuance::planning($p)['workStartDate']);
        $this->assertSame('2026-11-04', DocumentIssuance::planning(['schedule' => []], $p)['workStartDate']);
    }
}
