<?php

namespace Tests\Feature;

use App\Services\SpkSigner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SpkSignerTest extends TestCase
{
    private function fixture(): array
    {
        return [['spks' => [], 'deals' => [['id' => 'deal', 'projectId' => 'pp']], 'projects' => [['id' => 'pp', 'workflowVersion' => 3, 'approverUserIds' => ['Head of PMO' => 'head'], 'approvals' => [['role' => 'Head of PMO', 'userId' => 'head', 'decision' => 'Setujui', 'actorName' => 'Head snapshot QA', 'actorNik' => '000123']]]], 'users' => [['id' => 'other', 'role' => 'Head of PMO', 'name' => 'Wrong Head', 'nik' => '999'], ['id' => 'head', 'role' => 'Head of PMO', 'name' => 'Renamed Head', 'nik' => '456']]], ['id' => 'spk', 'dealId' => 'deal', 'signer' => 'FORGED', 'position' => 'FORGED', 'signerNik' => 'FORGED', 'signerUserId' => 'other']];
    }

    public function test_selected_head_overrides_forged_identity_and_position_with_no_queries_or_state_changes(): void
    {
        [$state, $spk] = $this->fixture();
        $before = $state;
        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolved = SpkSigner::sync($state, $spk, true);
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame('Head snapshot QA', $resolved['signer']);
        $this->assertSame('000123', $resolved['signerNik']);
        $this->assertSame('head', $resolved['signerUserId']);
        $this->assertSame('HEAD OF PMO AREA JAWA BALI', $resolved['position']);
        $this->assertSame('pp-head-pmo', $resolved['signerSource']);
        $this->assertSame($before, $state);
    }

    public function test_continuation_uses_its_own_assignment_and_does_not_trust_other_identity_snapshots(): void
    {
        [$state, $spk] = $this->fixture();
        $child = $state['projects'][0];
        $child['id'] = 'child';
        $child['approverUserIds']['Head of PMO'] = 'other';
        $child['signatureUsers']['Head of PMO'] = ['userId' => 'head', 'name' => 'Wrong snapshot', 'nik' => '888'];
        $state['projects'][] = $child;
        $state['deals'][0]['projectId'] = 'child';
        $resolved = SpkSigner::sync($state, $spk, true);
        $this->assertSame('Wrong Head', $resolved['signer']);
        $this->assertSame('999', $resolved['signerNik']);
        $this->assertSame('other', $resolved['signerUserId']);
    }

    public function test_missing_nik_remains_empty_and_missing_head_allows_draft_but_refuses_issuance(): void
    {
        [$state, $spk] = $this->fixture();
        unset($state['projects'][0]['approvals'][0]['actorNik'], $state['users'][1]['nik']);
        $this->assertSame('', SpkSigner::sync($state, $spk, true)['signerNik']);
        unset($state['projects'][0]['approverUserIds']);
        $this->assertSame('', SpkSigner::sync($state, $spk)['signer']);
        $this->expectException(ValidationException::class);
        SpkSigner::sync($state, $spk, true);
    }

    public function test_issued_snapshots_and_legacy_documents_keep_real_saved_identities_without_invented_nik(): void
    {
        [$state, $spk] = $this->fixture();
        foreach (['Terbit', 'Sah'] as $status) {
            $state['spks'] = [array_merge($spk, ['status' => $status, 'signer' => 'Saved Head', 'signerNik' => '000007', 'position' => 'Saved position'])];
            $resolved = SpkSigner::sync($state, array_merge($spk, ['signerNik' => 'fake']), true);
            $this->assertSame('Saved Head', $resolved['signer']);
            $this->assertSame('000007', $resolved['signerNik']);
            $this->assertSame('Saved position', $resolved['position']);
        }
        unset($state['spks'][0]['signerNik']);
        $this->assertArrayNotHasKey('signerNik', SpkSigner::sync($state, $spk));
        $state['spks'] = [];
        $state['projects'][0]['workflowVersion'] = 2;
        $this->assertSame($spk['signer'], SpkSigner::sync($state, $spk)['signer']);
        $this->assertArrayNotHasKey('signerNik', SpkSigner::sync($state, $spk));
    }
}
