<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\ProjectPlanBudget;
use App\Services\PrototypeDomain;
use App\Support\WholeVolume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class WholeVolumeTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function rejected(array $state, string $command, array $args): void
    {
        $before = json_encode($state);
        try {
            app(PrototypeDomain::class)->execute($state, $command, $args);
            $this->fail('Fractional volume must not be accepted.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('bilangan bulat', $error->getMessage());
            $this->assertSame($before, json_encode($state));
        }
    }

    public function test_scalar_boundaries_reject_fractions_strings_signs_and_nonfinite_without_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ([0, 1, 1000, 1000000000, 10.0] as $value) {
            $this->assertSame((float) $value, WholeVolume::number($value));
        }
        foreach ([0.001, 1.5, 999999999.999, -1, 1000000001, INF, NAN, '1,5', '1.5', '1e3', '1', true, null, []] as $value) {
            try {
                WholeVolume::number($value);
                $this->fail('Invalid volume accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('volume', $error->errors());
            }
        }
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_editable_agreement_inputs_are_whole_even_when_client_forges_status(): void
    {
        $state = json_decode(file_get_contents(database_path('demo/C.json')), true);
        $deal = $state['deals'][0];
        $state['deals'] = [];
        foreach (['qm', 'qs'] as $key) {
            foreach ([0.5, 1.001, '1,5'] as $value) {
                $bad = $deal;
                $bad['status'] = 'Sah';
                $bad['rows'][0][$key] = $value;
                foreach (['save', 'generate', 'attest'] as $action) {
                    $this->rejected($state, 'saveDeal', [$bad, $action]);
                }
            }
        }
        $this->assertSame('Draft', app(PrototypeDomain::class)->execute($state, 'saveDeal', [$deal, 'save'])['state']['deals'][0]['status']);
        // Already-issued snapshots retain their original fractional ledger and ignore client edits.
        $deal['status'] = 'Terbit';
        $deal['rows'][0]['qm'] = 1.5;
        $state['deals'] = [$deal];
        $input = $deal;
        $input['rows'][0]['qm'] = 999;
        $result = app(PrototypeDomain::class)->execute($state, 'saveDeal', [$input, 'attest'])['state'];
        $this->assertEquals(1.5, $result['deals'][0]['rows'][0]['qm']);
        $this->assertSame('Sah', $result['deals'][0]['status']);
    }

    public function test_actual_release_and_continuation_inputs_reject_fraction_without_rounding(): void
    {
        $domain = app(PrototypeDomain::class);
        $state = json_decode(file_get_contents(database_path('demo/D.json')), true);
        $result = $domain->execute($state, 'createTerm', [$state['spks'][0]['id']]);
        $state = $result['state'];
        $term = $state['terms'][0];
        $row = $state['deals'][0]['rows'][0]['rowId'];
        foreach (['m', 's'] as $component) {
            foreach ([0.001, 1.5, '1,5'] as $value) {
                $bad = $term;
                $bad['actuals'][$row] = ['m' => 1, 's' => 1];
                $bad['actuals'][$row][$component] = $value;
                foreach ([false, true] as $submit) {
                    $this->rejected($state, 'saveTerm', [$bad, $submit]);
                }
            }
        }
        $term['actuals'][$row] = ['m' => 1, 's' => 1];
        $this->assertSame('Draft', $domain->execute($state, 'saveTerm', [$term, false])['state']['terms'][0]['status']);
        $state = json_decode(file_get_contents(database_path('demo/E.json')), true);
        $bapp = $state['bapps'][0];
        $state = $domain->execute($state, 'attestBapp', [$bapp['id'], $bapp['no']])['state'];
        $this->rejected($state, 'releaseVolume', [$bapp['id'], [$row => ['m' => 0.5, 's' => 0]]]);
        $state = $domain->execute($state, 'releaseVolume', [$bapp['id'], [$row => ['m' => 10, 's' => 0]]])['state'];
        $this->rejected($state, 'createContinuation', [$bapp['id'], 'PP/WHOLE/QA', [$row => ['m' => 0.5, 's' => 0]], 'QA']);
    }

    public function test_new_budget_quantities_are_whole_but_historical_maintenance_keeps_fraction(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/pp-budget-vector.json')), true);
        $project = $fixture['project'];
        $project['planBudget']['materials'][0]['qty'] = 1;
        foreach (['mandor', 'materials', 'bop'] as $table) {
            $bad = $project;
            $bad['planBudget'][$table][0][$table === 'mandor' ? 'qtyM' : 'qty'] = 1.5;
            try {
                ProjectPlanBudget::apply($bad, $fixture['owners'], false, true);
                $this->fail('Fractional editable budget accepted.');
            } catch (ValidationException $error) {
                $this->assertStringContainsString('bilangan bulat', $error->getMessage());
            }
        }
        $before = $fixture['project'];
        $result = ProjectPlanBudget::apply($before, $fixture['owners'], false);
        $this->assertEquals(2.5, $result['planBudget']['materials'][0]['qty']);
        $this->assertSame($before, $fixture['project']);
    }

    public function test_http_fraction_rejection_preserves_sql_revision_ledger_receipts_and_audit(): void
    {
        $this->seedWorkflow('D');
        $this->asRole('Admin Konstruksi');
        $boot = $this->getJson('/api/prototype/state')->assertOk()->json();
        $this->postJson('/api/prototype/commands', ['command' => 'createTerm', 'args' => [$boot['state']['spks'][0]['id']], 'revision' => $boot['revision'], 'request_id' => (string) Str::uuid()])->assertOk();
        $boot = $this->getJson('/api/prototype/state')->assertOk()->json();
        $term = $boot['state']['terms'][0];
        $row = $boot['state']['deals'][0]['rows'][0]['rowId'];
        $tables = ['project_plans', 'contracts', 'work_orders', 'payment_terms', 'approval_runs', 'approval_decisions', 'volume_balances', 'volume_movements', 'prototype_audits', 'command_receipts'];
        $snapshot = fn () => array_map(fn ($table) => DB::table($table)->get()->toJson(), $tables);
        $before = $snapshot();
        $revision = PrototypeWorkspace::first()->revision;
        $term['actuals'][$row] = ['m' => 1.5, 's' => 0];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->postJson('/api/prototype/commands', ['command' => 'saveTerm', 'args' => [$term, false], 'revision' => $boot['revision'], 'request_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('volume');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->assertSame($before, $snapshot());
        $this->assertLessThanOrEqual(35, count($queries));
        fwrite(STDERR, "\nWhole-volume rejected HTTP: ".count($queries)." queries.\n");
    }
}
