<?php

namespace App\Services;

use App\Support\WholeRupiah;

/** User-specified withholding on saved Mandor service actuals only; not a tax-rate lookup. */
final class KasbonSettlement
{
    public const LEGACY_POLICY = 'mandor-service-pph25-v1';

    public const FINAL_ROUND_POLICY = 'mandor-service-pph25-round-v2';

    public const SERVICE_NET_ROUND_POLICY = 'mandor-service-pph25-service-round-v3';

    public const POLICY = 'mandor-service-pph25-tax-round-v4';

    private const POLICIES = [self::POLICY, self::SERVICE_NET_ROUND_POLICY, self::FINAL_ROUND_POLICY, self::LEGACY_POLICY];

    public static function quote(array $deal, array $term, string $policy = self::POLICY): array
    {
        if (! in_array($policy, self::POLICIES, true)) {
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
        if ($policy === self::POLICY) {
            // ROUND only total Jasa PPh; subtract it without rounding any other value.
            $tax = bcdiv(bcadd(bcmul($service, '0.025', 5), '0.5', 5), '1', 0);
            $net = bcsub($gross, $tax, 2);
        } elseif ($policy === self::SERVICE_NET_ROUND_POLICY) {
            // Round total Jasa after withholding once; leave Material and the final sum intact.
            $serviceNet = bcdiv(bcadd(bcmul($service, '0.975', 5), '0.5', 5), '1', 0);
            $net = bcadd($material, $serviceNet, 2);
        } else {
            // Frozen issued v1/v2 quotes keep their original arithmetic.
            $payable = bcadd($material, bcmul($service, '0.975', 5), 5);
            $net = bcdiv(bcadd($payable, $policy === self::FINAL_ROUND_POLICY ? '0.5' : '0', 5), '1', 0);
        }

        return ['policy' => $policy, 'gross' => (float) $gross, 'material' => (float) $material, 'service' => (float) $service, 'withheld' => (float) bcsub($gross, $net, 2), 'net' => (float) $net];
    }

    /** Exact component split of an already canonical quote, without applying tax again. */
    public static function components(array $quote): array
    {
        $parts = ['service' => bcsub((string) $quote['net'], (string) $quote['material'], 2), 'material' => (string) $quote['material']];

        return array_filter($parts, fn ($amount) => bccomp($amount, '0', 2) > 0);
    }

    public static function due(array $term, float $gross): float
    {
        return in_array($term['kasbon']['settlement']['policy'] ?? '', self::POLICIES, true) ? (float) $term['kasbon']['amount'] : $gross;
    }

    public static function matches(array $deal, array $term, array $summary, float $gross): bool
    {
        if (! isset($summary['settlement'])) {
            return bccomp((string) ($summary['amount'] ?? -1), (string) $gross, 2) === 0;
        }
        $policy = $summary['settlement']['policy'] ?? '';
        if (! in_array($policy, self::POLICIES, true)) {
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
