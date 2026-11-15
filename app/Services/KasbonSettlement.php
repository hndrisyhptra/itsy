<?php

namespace App\Services;

use App\Support\WholeRupiah;

/** User-specified withholding on saved Mandor service actuals only; not a tax-rate lookup. */
final class KasbonSettlement
{
    public const POLICY = 'mandor-service-pph25-v1';

    public static function quote(array $deal, array $term): array
    {
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
        // Cut fractional Rupiah from the final payable, never round it upward.
        $net = bcdiv(bcadd($material, bcmul($service, '0.975', 5), 5), '1', 0);

        return ['policy' => self::POLICY, 'gross' => (float) $gross, 'material' => (float) $material, 'service' => (float) $service, 'withheld' => (float) bcsub($gross, $net, 2), 'net' => (float) $net];
    }

    public static function due(array $term, float $gross): float
    {
        return ($term['kasbon']['settlement']['policy'] ?? '') === self::POLICY ? (float) $term['kasbon']['amount'] : $gross;
    }

    public static function matches(array $deal, array $term, array $summary, float $gross): bool
    {
        if (! isset($summary['settlement'])) {
            return bccomp((string) ($summary['amount'] ?? -1), (string) $gross, 2) === 0;
        }
        $quote = self::quote($deal, $term);
        if (($summary['settlement']['policy'] ?? '') !== self::POLICY || bccomp((string) ($summary['amount'] ?? -1), (string) $quote['net'], 2) !== 0) {
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
