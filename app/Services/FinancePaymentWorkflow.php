<?php

namespace App\Services;

use Illuminate\Support\Str;

trait FinancePaymentWorkflow
{
    use LegacySettlementReview;

    public function paymentDue(array $t): float
    {
        return KasbonSettlement::due($t, $this->termTotal($t));
    }

    public function kasbonSummaryMatches(array $t, array $summary): bool
    {
        $deal = $this->record('deals', $this->record('spks', $t['spkId'])['dealId']);

        return KasbonSettlement::matches($deal, $t, $summary, $this->termTotal($t));
    }

    public static function usesFinancePayment(array $t): bool
    {
        return ($t['workflowVersion'] ?? 1) === 3 && in_array($t['paymentFlow'] ?? '', ['kasbon-v1', 'kasbon-settlement-v2'], true);
    }

    public static function settlementRecorded(array $t): bool
    {
        return ($t['apmStatus'] ?? '') === 'Valid' || (self::usesFinancePayment($t)
            && ($t['financePayment']['status'] ?? '') === 'Dibayar' && ! empty($t['financePayment']['settledAt'])
            && ($t['paid'] ?? 0) > 0 && ! empty($t['kasbon']['id']) && ! empty($t['transferProof']['id'])
            && ($t['financePayment']['kasbonId'] ?? '') === $t['kasbon']['id']
            && ($t['financePayment']['transferFileId'] ?? '') === $t['transferProof']['id']);
    }

    private function checkedPaymentApm(string $id, string $apm, bool $optional = false): string
    {
        $apm = strtoupper($this->text($apm, 60));
        if ($apm === '' && $optional) {
            return '';
        }
        if (! preg_match('/^[A-Z0-9][A-Z0-9\/._-]{4,59}$/D', $apm)) {
            $this->fail('Nomor APM 5–60 karakter, huruf/angka/._-/ saja.');
        }
        foreach ($this->state['terms'] as $other) {
            if ($other['id'] !== $id && $other['apmStatus'] === 'Valid' && strtoupper($other['apm']) === $apm) {
                $this->fail('Nomor APM sudah digunakan.');
            }
        }

        return $apm;
    }

    private function settleFinancePayment(string $id, string $apm): null
    {
        $this->finance();
        $t = $this->record('terms', $id);
        $this->assertFinanceApmReady($t);
        $apm = $this->checkedPaymentApm($id, $apm, true);
        $amount = $this->paymentDue($t);
        $at = now()->toISOString();
        $t['paid'] = $amount;
        $t['apm'] = $apm;
        $t['apmStatus'] = $apm === '' ? 'Belum Terbit' : 'Valid';
        $t['paymentHistory'][] = ['amount' => $amount, 'ref' => $t['transferProof']['id'], 'source' => 'TRANSFER_PROOF', 'at' => $at, 'actor' => $this->state['actorUserId']];
        $t['financePayment'] = array_merge($t['financePayment'], ['status' => 'Dibayar', 'documentsAtPayment' => isset($t['scan']) ? 'OK' : 'NOK',
            'kasbonId' => $t['kasbon']['id'], 'transferFileId' => $t['transferProof']['id'], 'settledAt' => $at, 'settledBy' => $this->state['actorUserId']]);
        $this->put('terms', $t);
        $this->log('Pembayaran ditandai lunas', $apm ? 'APM '.$apm : 'Bukti transfer tercatat · APM belum diisi', '/termin/'.$id);

        return null;
    }

    private function updatePaymentApm(string $id, string $apm): null
    {
        $this->finance();
        $t = $this->record('terms', $id);
        if (! self::usesFinancePayment($t) || ! self::settlementRecorded($t) || $t['status'] !== 'Disetujui'
            || ($t['financePayment']['status'] ?? '') !== 'Dibayar' || bccomp((string) $t['paid'], (string) $this->paymentDue($t), 2) !== 0) {
            $this->fail('Nomor APM hanya dapat diubah Finance pada pembayaran kasbon yang sudah lunas.');
        }
        $apm = $this->checkedPaymentApm($id, $apm);
        if ($apm === $t['apm']) {
            return null;
        }
        $t['financePayment']['apmHistory'][] = ['before' => $t['apm'], 'after' => $apm, 'at' => now()->toISOString(), 'by' => $this->state['actorUserId']];
        $t['apm'] = $apm;
        $t['apmStatus'] = 'Valid';
        $this->put('terms', $t);
        $this->log('Nomor APM diperbarui', $apm, '/termin/'.$id);

        return null;
    }

