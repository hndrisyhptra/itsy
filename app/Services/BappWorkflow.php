<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Completion records describe the saved work, independently of financial approval. */
trait BappWorkflow
{
    public static function requiresBarp(array $t): bool
    {
        return ($t['barpFlow'] ?? '') === 'attest-before-bapp-v1';
    }

    public static function barpCurrent(array $t): bool
    {
        $a = $t['barpAttestation'] ?? [];

        return self::requiresBarp($t) && ($a['status'] ?? '') === 'Sah'
            && ! empty($a['by']) && ! empty($a['at']) && isset($a['source'])
            && self::bappJson($a['source']) === self::bappJson(self::bappBasis($t));
    }

    private function invalidateBarp(array &$t): void
    {
        if (self::requiresBarp($t) && isset($t['barpAttestation']) && ! self::barpCurrent($t)) {
            $t['barpAttestationHistory'][] = [...$t['barpAttestation'], 'invalidatedAt' => now()->toISOString(), 'invalidatedBy' => $this->state['actorUserId'] ?? '', 'reason' => 'BA RP berubah'];
            unset($t['barpAttestation']);
        }
    }

    public static function barpKasbonReturned(array $t): bool
    {
        $history = $t['history'] ?? [];
        $last = end($history);

        return self::usesSettlementPayment($t) && ! empty($t['kasbon']['id'])
            && ($last['kasbonId'] ?? '') === $t['kasbon']['id']
            && in_array($last['decision'] ?? '', ['Tolak', 'Kembalikan'], true);
    }

    /** A genuine returned current-kasbon cycle may repair its work documents without resubmitting yet. */
    public static function bappStatusAllowed(array $t): bool
    {
        return in_array($t['status'], ['Draft', 'Diajukan', 'Disetujui'], true)
            || ($t['status'] === 'Dikembalikan' && self::barpKasbonReturned($t)
                && empty($t['approvals']) && ! self::settlementRecorded($t) && ($t['paid'] ?? 0) == 0);
    }

    private function attestBarp(array $input): null
    {
        $this->admin();
        $t = $this->record('terms', $input['id'] ?? '');
        if (($t['workflowVersion'] ?? 1) < 3 || ! $this->canReviseSubmission($t) || (! empty($t['kasbon']) && ! self::barpKasbonReturned($t)) || ! empty($t['approvals']) || self::settlementRecorded($t) || ($t['paid'] ?? 0) > 0) {
            $this->fail('BA RP hanya dapat disahkan Admin sebelum pengajuan atau setelah dikembalikan/ditolak dan belum dibayar.');
        }
        $spk = $this->record('spks', $t['spkId']);
        $deal = $this->record('deals', $spk['dealId']);
        $pp = $this->record('projects', $deal['projectId']);
        if ($spk['status'] !== 'Sah' || $deal['status'] !== 'Sah' || $pp['status'] !== 'Sah') {
            $this->fail('PP, BA KH dan SPK harus Sah sebelum pengesahan BA RP.');
        }
        $t['barpFlow'] = 'attest-before-bapp-v1';
        $t['completionFlow'] = 'bapp-each-payment-v2';
        $this->put('terms', $t);
        $this->saveTerm($input, false);
        $t = $this->record('terms', $t['id']);
        BarpTemplate::save($this->state, $t, $t, true);
        if (self::barpCurrent($t)) {
            $this->fail('BA RP sudah Sah sesuai data tersimpan.');
        }
        $t['barpAttestation'] = ['status' => 'Sah', 'source' => self::bappBasis($t), 'at' => now()->toISOString(), 'by' => $this->state['actorUserId'] ?? ''];
        $this->put('terms', $t);
        $this->log('BA RP disahkan', 'BA RP pengajuan '.$t['number'], '/ba-rp/'.$t['id']);

        return null;
    }

    public static function earlyBapp(array $b): bool
    {
        return in_array($b['completionPolicy'] ?? '', ['barp-before-approval-v1', 'barp-each-payment-v2'], true);
    }

    public static function requiresBapp(array $t): bool
    {
        return ($t['completionFlow'] ?? '') === 'bapp-each-payment-v2'
            || (($t['completionFlow'] ?? '') === 'bapp-before-payment-v1' && $t['type'] === 'Final');
    }

