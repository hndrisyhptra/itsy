<?php

namespace Tests\Feature;

use App\Services\PrototypeDomain;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AgreementThresholdTest extends TestCase
{
    private function fixture(): array
    {
        $state = json_decode(file_get_contents(database_path('demo/C.json')), true);
        $deal = $state['deals'][0];
        $state['deals'] = [];
        $plans = [];
        foreach ($state['projects'][0]['boq'] as $i => $source) {
            $plans[] = ['rowId' => $source['id'], 'priceM' => ($source['m'] ?? 0) / 2, 'priceS' => ($source['s'] ?? 0) / 2];
            $deal['rows'][$i]['pm'] = $deal['rows'][$i]['sphM'] = $plans[$i]['priceM'];
            $deal['rows'][$i]['ps'] = $deal['rows'][$i]['sphS'] = $plans[$i]['priceS'];
        }
        $state['projects'][0]['planBudget'] = ['version' => 1, 'mandor' => $plans];
        $deal['status'] = 'Draft';
        $deal['evidence'] = 'Synthetic agreement threshold QA';

        return [$state, $deal];
    }

    private function reject(array $state, array $deal, string $action, string $message): void
    {
        try {
            app(PrototypeDomain::class)->execute($state, 'saveDeal', [$deal, $action]);
            $this->fail('Agreement above the saved component budget must fail.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_material_and_service_agreements_are_independently_capped_for_all_save_actions(): void
    {
        [$state, $deal] = $this->fixture();
        foreach (['pm' => 'Material', 'ps' => 'Jasa'] as $key => $component) {
            $bad = $deal;
            $bad['rows'][0][$key] += 0.01;
            $bad['rows'][0][$key === 'pm' ? 'ps' : 'pm'] = 0;
            $bad['status'] = 'Sah';
            $bad['planBudget'] = ['mandor' => [['priceM' => 999999, 'priceS' => 999999]]];
            $bad['agreementThresholdM'] = $bad['agreementThresholdS'] = 999999;
            foreach (['save', 'generate', 'attest'] as $action) {
                $this->reject($state, $bad, $action, 'Harga kesepakatan '.$component);
                $saved = app(PrototypeDomain::class)->execute($state, 'saveDeal', [$deal, $action])['state']['deals'][0];
                $this->assertSame($deal['rows'][0][$key], $saved['rows'][0][$key]);
            }
        }
    }

    public function test_zero_and_missing_mandor_references_fail_closed_for_selected_components(): void
    {
        [$state, $deal] = $this->fixture();
        $state['projects'][0]['planBudget']['mandor'][0]['priceM'] = 0;
        $deal['rows'][0]['sphM'] = 0;
        $deal['rows'][0]['pm'] = 0.01;
        $this->reject($state, $deal, 'save', 'Harga kesepakatan Material');
        $deal['rows'][0]['pm'] = 0;
        $this->assertSame(0, app(PrototypeDomain::class)->execute($state, 'saveDeal', [$deal, 'save'])['state']['deals'][0]['rows'][0]['pm']);
        array_shift($state['projects'][0]['planBudget']['mandor']);
        $this->reject($state, $deal, 'save', 'belum tersedia');
    }

    public function test_continuation_uses_its_own_mandor_price_instead_of_the_root_plan(): void
    {
        [$state, $deal] = $this->fixture();
        $child = $state['projects'][0];
        $child['id'] = 'child-agreement-threshold';
        $child['parentId'] = $child['rootId'];
        $child['allocations'] = [$deal['rows'][0]['rowId'] => ['m' => 10, 's' => 10]];
        $child['planBudget']['mandor'][0]['priceM'] = 10;
        $state['projects'][] = $child;
        $deal['projectId'] = $child['id'];
        $deal['rows'] = [$deal['rows'][0]];
        $deal['rows'][0]['qm'] = $deal['rows'][0]['qs'] = 1;
        $deal['rows'][0]['sphM'] = 10;
        $deal['rows'][0]['pm'] = 10.01;
        $this->reject($state, $deal, 'save', 'Harga kesepakatan Material');
    }

    public function test_issued_agreement_and_legacy_pp_prices_are_preserved(): void
    {
        [$state, $deal] = $this->fixture();
        $deal['rows'][0]['pm'] = $deal['rows'][0]['capM'];
        $deal['rows'][0]['ps'] = $deal['rows'][0]['capS'];
        $deal['status'] = 'Terbit';
        $state['deals'] = [$deal];
        $input = $deal;
        $input['rows'][0]['pm'] = $input['rows'][0]['ps'] = 999999;
        $saved = app(PrototypeDomain::class)->execute($state, 'saveDeal', [$input, 'attest'])['state']['deals'][0];
        $this->assertSame($deal['rows'], $saved['rows']);
        $state['deals'] = [];
        unset($state['projects'][0]['planBudget']);
        $this->assertSame($deal['rows'], app(PrototypeDomain::class)->execute($state, 'saveDeal', [$deal, 'save'])['state']['deals'][0]['rows']);
    }
}
