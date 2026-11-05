<?php

namespace Tests\Feature;

use App\Services\ContractNumber;
use App\Services\PrototypeDomain;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContractNumberTest extends TestCase
{
    private function fixture(bool $spk = false): array
    {
        $state = json_decode(file_get_contents(database_path('demo/'.($spk ? 'D' : 'C').'.json')), true);
        $state['schema'] = 3;
        $state['projects'][0]['no'] = '0002/LG.000/TA-03R546/11-2026';
        $doc = $state[$spk ? 'spks' : 'deals'][0];
        $state[$spk ? 'spks' : 'deals'] = [];
        $doc['no'] = 'STALE/CLIENT';
        $doc['status'] = 'Draft';
        $doc['evidence'] = 'Bukti sintetis QA';
        if ($spk) {
            $state['deals'][0]['no'] = 'BAKH/HISTORIS';
        }

        return [$state, $doc];
    }

    public function test_shared_numbers_preserve_pp_padding_branch_period_and_custom_format(): void
    {
        $vectors = json_decode(file_get_contents(base_path('tests/Fixtures/contract-numbering.json')), true);
        foreach ($vectors as $v) {
            $this->assertSame($v['bakh'], ContractNumber::fromProject($v['pp'], '01'));
            $this->assertSame($v['spk'], ContractNumber::fromProject($v['pp'], '02'));
        }
        $this->assertSame(150, strlen(ContractNumber::fromProject(str_repeat('x', 147), '01')));
        foreach ([[' ', '01'], ['valid', '03'], [str_repeat('x', 148), '02']] as [$source, $code]) {
            try {
                ContractNumber::fromProject($source, $code);
                $this->fail('Invalid source/code/length must fail');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('prototype', $e->errors());
            }
        }
    }

    public function test_new_and_draft_documents_ignore_client_number_for_save_generate_and_attestation(): void
    {
        foreach ([false, true] as $spk) {
            [$state, $doc] = $this->fixture($spk);
            $collection = $spk ? 'spks' : 'deals';
            $expected = $spk ? '0002-02/LG.000/TA-03R546/11-2026' : '0002-01/LG.000/TA-03R546/11-2026';
            foreach (['save', 'generate', 'attest'] as $action) {
                foreach ([false, true] as $draft) {
                    $source = $state;
                    if ($draft) {
                        $source[$collection][] = $doc;
                    }
                    $before = $source;
                    $after = app(PrototypeDomain::class)->execute($source, $spk ? 'saveSpk' : 'saveDeal', [$doc, $action])['state'];
                    $this->assertSame($before, $source);
                    $this->assertSame($expected, $after[$collection][0]['no']);
                    $this->assertSame($action === 'save' ? 'Draft' : ($action === 'generate' ? 'Terbit' : 'Sah'), $after[$collection][0]['status']);
                    $this->assertSame($state['projects'], $after['projects']);
                }
            }
        }
    }

    public function test_issued_numbers_stay_frozen_and_sah_documents_remain_immutable_in_schema_three(): void
    {
        foreach ([false, true] as $spk) {
            [$state, $doc] = $this->fixture($spk);
            $collection = $spk ? 'spks' : 'deals';
            $doc['no'] = $spk ? 'SPK/LAMA' : 'BAKH/LAMA';
            $doc['status'] = 'Terbit';
            $state[$collection][] = $doc;
            $input = $doc;
            $input['no'] = 'CLIENT/RENUMBER';
            $input['evidence'] = 'Bukti pengesahan baru';
            $after = app(PrototypeDomain::class)->execute($state, $spk ? 'saveSpk' : 'saveDeal', [$input, 'attest'])['state'];
            $this->assertSame($doc['no'], $after[$collection][0]['no']);
            $this->assertSame('Bukti pengesahan baru', $after[$collection][0]['evidence']);
            try {
                app(PrototypeDomain::class)->execute($after, $spk ? 'saveSpk' : 'saveDeal', [$input, 'save']);
                $this->fail('Sah document must remain locked');
            } catch (ValidationException $error) {
                $this->assertStringContainsString('terkunci', $error->getMessage());
            }
        }
    }

    public function test_another_client_number_cannot_bypass_fixed_code_uniqueness(): void
    {
        foreach ([false, true] as $spk) {
            [$state, $doc] = $this->fixture($spk);
            $command = $spk ? 'saveSpk' : 'saveDeal';
            $after = app(PrototypeDomain::class)->execute($state, $command, [$doc, 'save'])['state'];
            $duplicate = $doc;
            $duplicate['id'] = 'duplicate-document';
            $duplicate['no'] = 'ANOTHER/MANUAL';
            $before = $after;
            try {
                app(PrototypeDomain::class)->execute($after, $command, [$duplicate, 'save']);
                $this->fail('Duplicate fixed number must fail');
            } catch (ValidationException $error) {
                $this->assertSame($before, $after);
                $this->assertStringContainsString($spk ? 'duplikat' : 'sudah digunakan', $error->getMessage());
            }
        }
    }

    public function test_continuation_documents_use_the_child_pp_number_on_the_same_business_project(): void
    {
        foreach ([false, true] as $spk) {
            [$state, $doc] = $this->fixture($spk);
            $root = $state['projects'][0];
            $child = $root;
            $child['id'] = 'pp-number-child';
            $child['parentId'] = $root['id'];
            $child['no'] = '0099/LG.000/TA-03R502/01-2027';
            $rows = $spk ? $state['deals'][0]['rows'] : $doc['rows'];
            foreach ($rows as $row) {
                $child['allocations'][$row['rowId']] = ['m' => $row['qm'], 's' => $row['qs']];
            }
            $state['projects'][] = $child;
            if ($spk) {
                $state['deals'][0]['projectId'] = $child['id'];
            } else {
                $doc['projectId'] = $child['id'];
            }
            $after = app(PrototypeDomain::class)->execute($state, $spk ? 'saveSpk' : 'saveDeal', [$doc, 'save'])['state'];
            $this->assertSame($spk ? '0099-02/LG.000/TA-03R502/01-2027' : '0099-01/LG.000/TA-03R502/01-2027', $after[$spk ? 'spks' : 'deals'][0]['no']);
            $this->assertSame($state['projects'], $after['projects']);
        }
    }
}
