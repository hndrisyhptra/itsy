<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait ApprovalResetWorkflow
{
    private function resetApproval(string $id, array $expected, string $note, bool $project, array $context = []): null
    {
        $collection = $project ? 'projects' : 'terms';
        $document = $this->record($collection, $id);
        $plan = ApprovalReset::plan($this->state, $document, $project);
        abort_unless($plan['own'], 403, 'Hanya pemilik persetujuan aktif yang dapat membatalkannya.');
        if ($plan['reason']) {
            $this->fail($plan['reason']);
        }
        abort_unless(Arr::sortRecursive($expected) === Arr::sortRecursive($plan['expected']), 409, 'Persetujuan atau versi pengajuan berubah. Muat ulang sebelum membatalkan.');
        $note = $this->text($note, 1000);
        if ($note === '') {
            $this->fail('Alasan pembatalan persetujuan wajib diisi.');
        }
        $historyKey = $project ? 'approvalHistory' : 'history';
        $document[$historyKey] ??= $document['approvals'] ?? [];
        $mode = $plan['mode'];
        $marker = ['version' => 1, 'id' => (string) Str::uuid(), 'mode' => $mode, 'historyOffset' => count($project ? ($document['approvalHistory'] ?? []) : ($document['history'] ?? [])),
            'sourceCycleNo' => count(ProjectHeadReopen::cycles($document, $project)), 'actorUserId' => $this->state['actorUserId'], 'role' => $this->state['role'],
            'actorName' => $this->record('users', $this->state['actorUserId'])['name'], 'at' => now()->toISOString(), 'reason' => $note,
            'expected' => $expected, 'affectedApprovals' => $plan['affected'], 'carriedApprovals' => $plan['carried'], 'previousStatus' => $document['status'],
            'requestId' => $context['requestId'] ?? null, 'payloadHash' => $context['payloadHash'] ?? null];
        if ($mode === 'workflow') {
            if ($project && $document['status'] === 'Sah' && self::usesContractBundle($document)) {
                $submission = end($document['contractBundle']['submissions']);
                [$deals, $orders] = $this->bundleDocuments($id);
                foreach (['deals' => $deals, 'spks' => $orders] as $kind => $documents) {
                    if (count($documents) !== count($submission['documents'][$kind] ?? [])) {
                        $this->fail('Isi paket berbeda dari pengajuan semula. Pembatalan ditahan.');
                    }
                    foreach ($documents as $child) {
                        if ($child['status'] !== 'Sah' || ($child['approvedWithProject']['submissionId'] ?? '') !== $submission['id']) {
                            $this->fail('Status dokumen paket telah berubah. Pembatalan ditahan.');
                        }
                        $child['status'] = 'Draft';
                        unset($child['approvedWithProject']);
                        if (($submission['documents'][$kind][$child['id']] ?? '') !== $this->bundleHash($child)) {
                            $this->fail('Sumber paket telah berubah. Pembatalan ditahan.');
                        }
                        $this->put($kind, $child);
                    }
                }
            }
            $document['approvals'] = $plan['carried'];
            $document['status'] = 'Diajukan';
            if (! $project && self::usesSettlementPayment($document)) {
                $marker['previousFinancePayment'] = $document['financePayment'] ?? null;
                $document['financePayment'] = ['status' => 'Menunggu Approval'];
                if (! collect($document['approvals'])->contains('role', 'Finance')) {
                    unset($document['approverUserIds']['Finance']);
                }
            }
        } elseif ($mode === 'legacy-manager') {
            $marker['previousReview'] = $document['legacySettlementReview'];
            $marker['previousFinancePayment'] = $document['financePayment'];
            unset($document['legacySettlementReview']['decision']);
            $document['financePayment'] = ['status' => 'Kasbon Terbit'];
        } elseif ($mode === 'legacy-finance') {
            $marker['previousFinancePayment'] = $document['financePayment'];
            $document['financePayment'] = ['status' => 'Kasbon Terbit'];
        } elseif ($mode === 'legacy-partial') {
            $marker['previousPaymentRequest'] = $document['paymentRequest'];
            $document['paymentRequestHistory'][] = $document['paymentRequest'];
            unset($document['paymentRequest']['decision']);
            $document['paymentRequest']['status'] = 'Diajukan';
        } elseif ($mode === 'legacy-scan') {
            $marker['previousScan'] = $document['scan'];
            unset($document['financeApproval']);
            foreach (['verifiedAt', 'verifiedBy', 'verifiedRole', 'note'] as $field) {
                unset($document['scan'][$field]);
            }
            $document['scan']['status'] = 'Menunggu Verifikasi';
        }
        $document['approvalResets'][] = $marker;
        if (! $project) {
            $document['approvalResetVersion'] = count($document['approvalResets']);
        }
        $this->put($collection, $document);
        $this->log('Persetujuan '.($project ? 'paket PP' : 'pembayaran').' dibatalkan', $this->state['role'].' · '.$note, ($project ? '/project-plan/' : '/termin/').$id);

        return null;
    }
}
