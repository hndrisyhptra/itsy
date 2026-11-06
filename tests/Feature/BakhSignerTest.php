<?php

namespace Tests\Feature;

use App\Services\BakhSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BakhSignerTest extends TestCase
{
    private function fixture(): array
    {
        return [['projects' => [['id' => 'pp-qa', 'workflowVersion' => 3, 'approverUserIds' => ['Head of PMO' => 'head-selected'], 'approvals' => [['role' => 'Head of PMO', 'userId' => 'head-selected', 'decision' => 'Setujui', 'actorName' => 'Approved Head QA', 'actorNik' => 'QA-001']], 'signatureUsers' => ['Head of PMO' => ['userId' => 'head-selected', 'name' => 'PP Head QA', 'nik' => 'QA-002']]]], 'deals' => [], 'users' => [['id' => 'head-other', 'role' => 'Head of PMO', 'name' => 'Other Head QA', 'nik' => 'QA-OTHER'], ['id' => 'head-selected', 'role' => 'Head of PMO', 'name' => 'Renamed Head QA', 'nik' => 'QA-003']]], ['id' => 'bakh-qa', 'projectId' => 'pp-qa', 'status' => 'Draft', 'signer' => 'FORGED', 'signerNik' => 'FORGED', 'signerUserId' => 'head-other']];
    }

    public function test_selected_pp_approval_identity_overrides_client_fields_without_queries_or_mutation(): void
    {
        [$state, $deal] = $this->fixture();
        $before = $state;
        DB::flushQueryLog();
        DB::enableQueryLog();
        $saved = BakhSigner::sync($state, $deal, true);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame('Approved Head QA', $saved['signer']);
        $this->assertSame('QA-001', $saved['signerNik']);
        $this->assertSame('head-selected', $saved['signerUserId']);
        $this->assertSame('Head of PMO', $saved['signerRole']);
        $this->assertSame('pp-head-pmo', $saved['signerSource']);
        $this->assertSame($before, $state);
    }

    public function test_selected_continuation_uses_its_own_head_and_rejects_mismatched_identity_snapshots(): void
    {
        [$state, $deal] = $this->fixture();
        $child = $state['projects'][0];
        $child['id'] = 'child-qa';
        $child['approverUserIds']['Head of PMO'] = 'head-other';
        $state['projects'][] = $child;
        $deal['projectId'] = 'child-qa';
        $saved = BakhSigner::sync($state, $deal, true);
        $this->assertSame('Other Head QA', $saved['signer']);
        $this->assertSame('QA-OTHER', $saved['signerNik']);
        $this->assertSame('head-other', $saved['signerUserId']);
    }

    public function test_missing_nik_is_not_invented_and_missing_head_cannot_fall_back_to_an_arbitrary_account(): void
    {
        [$state, $deal] = $this->fixture();
        unset($state['projects'][0]['approvals'][0]['actorNik']);
        $state['projects'][0]['signatureUsers']['Head of PMO']['nik'] = '';
        unset($state['users'][1]['nik']);
        $this->assertSame('', BakhSigner::sync($state, $deal, true)['signerNik']);
        unset($state['projects'][0]['approverUserIds']);
        $empty = BakhSigner::sync($state, $deal);
        $this->assertSame('', $empty['signer']);
        $this->assertSame('', $empty['signerNik']);
        $this->expectException(ValidationException::class);
        BakhSigner::sync($state, $deal, true);
    }

    public function test_issued_and_attested_signer_snapshots_survive_pp_and_account_changes(): void
    {
        [$state, $deal] = $this->fixture();
        foreach (['Terbit', 'Sah'] as $status) {
            $state['deals'] = [['id' => $deal['id'], 'projectId' => $deal['projectId'], 'status' => $status, 'signer' => 'Original Signer QA', 'signerUserId' => 'original-head', 'signerNik' => 'QA-ORIGINAL', 'signerRole' => 'Head of PMO', 'signerSource' => 'pp-head-pmo']];
            $saved = BakhSigner::sync($state, $deal, true);
            $this->assertSame('Original Signer QA', $saved['signer']);
            $this->assertSame('QA-ORIGINAL', $saved['signerNik']);
            $this->assertSame('original-head', $saved['signerUserId']);
        }
        unset($state['deals'][0]['signerUserId'], $state['deals'][0]['signerNik'], $state['deals'][0]['signerRole'], $state['deals'][0]['signerSource']);
        $legacy = BakhSigner::sync($state, $deal);
        $this->assertSame('Original Signer QA', $legacy['signer']);
        $this->assertArrayNotHasKey('signerNik', $legacy);
        $this->assertArrayNotHasKey('signerUserId', $legacy);
    }

    public function test_legacy_pp_without_selected_head_retains_manual_name_without_claiming_an_account_identity(): void
    {
        [$state, $deal] = $this->fixture();
        $state['projects'][0]['workflowVersion'] = 2;
        $deal['signer'] = 'Legacy Document Signer QA';
        $saved = BakhSigner::sync($state, $deal, true);
        $this->assertSame($deal['signer'], $saved['signer']);
        $this->assertArrayNotHasKey('signerUserId', $saved);
        $this->assertArrayNotHasKey('signerNik', $saved);
    }
}
