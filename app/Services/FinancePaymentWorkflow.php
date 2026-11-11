<?php

namespace App\Services;

use Illuminate\Support\Str;

trait FinancePaymentWorkflow
{
    public static function usesFinancePayment(array $t): bool
    {
        return ($t['workflowVersion'] ?? 1) === 3 && ($t['paymentFlow'] ?? '') === 'kasbon-v1';
    }

    public static function adminKasbonPending(array $t): bool
    {
        if (! self::usesFinancePayment($t) || $t['status'] !== 'Disetujui' || $t['apmStatus'] === 'Valid' || $t['paid'] > 0
            || (isset($t['kasbon']) && ($t['financePayment']['status'] ?? '') !== 'Dikembalikan')) {
            return false;
        }
        foreach (self::approvalRoles($t) as $role) {
            if (! collect($t['approvals'])->contains(fn ($a) => $a['role'] === $role && $a['decision'] === 'Setujui' && ! empty($a['userId']) && $a['userId'] === ($t['approverUserIds'][$role] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    private function submitFinanceTerm(array $input): null
    {
        $this->admin();
        $t = $this->record('terms', $input['id'] ?? '');
        if (($t['workflowVersion'] ?? 1) !== 3 || isset($t['paymentRequest']) || $t['paid'] > 0 || isset($t['kasbon'])) {
            $this->fail('Alur kasbon hanya dapat diadopsi pada pengajuan v3 yang masih dapat diperbaiki dan belum memiliki transaksi Finance.');
        }
        $this->saveTerm($input, true);
        $t = $this->record('terms', $input['id']);
        $t['paymentFlow'] = 'kasbon-v1';
        $t['financePayment'] = ['status' => 'Menunggu Approval'];
        $this->put('terms', $t);

        return null;
    }

    private function adoptFinancePayment(string $id): null
    {
        $this->admin();
        $t = $this->record('terms', $id);
        if (($t['workflowVersion'] ?? 1) !== 3 || self::usesFinancePayment($t) || isset($t['paymentRequest']) || $t['paid'] > 0 || self::usesBusinessSupport($t)) {
            $this->fail('Adopsi hanya untuk pengajuan v3 belum dibayar, lima approver Project Support dan tanpa transaksi Payment lama.');
        }
        $t['paymentFlow'] = 'kasbon-v1';
        $this->financePaymentEligible($t);
        $t['financePayment'] = ['status' => 'Menunggu Approval'];
        $this->put('terms', $t);
        $this->log('Alur kasbon diadopsi Admin', 'Approval pekerjaan tetap tersimpan', '/termin/'.$id);

        return null;
    }

    private function financePaymentEligible(array $t): void
    {
        if (! self::usesFinancePayment($t) || $t['status'] !== 'Disetujui' || $t['apmStatus'] === 'Valid' || $t['paid'] > 0) {
            $this->fail('Payment membutuhkan seluruh approval termasuk Head dan tagihan belum dibayar.');
        }
        foreach ($this->approvalRoles($t) as $role) {
            if (! collect($t['approvals'])->contains(fn ($a) => $a['role'] === $role && ($a['userId'] ?? '') === ($t['approverUserIds'][$role] ?? '') && $a['decision'] === 'Setujui')) {
                $this->fail('Kelima approval pengguna terpilih harus lengkap.');
            }
        }
    }

    private function issueKasbonSummary(string $id, array $summary): null
    {
        $this->admin();
        $t = $this->record('terms', $id);
        $this->financePaymentEligible($t);
        if (isset($t['kasbon']) && ($t['financePayment']['status'] ?? '') !== 'Dikembalikan') {
            $this->fail('Kasbon telah diterbitkan; kembalikan dengan catatan sebelum revisi.');
        }
        if (! Str::isUuid($summary['id'] ?? '') || bccomp((string) ($summary['amount'] ?? -1), (string) $this->termTotal($t), 2) !== 0) {
            $this->fail('Kasbon harus sesuai tagihan termin.');
        }
        if (isset($t['kasbon'])) {
            $t['kasbonHistory'][] = $t['kasbon'];
        }
        if (isset($t['transferProof'])) {
            $t['transferProofHistory'][] = $t['transferProof'];
            unset($t['transferProof']);
        }
        $t['kasbon'] = $summary;
        $t['financePayment'] = ['status' => 'Kasbon Terbit'];
        $this->put('terms', $t);
        $this->log('Kasbon diajukan Admin Konstruksi', 'Termin '.$t['number'].' · versi '.$summary['version'], '/termin/'.$id);

        return null;
    }

    private function decideFinancePayment(string $id, string $decision, string $note, ?string $expectedKasbonId = null): null
    {
        $this->finance();
        $t = $this->record('terms', $id);
        $this->financePaymentEligible($t);
        if ((isset($t['kasbon']['issuedRole']) || $expectedKasbonId !== null) && $expectedKasbonId !== ($t['kasbon']['id'] ?? null)) {
            $this->fail('Versi kasbon berubah. Review kembali kasbon yang aktif sebelum memberi keputusan Finance.');
        }
        $this->enum($decision, ['Setujui', 'Kembalikan']);
        $note = $this->text($note, 1000);
        if (! isset($t['kasbon']) || ! in_array($t['financePayment']['status'] ?? '', ['Kasbon Terbit', 'Diverifikasi'], true) || ($decision === 'Kembalikan' && ! $note) || ($decision === 'Setujui' && ($t['financePayment']['status'] ?? '') !== 'Kasbon Terbit')) {
            $this->fail('Terbitkan kasbon terlebih dahulu; pengembalian wajib disertai catatan.');
        }
        if ($decision === 'Setujui' && isset($t['scan']) && ($t['scan']['status'] ?? '') !== 'Valid') {
            $this->fail('Scan sudah tersedia; verifikasi berkas terlebih dahulu sebelum Approve Payment.');
        }
        $actor = $this->approver($this->state['actorUserId'], 'Finance');
        $a = ['userId' => $actor['id'], 'actorName' => $actor['name'], 'role' => 'Finance', 'decision' => $decision, 'note' => $note, 'at' => now()->toISOString(), 'kasbonId' => $t['kasbon']['id'], 'requestedAt' => $t['kasbon']['issuedAt']];
        $t['financePaymentHistory'][] = $a;
        $t['financePayment'] = ['status' => $decision === 'Setujui' ? 'Diverifikasi' : 'Dikembalikan', 'decision' => $a];
        $this->put('terms', $t);
        $this->log('Payment '.$t['financePayment']['status'], 'Termin '.$t['number'].' · '.$note, '/termin/'.$id);

        return null;
    }

    private function attachTransferProof(string $id, array $file): null
    {
        $this->finance();
        $t = $this->record('terms', $id);
        $this->financePaymentEligible($t);
        if (! Str::isUuid($file['id'] ?? '')) {
            $this->fail('Bukti transfer tidak valid.');
        }
        if (isset($t['transferProof'])) {
            $t['transferProofHistory'][] = $t['transferProof'];
        }
        $t['transferProof'] = array_intersect_key($file, array_flip(['id', 'uploadedBy', 'uploadedAt'])) + ['kasbonId' => $t['kasbon']['id']];
        $this->put('terms', $t);
        $this->log('Bukti transfer diunggah Finance', 'Termin '.$t['number'], '/termin/'.$id);

        return null;
    }

    private function recordPayeeFile(string $id): null
    {
        $this->admin();
        $this->record('spks', $id);
        $this->log('Foto identitas/rekening Mandor diunggah', 'Lampiran pribadi tersimpan', '/spk/'.$id);

        return null;
    }

    private function setPayeeVersion(string $id, int $version): null
    {
        $this->admin();
        $v = $this->record('spks', $id);
        // Financial annex is versioned separately; issued SPK prose/identity stay frozen.
        $v['payeeVersion'] = $version;
        $this->put('spks', $v);
        $this->log('Data pembayaran Mandor diperbarui', 'Versi '.$version, '/spk/'.$id);

        return null;
    }

    private function saveSpkFinance(array $input, string $action): string
    {
        $old = $this->existing('spks', $input['id'] ?? '');
        if ($action === 'attest' && empty($input['_payeeReady'])) {
            $this->fail('Lengkapi dan simpan NIK KTP, foto KTP, rekening, pemilik rekening, nama bank dan foto buku tabungan sebelum Sahkan SPK.');
        }
        unset($input['_payeeReady']);
        $input['financialFlow'] = 'kasbon-v1';
        if ($old) {
            $input['payeeVersion'] = $old['payeeVersion'] ?? 0;
        }

        $id = $this->saveSpk($input, $action);
        $saved = $this->record('spks', $id);
        $saved['financialFlow'] = 'kasbon-v1';
        $this->put('spks', $saved);

        return $id;
    }

    private function assertFinanceApmReady(array $t): void
    {
        $this->financePaymentEligible($t);
        if (isset($t['scan']) && ($t['scan']['status'] ?? '') !== 'Valid') {
            $this->fail('Scan sudah tersedia; verifikasi berkas sebelum APM.');
        }
        if (($t['financePayment']['status'] ?? '') !== 'Diverifikasi' || ($t['financePayment']['decision']['decision'] ?? '') !== 'Setujui'
            || ($t['financePayment']['decision']['kasbonId'] ?? '') !== ($t['kasbon']['id'] ?? null) || empty($t['transferProof']['id']) || ($t['transferProof']['kasbonId'] ?? '') !== ($t['kasbon']['id'] ?? null)
            || bccomp((string) ($t['kasbon']['amount'] ?? -1), (string) $this->termTotal($t), 2) !== 0) {
            $this->fail('Kasbon terbit, persetujuan Finance sesuai kasbon dan bukti transfer wajib sebelum APM.');
        }
    }
}
