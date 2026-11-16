<?php

namespace App\Services;

/** Additional current-kasbon review; original five-role SQL runs/decisions stay immutable. */
trait LegacySettlementReview
{
    public static function legacyReviewApproved(array $t): bool
    {
        $r = $t['legacySettlementReview'] ?? null;

        return $r === null || (($r['kasbonId'] ?? '') === ($t['kasbon']['id'] ?? null)
            && ($r['decision']['decision'] ?? '') === 'Setujui'
            && ($r['decision']['role'] ?? '') === 'Mgr Finance & Settlement'
            && ($r['decision']['userId'] ?? '') === ($r['userId'] ?? null)
            && ($r['decision']['kasbonId'] ?? '') === ($t['kasbon']['id'] ?? null));
    }

    public static function legacyReviewPending(array $t): bool
    {
        return isset($t['legacySettlementReview']) && self::usesFinancePayment($t) && ! self::usesSettlementPayment($t)
            && $t['status'] === 'Disetujui' && ($t['paid'] ?? 0) == 0 && $t['apmStatus'] !== 'Valid'
            && ($t['financePayment']['status'] ?? '') === 'Kasbon Terbit' && ! self::legacyReviewApproved($t);
    }

    public static function canRouteLegacyFinance(array $t): bool
    {
        return self::usesFinancePayment($t) && ! self::usesSettlementPayment($t) && $t['status'] === 'Disetujui'
            && ! empty($t['kasbon']['id']) && ($t['financePayment']['status'] ?? '') === 'Kasbon Terbit'
            && ! isset($t['legacySettlementReview']) && empty($t['financePayment']['decision'])
            && ! isset($t['transferProof']) && ! collect($t['financePaymentHistory'] ?? [])->contains('decision', 'Setujui')
            && empty($t['apm']) && empty($t['paymentHistory']) && ($t['paid'] ?? 0) == 0 && $t['apmStatus'] !== 'Valid';
    }

    private function routeLegacyFinance(array $targets, string $userId): null
    {
        abort_unless(in_array($this->state['role'], ['Finance', 'Superadmin'], true), 403);
        if (! array_is_list($targets) || count($targets) < 1 || count($targets) > 50) {
            $this->fail('Pilih 1–50 pengajuan per pemindahan.');
        }
        $user = $this->approver($userId, 'Mgr Finance & Settlement');
        $terms = array_column($this->state['terms'], null, 'id');
        $spks = array_column($this->state['spks'], null, 'id');
        $deals = array_column($this->state['deals'], null, 'id');
        $projects = array_column($this->state['projects'], null, 'id');
        $seen = [];
        foreach ($targets as $target) {
            $t = $terms[$target['id'] ?? ''] ?? [];
            if (! $t || isset($seen[$t['id']]) || ! self::canRouteLegacyFinance($t) || ($target['kasbonId'] ?? '') !== $t['kasbon']['id']) {
                $this->fail('Pengajuan atau versi kasbon berubah, sudah dialihkan/disetujui Finance, atau telah dibayar. Tidak ada pengajuan yang dipindahkan.');
            }
            $seen[$t['id']] = true;
            $p = $projects[$deals[$spks[$t['spkId']]['dealId']]['projectId']];
            if (isset($user['branches']) && ! in_array($p['branch'], $user['branches'], true)) {
                $this->fail('Mgr Finance & Settlement harus memiliki seluruh Branch pengajuan terpilih.');
            }
            foreach (self::approvalRoles($t) as $role) {
                if (! collect($t['approvals'])->contains(fn ($a) => $a['role'] === $role && $a['decision'] === 'Setujui'
                    && ! empty($a['userId']) && $a['userId'] === ($t['approverUserIds'][$role] ?? ''))) {
                    $this->fail('Lima approval pekerjaan harus lengkap sebelum dialihkan.');
                }
            }
        }
        foreach ($targets as $target) {
            $t = $terms[$target['id']];
            $r = ['version' => 1, 'kasbonId' => $t['kasbon']['id'], 'userId' => $user['id'], 'name' => $user['name'],
                'assignedAt' => now()->toISOString(), 'assignedBy' => $this->state['actorUserId']];
            $t['legacySettlementReview'] = $r;
            $t['legacySettlementReviewHistory'][] = $r;
            $this->put('terms', $t);
            $this->log('Payment dialihkan ke Mgr Finance & Settlement', 'Termin '.$t['number'].' · '.$user['name'], '/termin/'.$t['id']);
        }

        return null;
    }

    private function decideLegacySettlementReview(string $id, string $kasbonId, string $decision, string $note): null
    {
        $t = $this->record('terms', $id);
        $r = $t['legacySettlementReview'] ?? [];
        abort_unless($this->state['role'] === 'Mgr Finance & Settlement' && ($r['userId'] ?? '') === $this->state['actorUserId'], 403);
        $user = $this->approver($this->state['actorUserId'], 'Mgr Finance & Settlement');
        if (! self::legacyReviewPending($t) || ($r['kasbonId'] ?? '') !== $kasbonId || ($t['kasbon']['id'] ?? '') !== $kasbonId) {
            $this->fail('Review tidak menunggu akun ini atau versi kasbon berubah.');
        }
        $this->enum($decision, ['Setujui', 'Kembalikan']);
        $note = $this->text($note, 1000);
        if ($decision === 'Kembalikan' && $note === '') {
            $this->fail('Catatan pengembalian kasbon wajib diisi.');
        }
        $a = ['role' => 'Mgr Finance & Settlement', 'userId' => $user['id'], 'actorName' => $user['name'],
            'decision' => $decision, 'note' => $note, 'at' => now()->toISOString(), 'kasbonId' => $kasbonId];
        $t['legacySettlementReview']['decision'] = $a;
        $t['legacySettlementReviewHistory'][] = $t['legacySettlementReview'];
        if ($decision === 'Kembalikan') {
            $t['financePayment'] = ['status' => 'Dikembalikan'];
        }
        $this->put('terms', $t);
        $this->log('Review Mgr Finance & Settlement · '.$decision, 'Termin '.$t['number'].' · '.$note, '/termin/'.$id);

        return null;
    }
}