    public static function bappBasis(array $t): array
    {
        return ['spkId' => $t['spkId'], 'type' => $t['type'], 'actuals' => $t['actuals'], 'barp' => $t['barp'] ?? null, 'moneyPolicy' => $t['moneyPolicy'] ?? null];
    }

    public static function bappCurrent(array $b, array $t): bool
    {
        return ($b['termId'] ?? '') === $t['id'] && ($b['status'] ?? '') === 'Sah'
            && (! self::requiresBarp($t) || self::barpCurrent($t))
            && self::earlyBapp($b)
            && isset($b['source']) && self::bappJson($b['source']) === self::bappJson(self::bappBasis($t));
    }

    private static function bappJson(mixed $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }

    private function ensureBapp(array $t, bool $early = false, ?string $id = null): array
    {
        $b = collect($this->state['bapps'])->firstWhere('termId', $t['id']);
        if (! $b) {
            if ($id && $this->existing('bapps', $id)) {
                $this->fail('ID BA PP sudah digunakan dokumen lain.');
            }
            $number = 'BAPP/'.$this->record('spks', $t['spkId'])['no'].(($t['completionFlow'] ?? '') === 'bapp-each-payment-v2' ? '/T'.$t['number'] : '');
            if (mb_strlen($number) > 150) {
                $number = 'BAPP/'.$t['id'].'/T'.$t['number'];
            }
            $b = ['id' => $id ?? 'bapp-'.Str::uuid(), 'termId' => $t['id'], 'no' => $number, 'status' => 'Draft', ...(self::requiresBarp($t) ? ['date' => $t['barp']['date'] ?? ''] : []), 'evidence' => '', 'released' => [], 'releaseHistory' => []];
        }
        if ($early && ! isset($b['completionPolicy']) && $b['status'] !== 'Sah') {
            $b['completionPolicy'] = 'barp-before-approval-v1';
        }
        if (self::earlyBapp($b) && $b['status'] === 'Sah' && ! self::bappCurrent($b, $t)) {
            $b['attestationHistory'][] = ['source' => $b['source'], 'at' => $b['attestedAt'], 'by' => $b['attestedBy'], 'invalidatedAt' => now()->toISOString(), 'invalidatedBy' => $this->state['actorUserId'] ?? '', 'reason' => 'BARP berubah'];
            $b['status'] = 'Draft';
            unset($b['source'], $b['attestedAt'], $b['attestedBy']);
        }
        if ($early && $b['status'] !== 'Sah' && ($t['completionFlow'] ?? '') === 'bapp-each-payment-v2') {
            $b['completionPolicy'] = 'barp-each-payment-v2';
        }
        $this->put('bapps', $b);

        return $b;
    }

    private function prepareBapp(string $id, ?string $resultId = null): string
    {
        $this->admin();
        $t = $this->record('terms', $id);
        if (($t['workflowVersion'] ?? 1) < 3 || ! self::bappStatusAllowed($t) || $this->termTotal($t) <= 0 || empty($t['barp']) || self::settlementRecorded($t) || ($t['paid'] ?? 0) > 0) {
            $this->fail('Simpan BA RP dengan volume positif sebelum menyiapkan BA PP.');
        }
        if (self::requiresBarp($t) && ! self::barpCurrent($t)) {
            $this->fail('Sahkan BA RP sebelum membuat BA PP.');
        }
        $t['completionFlow'] = 'bapp-each-payment-v2';
        $b = $this->ensureBapp($t, true, $resultId);
        if (self::earlyBapp($b)) {
            $this->put('terms', $t);
        }
        $this->log('BA PP disiapkan dari BA RP', $b['no'], '/ba-pp/'.$b['id']);

        return $b['id'];
    }

    private function assertBappBeforePayment(array $t): void
    {
        if (! self::requiresBapp($t)) {
            return;
        }
        $b = collect($this->state['bapps'])->firstWhere('termId', $t['id']);
        if (! $b || ! self::bappCurrent($b, $t)) {
            $this->fail('Sahkan BA PP sesuai BA RP tersimpan sebelum mengajukan atau memproses pembayaran.');
        }
    }
}
