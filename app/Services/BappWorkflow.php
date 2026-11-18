<?php

namespace App\Services;

use Illuminate\Support\Str;

/** Completion records describe the saved work, independently of financial approval. */
trait BappWorkflow
{
    public static function bappBasis(array $t): array
    {
        return ['spkId' => $t['spkId'], 'type' => $t['type'], 'actuals' => $t['actuals'], 'barp' => $t['barp'] ?? null, 'moneyPolicy' => $t['moneyPolicy'] ?? null];
    }

    public static function bappCurrent(array $b, array $t): bool
    {
        return ($b['termId'] ?? '') === $t['id'] && ($b['status'] ?? '') === 'Sah'
            && ($b['completionPolicy'] ?? '') === 'barp-before-approval-v1'
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
            $b = ['id' => $id ?? 'bapp-'.Str::uuid(), 'termId' => $t['id'], 'no' => 'BAPP/'.$this->record('spks', $t['spkId'])['no'], 'status' => 'Draft', 'evidence' => '', 'released' => [], 'releaseHistory' => []];
        }
        if ($early && ! isset($b['completionPolicy']) && $b['status'] !== 'Sah') {
            $b['completionPolicy'] = 'barp-before-approval-v1';
        }
        if (($b['completionPolicy'] ?? '') === 'barp-before-approval-v1' && $b['status'] === 'Sah' && ! self::bappCurrent($b, $t)) {
            $b['attestationHistory'][] = ['source' => $b['source'], 'at' => $b['attestedAt'], 'by' => $b['attestedBy'], 'invalidatedAt' => now()->toISOString(), 'invalidatedBy' => $this->state['actorUserId'] ?? '', 'reason' => 'BARP berubah'];
            $b['status'] = 'Draft';
            unset($b['source'], $b['attestedAt'], $b['attestedBy']);
        }
        $this->put('bapps', $b);

        return $b;
    }

    private function prepareBapp(string $id, ?string $resultId = null): string
    {
        $this->admin();
        $t = $this->record('terms', $id);
        if (($t['workflowVersion'] ?? 1) < 3 || $t['type'] !== 'Final' || ! in_array($t['status'], ['Draft', 'Diajukan', 'Disetujui'], true) || $this->termTotal($t) <= 0 || empty($t['barp']) || self::settlementRecorded($t) || ($t['paid'] ?? 0) > 0) {
            $this->fail('Simpan BA RP Final dengan volume positif sebelum menyiapkan BA PP.');
        }
        $b = $this->ensureBapp($t, true, $resultId);
        if (($b['completionPolicy'] ?? '') === 'barp-before-approval-v1') {
            $t['completionFlow'] = 'bapp-before-payment-v1';
            $this->put('terms', $t);
        }
        $this->log('BA PP disiapkan dari BA RP', $b['no'], '/ba-pp/'.$b['id']);

        return $b['id'];
    }

    private function assertBappBeforePayment(array $t): void
    {
        if ($t['type'] !== 'Final' || ($t['completionFlow'] ?? '') !== 'bapp-before-payment-v1') {
            return;
        }
        $b = collect($this->state['bapps'])->firstWhere('termId', $t['id']);
        if (! $b || ! self::bappCurrent($b, $t)) {
            $this->fail('Sahkan BA PP sesuai BA RP tersimpan sebelum mengajukan atau memproses pembayaran Final.');
        }
    }
}
