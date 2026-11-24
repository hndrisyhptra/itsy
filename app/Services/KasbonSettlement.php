<?php

namespace App\Services;

use App\Support\WholeRupiah;

/** User-specified withholding on saved Mandor service actuals only; not a tax-rate lookup. */
final class KasbonSettlement
{
    public const LEGACY_POLICY = 'mandor-service-pph25-v1';

    public const POLICY = 'mandor-service-pph25-round-v2';

    public static function quote(array $deal, array $term, string $policy = self::POLICY): array
    {
        if (! in_array($policy, [self::POLICY, self::LEGACY_POLICY], true)) {
            throw new \InvalidArgumentException('Kebijakan perhitungan kasbon tidak dikenali.');
        }
        $gross = $material = '0';
        foreach ($deal['rows'] as $r) {
            $a = $term['actuals'][$r['rowId']] ?? ['m' => 0, 's' => 0];
            $m = bcmul(sprintf('%.6F', $r['m'] ? $a['m'] : 0), sprintf('%.6F', $r['pm']), 6);
            $s = bcmul(sprintf('%.6F', $r['s'] ? $a['s'] : 0), sprintf('%.6F', $r['ps']), 6);
            $mAmount = WholeRupiah::amount($m, $term);
            $line = WholeRupiah::enabled($term) ? $mAmount + WholeRupiah::amount($s, $term) : WholeRupiah::amount(bcadd($m, $s, 6), $term);
            $material = bcadd($material, (string) $mAmount, 2);
            $gross = bcadd($gross, (string) $line, 2);
        }
        $service = bcsub($gross, $material, 2);
        // ROUND the final payable once, half-up. Preserve v1 truncation for issued snapshots.
        $payable = bcadd($material, bcmul($service, '0.975', 5), 5);
        $net = bcdiv(bcadd($payable, $policy === self::POLICY ? '0.5' : '0', 5), '1', 0);

        return ['policy' => $policy, 'gross' => (float) $gross, 'material' => (float) $material, 'service' => (float) $service, 'withheld' => (float) bcsub($gross, $net, 2), 'net' => (float) $net];
    }

    public static function due(array $term, float $gross): float
    {
        return in_array($term['kasbon']['settlement']['policy'] ?? '', [self::POLICY, self::LEGACY_POLICY], true) ? (float) $term['kasbon']['amount'] : $gross;
    }

    public static function matches(array $deal, array $term, array $summary, float $gross): bool
    {
        if (! isset($summary['settlement'])) {
            return bccomp((string) ($summary['amount'] ?? -1), (string) $gross, 2) === 0;
        }
        $policy = $summary['settlement']['policy'] ?? '';
        if (! in_array($policy, [self::POLICY, self::LEGACY_POLICY], true)) {
            return false;
        }
        $quote = self::quote($deal, $term, $policy);
        if (bccomp((string) ($summary['amount'] ?? -1), (string) $quote['net'], 2) !== 0) {
            return false;
        }
        foreach (['gross', 'material', 'service', 'withheld', 'net'] as $key) {
            if (! is_numeric($summary['settlement'][$key] ?? null) || bccomp((string) $summary['settlement'][$key], (string) $quote[$key], 2) !== 0) {
                return false;
            }
        }

        return $quote['net'] > 0;
    }
}
