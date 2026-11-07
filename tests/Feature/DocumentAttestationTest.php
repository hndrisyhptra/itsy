<?php

namespace Tests\Feature;

use App\Services\PrototypeDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class DocumentAttestationTest extends TestCase
{
    private function fixture(string $scenario): array
    {
        return json_decode(file_get_contents(database_path('demo/'.$scenario.'.json')), true);
    }

    public function test_bakh_and_spk_can_be_saved_issued_and_attested_without_text_evidence_and_without_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach (['C' => ['deals', 'saveDeal'], 'D' => ['spks', 'saveSpk']] as $scenario => [$collection, $command]) {
            foreach (['save', 'generate', 'attest'] as $action) {
                foreach (['missing', '', null, ['forged' => 'not a reference']] as $obsoleteInput) {
                    $state = $this->fixture($scenario);
                    $doc = $state[$collection][0];
                    $state[$collection] = [];
                    unset($doc['evidence']);
                    if ($obsoleteInput !== 'missing') {
                        $doc['evidence'] = $obsoleteInput;
                    }
                    $before = $state;
                    $after = app(PrototypeDomain::class)->execute($state, $command, [$doc, $action])['state'];
                    $this->assertSame('', $after[$collection][0]['evidence']);
                    $this->assertSame(match ($action) {
                        'save' => 'Draft', 'generate' => 'Terbit', 'attest' => 'Sah',
                    }, $after[$collection][0]['status']);
                    $this->assertSame($before, $state);
                }
            }
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $queries, 'Text-reference normalization uses only the loaded aggregate.');
    }

    public function test_existing_draft_and_issued_text_remain_archived_and_cannot_be_replaced_by_stale_clients(): void
    {
        foreach (['C' => ['deals', 'saveDeal'], 'D' => ['spks', 'saveSpk']] as $scenario => [$collection, $command]) {
            foreach (['Draft', 'Terbit'] as $status) {
                $state = $this->fixture($scenario);
                $state[$collection][0]['status'] = $status;
                $state[$collection][0]['evidence'] = 'Historical archive';
                $input = $state[$collection][0];
                $input['evidence'] = 'Stale client replacement';
                $after = app(PrototypeDomain::class)->execute($state, $command, [$input, 'attest'])['state'];
                $this->assertSame('Historical archive', $after[$collection][0]['evidence']);
                $this->assertSame('Sah', $after[$collection][0]['status']);
                try {
                    app(PrototypeDomain::class)->execute($after, $command, [$input, 'attest']);
                    $this->fail('Attested documents must stay locked.');
                } catch (ValidationException $e) {
                    $this->assertStringContainsString('terkunci', $e->getMessage());
                }
            }
        }
    }

    public function test_bapp_ignores_legacy_argument_preserves_archive_and_requires_full_final_approval(): void
    {
        foreach (['', 'Historical archive'] as $archive) {
            $state = $this->fixture('E');
            $b = $state['bapps'][0];
            $state['bapps'][0]['evidence'] = $archive;
            $after = app(PrototypeDomain::class)->execute($state, 'attestBapp', [$b['id'], ' BAPP/QA ', ['obsolete' => 'ignored']])['state'];
            $this->assertSame('Sah', $after['bapps'][0]['status']);
            $this->assertSame('BAPP/QA', $after['bapps'][0]['no']);
            $this->assertSame($archive, $after['bapps'][0]['evidence']);
        }
        foreach (['role', 'number', 'type', 'status', 'approvals'] as $invalid) {
            $state = $this->fixture('E');
            $b = $state['bapps'][0];
            $index = array_search($b['termId'], array_column($state['terms'], 'id'), true);
            match ($invalid) {
                'role' => $state['role'] = 'Finance',
                'type' => $state['terms'][$index]['type'] = 'Parsial',
                'status' => $state['terms'][$index]['status'] = 'Diajukan',
                'approvals' => $state['terms'][$index]['approvals'] = [],
                default => null,
            };
            $before = $state;
            try {
                app(PrototypeDomain::class)->execute($state, 'attestBapp', [$b['id'], $invalid === 'number' ? '' : $b['no']]);
                $this->fail('Attestation must retain guard: '.$invalid);
            } catch (ValidationException $e) {
                $this->assertSame($before, $state);
                $this->assertArrayHasKey('prototype', $e->errors());
            } catch (HttpExceptionInterface $e) {
                $this->assertSame('role', $invalid);
                $this->assertSame(403, $e->getStatusCode());
                $this->assertSame($before, $state);
            }
        }
    }
}
