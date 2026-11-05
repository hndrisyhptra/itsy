<?php

namespace Tests\Feature;

use App\Services\BakhSchedule;
use App\Services\PrototypeDomain;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BakhScheduleTest extends TestCase
{
    private function fixture(): array
    {
        $state = json_decode(file_get_contents(database_path('demo/C.json')), true);
        $deal = $state['deals'][0];
        $state['deals'] = [];
        $vector = json_decode(file_get_contents(base_path('tests/Fixtures/bakh-schedule.json')), true)[0];
        foreach ($vector['schedule'] as $i => $row) {
            $state['projects'][0]['schedule'][$i] = $row + ['name' => 'Stage '.($i + 1)];
        }
        $deal['status'] = 'Draft';
        $deal['evidence'] = 'Bukti sintetis pengujian';

        return [$state, $deal];
    }

    public function test_schedule_vectors_match_calendar_boundaries_hk_limits_and_invalid_source_dates(): void
    {
        $vectors = json_decode(file_get_contents(base_path('tests/Fixtures/bakh-schedule.json')), true);
        foreach ($vectors as $v) {
            $this->assertSame($v['expected'], BakhSchedule::period(['schedule' => $v['schedule']]), $v['name']);
        }
    }

    public function test_new_and_old_draft_dates_are_derived_from_saved_pp_for_save_generate_and_attestation(): void
    {
        [$state, $deal] = $this->fixture();
        $deal['start'] = '2000-01-01';
        $deal['end'] = '2100-12-31';
        foreach (['save', 'generate', 'attest'] as $action) {
            foreach ([false, true] as $existingDraft) {
                $source = $state;
                if ($existingDraft) {
                    $source['deals'][] = $deal;
                }
                $after = app(PrototypeDomain::class)->execute($source, 'saveDeal', [$deal, $action])['state'];
                $saved = collect($after['deals'])->firstWhere('id', $deal['id']);
                $this->assertSame('2026-11-30', $saved['start']);
                $this->assertSame('2026-12-13', $saved['end']);
                $this->assertSame($deal['rows'], $saved['rows']);
                $this->assertSame($state['projects'], $after['projects']);
            }
        }
    }

    public function test_issued_dates_are_preserved_and_attestation_cannot_rewrite_them(): void
    {
        [$state, $deal] = $this->fixture();
        $deal['status'] = 'Terbit';
        $deal['start'] = '2026-11-30';
        $deal['end'] = '2026-12-01';
        $state['deals'][] = $deal;
        $input = $deal;
        $input['start'] = '2000-01-01';
        $input['end'] = '2100-12-31';
        $after = app(PrototypeDomain::class)->execute($state, 'saveDeal', [$input, 'attest'])['state'];
        $saved = collect($after['deals'])->firstWhere('id', $deal['id']);
        $this->assertSame('2026-11-30', $saved['start']);
        $this->assertSame('2026-12-01', $saved['end']);
        $this->assertSame('Sah', $saved['status']);
    }

    public function test_missing_pp_schedule_fails_closed_without_inventing_dates(): void
    {
        [$state, $deal] = $this->fixture();
        $state['projects'][0]['schedule'] = [];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Jadwal PP belum lengkap');
        app(PrototypeDomain::class)->execute($state, 'saveDeal', [$deal, 'save']);
    }
}
