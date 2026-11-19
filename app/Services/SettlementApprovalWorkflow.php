<?php

namespace App\Services;

use Illuminate\Support\Str;

trait SettlementApprovalWorkflow
{
    private bool $issuingSettlementKasbon = false;

    public static function usesSettlementPayment(array $term): bool
    {
        return ($term['paymentFlow'] ?? '') === 'kasbon-settlement-v2' && ($term['workflowVersion'] ?? 1) === 3;
    }

    private function createSettlementTerm(string $spk, ?string $id): string
    {
        $id = $this->createTerm($spk, $id);
        $term = $this->record('terms', $id);
        if (($term['workflowVersion'] ?? 1) === 3) {
            $term['paymentFlow'] = 'kasbon-settlement-v2';
            $term['completionFlow'] = 'bapp-each-payment-v2';
            $term['barpFlow'] = 'attest-before-bapp-v1';
            $this->put('terms', $term);
        }

        return $id;
    }

    private function submitSettlementKasbon(string $id, array $summary): null
    {
        $this->admin();
        $t = $this->record('terms', $id);
        if (! self::usesSettlementPayment($t) || ! $this->canReviseSubmission($t) || $t['paid'] > 0 || $t['apmStatus'] === 'Valid') {
            $this->fail('Kasbon baru hanya dapat diajukan bersama draf pembayaran yang belum dibayar.');
        }
        if (! Str::isUuid($summary['id'] ?? '') || ! $this->kasbonSummaryMatches($t, $summary)) {
            $this->fail('Kasbon harus sama dengan nilai tagihan termin.');
        }
        $this->assertBappBeforePayment($t);
        $project = $this->record('projects', $this->record('deals', $this->record('spks', $t['spkId'])['dealId'])['projectId']);
        $user = $this->approver($summary['settlementUserId'] ?? '', 'Mgr Finance & Settlement');
        if (isset($user['workUnits']) && ! in_array($project['unit'] === 'Operation' ? 'Operation' : 'Konstruksi', $user['workUnits'], true)) {
            $this->fail('Unit kerja Mgr Finance & Settlement tidak sesuai PP.');
        }
        if (isset($user['branches']) && ! in_array($project['branch'], $user['branches'], true)) {
            $this->fail('Mgr Finance & Settlement harus ditugaskan pada Branch PP.');
        }
        if (isset($t['kasbon'])) {
            $t['kasbonHistory'][] = $t['kasbon'];
        }
        if (isset($t['transferProof'])) {
            $t['transferProofHistory'][] = $t['transferProof'];
            unset($t['transferProof']);
        }
        $t['kasbon'] = $summary;
        $t['approverUserIds']['Mgr Finance & Settlement'] = $user['id'];
        unset($t['approverUserIds']['Finance']);
        $t['financePayment'] = ['status' => 'Menunggu Approval'];
        $this->put('terms', $t);
        $this->issuingSettlementKasbon = true;
        try {
            $this->saveTerm($t, true);
        } finally {
            $this->issuingSettlementKasbon = false;
        }
        $this->log('Kasbon dan pembayaran diajukan untuk tujuh approval', 'Termin '.$t['number'], '/termin/'.$id);

        return null;
    }

    private function settlementDecision(array $t, array $decision): array
    {
        if ($decision['role'] === 'Finance') {
            $t['approverUserIds']['Finance'] = $decision['userId'];
            $t['financePaymentHistory'][] = $decision;
            $t['financePayment'] = ['status' => $decision['decision'] === 'Setujui' ? 'Diverifikasi' : 'Dikembalikan', 'decision' => $decision];
        }

        return $t;
    }
}