    /** Physical signer correction only; never an application approval or financial reissue. */
    private function setKasbonPrintSigner(string $id, string $kasbonId, string $userId): null
    {
        abort_unless(in_array($this->state['role'], ['Admin Konstruksi', 'Admin Operation', 'Superadmin', 'Finance'], true), 403);
        $t = $this->record('terms', $id);
        if (! self::usesFinancePayment($t) || self::usesSettlementPayment($t) || $t['status'] !== 'Disetujui'
            || isset($t['legacySettlementReview']) || $kasbonId === '' || ($t['kasbon']['id'] ?? '') !== $kasbonId) {
            $this->fail('Pilih kasbon aktif dari pembayaran lama yang sudah disetujui. Versi arsip dan alur tujuh approval tidak dapat diubah melalui pilihan ini.');
        }
        $user = $this->approver($userId, 'Mgr Finance & Settlement');
        $p = $this->record('projects', $this->record('deals', $this->record('spks', $t['spkId'])['dealId'])['projectId']);
        if (isset($user['branches']) && ! in_array($p['branch'], $user['branches'], true)) {
            $this->fail('Penandatangan harus ditugaskan pada Branch PP.');
        }
        $before = $t['kasbonPrintSigners'][$kasbonId] ?? null;
        $job = trim($user['jabatan'] ?? '') ?: 'Mgr Finance & Settlement Area Jawa Bali';
        if (($before['userId'] ?? '') === $user['id'] && ($before['name'] ?? '') === $user['name'] && ($before['job'] ?? '') === $job) {
            return null;
        }
        $after = ['userId' => $user['id'], 'name' => $user['name'], 'job' => $job,
            'at' => now()->toISOString(), 'by' => $this->state['actorUserId']];
        $t['kasbonPrintSigners'][$kasbonId] = $after;
        $t['kasbonPrintSignerHistory'][] = ['kasbonId' => $kasbonId, 'before' => $before, 'after' => $after];
        $this->put('terms', $t);
        $this->log('Penandatangan fisik kasbon diperbarui', $user['name'].' · Mgr Finance & Settlement', '/termin/'.$id);

        return null;
    }

    public static function adminKasbonPending(array $t): bool
    {
        if (self::usesSettlementPayment($t)) {
            return in_array($t['status'], ['Draft', 'Dikembalikan', 'Ditolak'], true) && $t['paid'] == 0 && $t['apmStatus'] !== 'Valid';
        }
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
        if (self::usesSettlementPayment($t)) {
            $this->fail('Ajukan pembayaran melalui form kasbon untuk tujuh approval.');
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
        if (! Str::isUuid($summary['id'] ?? '') || ! $this->kasbonSummaryMatches($t, $summary)) {
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
        if (isset($t['legacySettlementReview'])) {
            unset($t['legacySettlementReview']['decision']);
            $t['legacySettlementReview']['kasbonId'] = $summary['id'];
            $t['legacySettlementReviewHistory'][] = $t['legacySettlementReview'];
        }
        $t['financePayment'] = ['status' => 'Kasbon Terbit'];
        $this->put('terms', $t);
        $this->log('Kasbon diajukan Admin Konstruksi', 'Termin '.$t['number'].' · versi '.$summary['version'], '/termin/'.$id);

        return null;
    }

    private function decideFinancePayment(string $id, string $decision, string $note, ?string $expectedKasbonId = null): null
    {
        $this->finance();
        $t = $this->record('terms', $id);
        if (self::usesSettlementPayment($t)) {
            if ($expectedKasbonId !== ($t['kasbon']['id'] ?? null)) {
                $this->fail('Versi kasbon berubah. Review kembali dokumen aktif.');
            }

            return $this->decide($id, $decision, $note);
        }
        $this->financePaymentEligible($t);
        if (! self::legacyReviewApproved($t)) {
            $this->fail('Menunggu persetujuan Mgr Finance & Settlement untuk kasbon aktif.');
        }
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
        $this->assertBappBeforePayment($t);
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

    private function selectMasterMandor(string $id, array $data): null
    {
        $this->admin();
        $v = $this->record('spks', $id);
        $d = $this->record('deals', $v['dealId']);
        $p = $this->record('projects', $d['projectId']);
        $this->assertBundleDocumentEditable($p, 'save');
        if (! in_array($p['status'], ['Draft', 'Dikembalikan', 'Ditolak'], true) || $v['status'] !== 'Draft' || $d['status'] !== 'Draft'
            || collect($this->state['terms'])->contains('spkId', $id)) {
            $this->fail('Pilih Mandor hanya pada draf paket sebelum BA RP/pembayaran.');
        }
        $d['mandor'] = $data['name'];
        $d['masterMandor'] = ['id' => $data['id'], 'version' => (int) $data['version']];
        $v['address'] = $data['address'];
        $v['payeeVersion'] = (int) $data['payeeVersion'];
        $v['masterMandor'] = ['id' => $data['id'], 'version' => (int) $data['version']];
        $this->put('deals', $d);
        $this->put('spks', $v);
        $this->log('Mandor dipilih dari master', 'Identitas dan rekening disalin sebagai versi penerima SPK.', '/spk/'.$id);

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
        $this->assertBappBeforePayment($t);
        if (! self::legacyReviewApproved($t)) {
            $this->fail('Menunggu persetujuan Mgr Finance & Settlement untuk kasbon aktif.');
        }
        $this->financePaymentEligible($t);
        if (isset($t['scan']) && ($t['scan']['status'] ?? '') !== 'Valid') {
            $this->fail('Scan sudah tersedia; verifikasi berkas sebelum APM.');
        }
        if (($t['financePayment']['status'] ?? '') !== 'Diverifikasi' || ($t['financePayment']['decision']['decision'] ?? '') !== 'Setujui'
            || ($t['financePayment']['decision']['kasbonId'] ?? '') !== ($t['kasbon']['id'] ?? null) || empty($t['transferProof']['id']) || ($t['transferProof']['kasbonId'] ?? '') !== ($t['kasbon']['id'] ?? null)
            || ! $this->kasbonSummaryMatches($t, $t['kasbon'] ?? [])) {
            $this->fail('Kasbon terbit, persetujuan Finance sesuai kasbon dan bukti transfer wajib sebelum APM.');
        }
    }
}
