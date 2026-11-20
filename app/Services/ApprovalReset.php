<?php

namespace App\Services;

/** Read-only planning for self-revocation. Never infer an actor from a role alone. */
final class ApprovalReset
{
    public static function plan(array $state, array $document, bool $project): array
    {
        $actor = $state['actorUserId'] ?? '';
        $role = $state['role'];
        $user = collect($state['users'])->firstWhere('id', $actor);
        $roles = PrototypeDomain::approvalRoles($document, $project);
        $selected = ($document['workflowVersion'] ?? 1) >= 3 ? ($document['approverUserIds'][$role] ?? '') : ($document[$role === 'PM Konstruksi' ? 'pmUserId' : 'managerUserId'] ?? '');
        $decision = collect($document['approvals'] ?? [])->first(fn ($a) => in_array($role, $roles, true) && ($a['userId'] ?? '') === $actor && $a['role'] === $role && $a['decision'] === 'Setujui' && $actor === $selected
            && ($project || ! PrototypeDomain::usesSettlementPayment($document) || ($a['kasbonId'] ?? '') === ($document['kasbon']['id'] ?? null)));
        $mode = 'workflow';
        if (! $decision && ! $project && ($document['status'] ?? '') === 'Disetujui' && ! PrototypeDomain::usesSettlementPayment($document)) {
            $review = $document['legacySettlementReview'] ?? [];
            $finance = $document['financePayment']['decision'] ?? [];
            $scan = $document['financeApproval'] ?? [];
            $partial = $document['paymentRequest']['decision'] ?? [];
            if ($role === 'Mgr Finance & Settlement' && ($review['userId'] ?? '') === $actor && ($review['kasbonId'] ?? '') === ($document['kasbon']['id'] ?? null)) {
                $decision = ($review['decision']['kasbonId'] ?? '') === ($document['kasbon']['id'] ?? null) ? ($review['decision'] ?? null) : null;
                $mode = 'legacy-manager';
            } elseif ($role === 'Finance' && ($finance['userId'] ?? '') === $actor && ($finance['kasbonId'] ?? '') === ($document['kasbon']['id'] ?? null) && ($document['financePayment']['status'] ?? '') === 'Diverifikasi') {
                $decision = $finance;
                $mode = 'legacy-finance';
            } elseif ($role === 'Finance' && ($partial['userId'] ?? '') === $actor && ($partial['paymentRequestId'] ?? '') === ($document['paymentRequest']['id'] ?? null) && ($document['paymentRequest']['status'] ?? '') === 'Diverifikasi') {
                $decision = $partial;
                $mode = 'legacy-partial';
            } elseif ($role === 'Finance' && ($scan['userId'] ?? '') === $actor && ($scan['scanId'] ?? '') === ($document['scan']['id'] ?? null) && ($document['scan']['status'] ?? '') === 'Valid') {
                $decision = $scan;
                $mode = 'legacy-scan';
            }
        }
        $own = $actor !== '' && $user && $user['active'] && $user['role'] === $role && ($decision['userId'] ?? '') === $actor && ($decision['role'] ?? '') === $role && ($decision['decision'] ?? '') === 'Setujui';
        $history = $project ? ($document['approvalHistory'] ?? $document['approvals'] ?? []) : ($document['history'] ?? $document['approvals'] ?? []);
        $lastSubmission = $project ? collect($document['contractBundle']['submissions'] ?? [])->last() : null;
        $expected = ['approvedAt' => $decision['at'] ?? '', 'historyLength' => count($history), 'resetCount' => count($document['approvalResets'] ?? []),
            'bindingId' => $project ? ($lastSubmission['id'] ?? '') : ($document['kasbon']['id'] ?? ($mode === 'legacy-partial' ? ($document['paymentRequest']['id'] ?? '') : ($mode === 'legacy-scan' ? ($document['scan']['id'] ?? '') : ''))), 'mode' => $mode, 'financeHistoryLength' => count($document['financePaymentHistory'] ?? []) + count($document['financeHistory'] ?? []), 'reviewHistoryLength' => count($document['legacySettlementReviewHistory'] ?? []), 'requestHistoryLength' => count($document['paymentRequestHistory'] ?? [])];
        $result = ['own' => $own, 'mode' => $mode, 'decision' => $decision, 'expected' => $expected, 'reason' => '', 'affected' => [], 'carried' => [], 'documentIds' => [$document['id']]];
        if (! $own) {
            $result['reason'] = 'Tidak ada persetujuan aktif atas akun Anda pada versi pengajuan ini.';

            return $result;
        }
        if (($document['workflowVersion'] ?? 1) < 2 || ! in_array($document['status'], ['Diajukan', 'Sah', 'Disetujui'], true)) {
            $result['reason'] = 'Status pengajuan ini tidak mendukung pembatalan persetujuan.';

            return $result;
        }
        $historyKey = fn ($a) => ($a['role'] ?? '').'|'.($a['userId'] ?? '').'|'.($a['at'] ?? '').'|'.($a['kasbonId'] ?? '').'|'.($a['decision'] ?? '');
        $actualHistory = array_fill_keys(array_map($historyKey, $history), true);
        $seen = [];
        foreach ($document['approvals'] ?? [] as $a) {
            $assigned = ($document['workflowVersion'] ?? 1) >= 3 ? ($document['approverUserIds'][$a['role']] ?? '') : ($document[$a['role'] === 'PM Konstruksi' ? 'pmUserId' : 'managerUserId'] ?? '');
            if (! in_array($a['role'], $roles, true) || isset($seen[$a['role']]) || $a['decision'] !== 'Setujui' || empty($a['userId']) || $a['userId'] !== $assigned || empty($a['at']) || ! isset($actualHistory[$historyKey($a)])
                || (! $project && PrototypeDomain::usesSettlementPayment($document) && ($a['kasbonId'] ?? '') !== ($document['kasbon']['id'] ?? null))) {
                $result['reason'] = 'Riwayat persetujuan aktif tidak cocok dengan penugasan/versi tersimpan. Pembatalan ditahan.';

                return $result;
            }
            $seen[$a['role']] = true;
        }
        if ($mode !== 'workflow' && count($seen) !== count($roles)) {
            $result['reason'] = 'Approval pekerjaan historis belum lengkap. Pembatalan ditahan.';

            return $result;
        }
        $deals = array_column($state['deals'], null, 'id');
        $spks = array_column($state['spks'], null, 'id');
        $pp = $project ? $document : collect($state['projects'])->firstWhere('id', $deals[$spks[$document['spkId']]['dealId']]['projectId']);
        if (! $pp || isset($pp['headApprovalReopen'])) {
            $result['reason'] = 'PP memakai koreksi historis khusus; pembatalan mandiri ditahan.';

            return $result;
        }
        if ((isset($user['branches']) && ! in_array($pp['branch'], $user['branches'], true)) || (isset($user['workUnits']) && ! in_array($pp['unit'] === 'Operation' ? 'Operation' : 'Konstruksi', $user['workUnits'], true))) {
            $result['reason'] = 'Akses Branch atau unit kerja akun telah berubah.';

            return $result;
        }
        if ($mode === 'workflow') {
            $position = array_search($role, $roles, true);
            foreach ($document['approvals'] ?? [] as $a) {
                $i = array_search($a['role'], $roles, true);
                if ($i === false || $i >= $position) {
                    $result['affected'][] = $a;
                } else {
                    $result['carried'][] = $a;
                }
            }
        } else {
            $result['affected'] = [$decision];
            if ($mode === 'legacy-manager' && ($document['financePayment']['decision']['decision'] ?? '') === 'Setujui') {
                $result['affected'][] = $document['financePayment']['decision'];
            }
            $result['carried'] = $document['approvals'] ?? [];
        }
        if ($project) {
            $children = array_values(array_filter($state['deals'], fn ($d) => $d['projectId'] === $document['id']));
            $dealIds = array_column($children, 'id');
            $orders = array_values(array_filter($state['spks'], fn ($v) => in_array($v['dealId'], $dealIds, true)));
            $orderIds = array_column($orders, 'id');
            $result['documentIds'] = [$document['id'], ...$dealIds, ...$orderIds];
            if (collect($state['terms'])->contains(fn ($t) => in_array($t['spkId'], $orderIds, true)) || collect($state['projects'])->contains(fn ($p) => ($p['parentId'] ?? '') === $document['id'])) {
                $result['reason'] = 'PP sudah memiliki pengajuan pekerjaan/pembayaran atau PP lanjutan. Pembatalan mandiri ditahan.';
            } elseif (! PrototypeDomain::usesContractBundle($document) && ($children || $orders)) {
                $result['reason'] = 'PP historis sudah memiliki BA KH/SPK terpisah. Pembatalan mandiri ditahan.';
            }
        } else {
            if (($document['financePayment']['status'] ?? '') === 'Dikembalikan' || ($document['paymentRequest']['status'] ?? '') === 'Dikembalikan') {
                $result['reason'] = 'Pengajuan sudah dikembalikan untuk revisi Admin. Pembatalan persetujuan ditahan.';
            } elseif ($pp['status'] !== 'Sah') {
                $result['reason'] = 'PP belum Sah; proses turunannya sedang ditahan.';
            } elseif (($document['paid'] ?? 0) > 0 || ! empty($document['apm']) || ($document['apmStatus'] ?? '') === 'Valid' || ! empty($document['paymentHistory']) || ! empty($document['transferProof']) || ! empty($document['transferProofHistory']) || ! empty($document['financePayment']['settledAt']) || ($document['financePayment']['status'] ?? '') === 'Dibayar') {
                $result['reason'] = 'Bukti transfer, APM atau realisasi pembayaran sudah tercatat. Pembatalan persetujuan ditahan.';
            } elseif (collect($state['terms'])->contains(fn ($t) => $t['spkId'] === $document['spkId'] && $t['number'] > $document['number'])) {
                $result['reason'] = 'Sudah ada pengajuan berikutnya pada SPK ini.';
            } elseif (collect($state['bapps'])->contains(fn ($b) => $b['termId'] === $document['id'] && (! empty($b['releaseHistory']) || array_filter($b['released'] ?? [], fn ($q) => ($q['m'] ?? 0) > 0 || ($q['s'] ?? 0) > 0)))) {
                $result['reason'] = 'Volume BA PP sudah dilepas; pembatalan persetujuan ditahan.';
            } elseif ($mode === 'workflow' && ! PrototypeDomain::usesSettlementPayment($document) && (! empty($document['kasbon']) || ! empty($document['paymentRequest']) || ! empty($document['financeApproval']) || ! empty($document['financePayment']['decision']))) {
                $result['reason'] = 'Pengajuan lama sudah masuk proses kasbon/Finance. Persetujuan pekerjaan tetap mengikuti riwayat tersimpan.';
            }
        }

        return $result;
    }
}
