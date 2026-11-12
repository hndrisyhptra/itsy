<?php

namespace App\Support;

use App\Services\PrototypeDomain;
use Illuminate\Support\Facades\DB;

final class WholeRupiah
{
    public const POLICY = 'idr-trunc-v1';

    public const HALF_POLICY = 'idr-whole-v1';

    // Only operational currency. Quantities, percentages and original workbook comparisons retain their precision.
    public const COLUMNS = [
        'designator_package_prices' => ['price' => false],
        'khs_prices' => ['material_price' => true, 'service_price' => true],
        'project_plans' => ['budget' => false],
        'project_plan_costs' => ['amount' => false],
        'boq_components' => ['official_unit_price' => false],
        'contract_components' => ['khs_ceiling_snapshot' => false, 'sph_price' => false, 'offered_price' => false, 'agreed_price' => false],
        'payment_terms' => ['approved_amount' => true],
        'apm_settlements' => ['amount' => false],
        'partial_payment_requests' => ['amount' => false],
        'finance_kasbons' => ['amount' => false],
    ];

    public static function enabled(array $document): bool
    {
        return in_array($document['moneyPolicy'] ?? null, [self::POLICY, self::HALF_POLICY], true);
    }

    public static function round(string|float|int $value, bool $whole = true): float
    {
        $negative = bccomp((string) $value, '0', 6) < 0;
        $absolute = $negative ? bcmul((string) $value, '-1', 6) : (string) $value;
        $result = $whole ? bcdiv($absolute, '1', 0) : bcdiv(bcadd($absolute, '0.005', 6), '1', 2);

        return (float) ($negative ? '-'.$result : $result);
    }

    public static function amount(string|float|int $value, array $document): float
    {
        if (($document['moneyPolicy'] ?? null) === self::HALF_POLICY) {
            return (float) bcdiv(bcadd((string) $value, '0.5', 6), '1', 0);
        }

        return self::round($value, self::enabled($document));
    }

    /** Fail before any DDL. Do not let MySQL implicitly round a previously recorded bill or settlement. */
    public static function assertIntegralStorage(): void
    {
        $conflicts = [];
        foreach (self::COLUMNS as $table => $columns) {
            $query = DB::table($table)->where(function ($q) use ($columns) {
                foreach ($columns as $column => $_) {
                    $q->orWhereRaw($column.' <> ROUND('.$column.', 0)');
                }
            });
            if ($query->exists()) {
                $conflicts[] = $table;
            }
        }
        // A pending historical invoice has no approved_amount yet. Inspect its own saved agreement/actuals,
        // in bounded pages, so approval after migration cannot silently truncate a fractional bill.
        DB::table('payment_terms as t')->join('work_orders as w', 'w.id_spk', '=', 't.work_order_id')
            ->join('contracts as c', 'c.id_contract', '=', 'w.contract_id')
            ->whereIn('t.status', ['SUBMITTED', 'APPROVED'])
            ->select(['t.id_payment_term', 't.document_data as term_data', 'w.source_id as spk_source', 'c.document_data as deal_data'])
            ->orderBy('t.id_payment_term')->chunk(200, function ($rows) use (&$conflicts) {
                foreach ($rows as $r) {
                    $term = json_decode($r->term_data, true, 512, JSON_THROW_ON_ERROR);
                    $deal = json_decode($r->deal_data, true, 512, JSON_THROW_ON_ERROR);
                    if (self::enabled($term)) {
                        continue;
                    }
                    $domain = app(PrototypeDomain::class)->load(['deals' => [$deal], 'spks' => [['id' => $r->spk_source, 'dealId' => $deal['id']]]]);
                    $amount = $domain->termTotal($term);
                    if (self::round($amount) != $amount) {
                        $conflicts[] = 'payment_terms (tagihan snapshot historis)';

                        return false;
                    }
                }
            });
        if ($conflicts) {
            throw new \RuntimeException('Migrasi rupiah bulat dihentikan sebelum perubahan: nominal pecahan ditemukan pada '.implode(', ', $conflicts).'. Cadangkan dan rekonsiliasi nominal/riwayat terkait secara terpisah; jangan membulatkan pembayaran lama otomatis.');
        }
    }
}
