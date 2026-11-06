<?php

namespace Tests\Feature;

use App\Services\PrototypeDomain;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SphThresholdTest extends TestCase
{
    private function fixture(): array
    {
        $state = json_decode(file_get_contents(database_path('demo/C.json')), true);
        $deal = $state['deals'][0];
        $state['deals'] = [];
        $plans = [];
        foreach ($state['projects'][0]['boq'] as $i => $source) {
            $plans[] = ['rowId' => $source['id'], 'priceM' => ($source['m'] ?? 0) / 2, 'priceS' => ($source['s'] ?? 0) / 2];
            $deal['rows'][$i]['sphM'] = $plans[$i]['priceM'];
            $deal['rows'][$i]['sphS'] = $plans[$i]['priceS'];
            $deal['rows'][$i]['pm'] = $plans[$i]['priceM'];
            $deal['rows'][$i]['ps'] = $plans[$i]['priceS'];
        }
        $state['projects'][0]['planBudget'] = ['version' => 1, 'mandor' => $plans];
        $deal['status'] = 'Draft';
        $deal['evidence'] = 'Bukti sintetis pengujian';

        return [$state, $deal];
    }

    private function reject(array $state, array $deal, string $action, string $message): void
    {
        $before = $state;
        try {
            app(PrototypeDomain::class)->execute($state, 'saveDeal', [$deal, $action]);
            $this->fail('SPH above its PP threshold must fail');
        } catch (ValidationException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
            $this->assertSame($before, $state);
        }
    }

    public function test_each_sph_component_is_capped_by_its_mandor_unit_price_in_every_save_action(): void
    {
        [$state, $deal] = $this->fixture();
        foreach (['sphM' => 'Material', 'sphS' => 'Jasa'] as $key => $component) {
            $bad = $deal;
            $bad['rows'][0][$key] += 0.01;
            $bad['rows'][0][$key === 'sphM' ? 'sphS' : 'sphM'] = 0;
            $bad['status'] = 'Sah'; // Submitted status must not skip policy checks.
            $bad['sphThresholdM'] = $bad['sphThresholdS'] = 999999;
            $bad['planBudget'] = ['mandor' => [['priceM' => 999999, 'priceS' => 999999]]];
            foreach (['save', 'generate', 'attest'] as $action) {
                $this->reject($state, $bad, $action, 'SPH '.$component);
            }
        }
        $saved = app(PrototypeDomain::class)->execute($state, 'saveDeal', [$deal, 'save'])['state']['deals'][0];
        $this->assertSame($deal['rows'][0]['sphM'], $saved['rows'][0]['sphM']);
        $this->assertSame($deal['rows'][0]['sphS'], $saved['rows'][0]['sphS']);
    }

    public function test_zero_threshold_is_enforced_and_missing_plan_row_never_falls_back_to_khs(): void
    {
        [$state, $deal] = $this->fixture();
        $state['projects'][0]['planBudget']['mandor'][0]['priceM'] = 0;
        $deal['rows'][0]['pm'] = 0;
        $deal['rows'][0]['sphM'] = 0.01;
        $this->reject($state, $deal, 'save', 'melebihi threshold');
        $deal['rows'][0]['sphM'] = 0;
        $this->assertSame('Draft', app(PrototypeDomain::class)->execute($state, 'saveDeal', [$deal, 'save'])['state']['deals'][0]['status']);
        array_shift($state['projects'][0]['planBudget']['mandor']);
        $this->reject($state, $deal, 'save', 'belum tersedia');
    }

    public function test_legacy_pp_without_itemized_budget_keeps_its_existing_price_policy(): void
    {
        [$state, $deal] = $this->fixture();
        unset($state['projects'][0]['planBudget']);
        $deal['rows'][0]['sphM'] = $deal['rows'][0]['capM'];
        $this->assertSame('Draft', app(PrototypeDomain::class)->execute($state, 'saveDeal', [$deal, 'save'])['state']['deals'][0]['status']);
    }

    public function test_issued_snapshot_is_preserved_during_attestation_and_subsequent_spk_validation(): void
    {
        [$state, $deal] = $this->fixture();
        $deal['status'] = 'Terbit';
        $deal['rows'][0]['sphM'] = $deal['rows'][0]['capM']; // Issued before the new policy.
        $state['deals'] = [$deal];
        $input = $deal;
        $input['rows'][0]['sphM'] = 999999;
        $result = app(PrototypeDomain::class)->execute($state, 'saveDeal', [$input, 'attest']);
        $saved = $result['state']['deals'][0];
        $this->assertSame('Sah', $saved['status']);
        $this->assertSame($deal['rows'], $saved['rows']);
        $fixture = json_decode(file_get_contents(database_path('demo/D.json')), true);
        $spk = $fixture['spks'][0];
        $spk['dealId'] = $saved['id'];
        $spk['start'] = $saved['start'];
        $spk['end'] = $saved['end'];
        $result = app(PrototypeDomain::class)->execute($result['state'], 'saveSpk', [$spk, 'attest']);
        $this->assertSame('Sah', $result['state']['spks'][0]['status']);
    }
}
