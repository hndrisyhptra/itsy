<?php

namespace App\Services;

use Illuminate\Support\Str;

trait PartialPaymentWorkflow
{
    public static function usesPartialPayment(array $term): bool
    {
        return ($term['workflowVersion'] ?? 1) === 3 && $term['type'] === 'Parsial' && ($term['paymentFlow'] ?? '') === 'partial-payment-v1';
    }

    public static function financeTaskPending(array $t): bool
    {
        if ($t['status'] !== 'Disetujui') {
            return false;
        }
        if (self::usesPartialPayment($t)) {
            return ($t['apmStatus'] !== 'Valid' && in_array($t['paymentRequest']['status'] ?? '', ['Diajukan', 'Diverifikasi'], true)) || ($t['scan']['status'] ?? '') === 'Menunggu Verifikasi';
        }

        return ($t['workflowVersion'] ?? 1) >= 2 && $t['apmStatus'] !== 'Valid' && in_array($t['scan']['status'] ?? '', ['Menunggu Verifikasi', 'Valid'], true);
    }

    public static function partialFinanceHistory(array $t): array
    {
        return array_values(array_filter(array_column([...($t['paymentRequestHistory'] ?? []), ...(isset($t['paymentRequest']) ? [$t['paymentRequest']] : [])], 'decision')));
    }

    private function partialPaymentEligible(array $term): void
    {
        if (($term['workflowVersion'] ?? 1) !== 3 || $term['type'] !== 'Parsial' || $term['status'] !== 'Disetujui' || count($term['approvals']) !== $this->approvalCount($term) || $term['apmStatus'] === 'Valid' || $term['paid'] > 0) {
            $this->fail('Payment khusus Termin Parsial membutuhkan lima approval lengkap dan tagihan belum dibayar.');
        }
        foreach ($this->approvalRoles($term) as $role) {
            if (! collect($term['approvals'])->contains(fn ($a) => $a['role'] === $role && ($a['userId'] ?? '') === ($term['approverUserIds'][$role] ?? '') && $a['decision'] === 'Setujui')) {
                $this->fail('Approval Payment harus sesuai kelima pengguna terpilih, termasuk Head of PMO.');
            }
        }
    }

    private function requestPartialPayment(string $id): null
    {
        $this->admin();
        $t = $this->record('terms', $id);
        $this->partialPaymentEligible($t);
        if (isset($t['paymentRequest']) && $t['paymentRequest']['status'] !== 'Dikembalikan') {
            $this->fail('Payment sudah diajukan. Pantau verifikasi Finance pada pengajuan yang sama.');
        }
        $t['paymentRequestHistory'] ??= [];
        if (isset($t['paymentRequest'])) {
            $t['paymentRequestHistory'][] = $t['paymentRequest'];
        }
        $actor = $this->record('users', $this->state['actorUserId']);
        $t['paymentFlow'] = 'partial-payment-v1';
        $t['paymentRequest'] = ['id' => (string) Str::uuid(), 'cycle' => count($t['paymentRequestHistory']) + 1, 'status' => 'Diajukan',
            'amount' => $this->termTotal($t), 'requestedBy' => $actor['id'], 'requestedByName' => $actor['name'], 'requestedAt' => now()->toISOString(),
            'documentsAtRequest' => isset($t['scan']) ? 'OK' : 'NOK'];
        $this->put('terms', $t);
        $this->log('Payment Parsial diajukan ke Finance', 'Termin '.$t['number'].' · Berkas '.$t['paymentRequest']['documentsAtRequest'], '/termin/'.$id);

        return null;
    }

    private function verifyPartialPayment(string $id, string $decision, string $note): null
    {
        $this->finance();
        $t = $this->record('terms', $id);
        $this->partialPaymentEligible($t);
        $this->enum($decision, ['Setujui', 'Kembalikan']);
        $note = $this->text($note, 1000);
        if (! self::usesPartialPayment($t) || ($t['paymentRequest']['status'] ?? '') !== 'Diajukan') {
            $this->fail('Admin harus mengajukan Payment terlebih dahulu sebelum verifikasi Finance.');
        }
        if ($decision === 'Kembalikan' && $note === '') {
            $this->fail('Catatan pengembalian Payment wajib diisi.');
        }
        $actor = $this->approver($this->state['actorUserId'], 'Finance');
        $t['paymentRequest']['decision'] = ['role' => 'Finance', 'userId' => $actor['id'], 'actorName' => $actor['name'], 'decision' => $decision, 'note' => $note, 'at' => now()->toISOString(), 'paymentRequestId' => $t['paymentRequest']['id'], 'requestedAt' => $t['paymentRequest']['requestedAt']];
        $t['paymentRequest']['status'] = $decision === 'Setujui' ? 'Diverifikasi' : 'Dikembalikan';
        $this->put('terms', $t);
        $this->log('Payment Parsial '.$t['paymentRequest']['status'], 'Termin '.$t['number'].' · '.$note, '/termin/'.$id);

        return null;
    }

    private function payPartialPayment(string $id, string $apm): null
    {
        $this->finance();
        $t = $this->record('terms', $id);
        $this->partialPaymentEligible($t);
        if (! self::usesPartialPayment($t)) {
            $this->fail('Admin harus mengajukan Payment Parsial terlebih dahulu.');
        }

        // Kasbon format explicitly deferred by the user; it is not a payment prerequisite.
        return $this->recordApm($id, $apm, true);
    }

    private function assertPartialApmReady(array $t): void
    {
        $request = $t['paymentRequest'] ?? [];
        if (($request['status'] ?? '') !== 'Diverifikasi' || ($request['decision']['decision'] ?? '') !== 'Setujui'
            || empty($request['decision']['userId'])
            || bccomp((string) ($request['amount'] ?? -1), (string) $this->termTotal($t), 2) !== 0) {
            $this->fail('Payment terverifikasi sesuai tagihan wajib sebelum APM.');
        }
    }
}
