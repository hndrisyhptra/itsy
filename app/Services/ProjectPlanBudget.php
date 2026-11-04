<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

final class ProjectPlanBudget
{
    public const CATEGORIES = ['mandor' => 'Jasa Mandor', 'material' => 'Material Non Stock', 'bop' => 'BOP', 'permit' => 'Perizinan (Preliminary) Reimburse'];

    public const PRINT_ROLES = ['Admin Konstruksi', 'PM Konstruksi', 'Admin Area', 'Mgr Deployment', 'Mgr Bussiness Support', 'Head of PMO'];

    public static function apply(array $p, array $owners, bool $submit): array
    {
        $input = $p['planBudget'] ?? [];
        if (! is_array($input)) {
            self::fail('Form anggaran tidak valid.');
        }
        $b = ['version' => 1, 'mandor' => [], 'materials' => [], 'bop' => [], 'usage' => [], 'permit' => self::number($input['permit'] ?? $p['costs']['permit'] ?? 0), 'bopLimit' => isset($input['bopLimit']) ? self::number($input['bopLimit']) : null, 'bopReference' => self::text($input['bopReference'] ?? '', 500)];
        $rows = $input['mandor'] ?? array_map(fn ($r) => ['rowId' => $r['rowId'], 'qtyM' => $r['qm'], 'qtyS' => $r['qs'], 'priceM' => 0, 'priceS' => 0], $owners);
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== count($owners) || count(array_unique(array_column($rows, 'rowId'))) !== count($owners)) {
            self::fail('Designator BOQ Mandor harus sesuai BOQ Plan.');
        }
        $totals = array_fill_keys(['ownerM', 'ownerS', 'mandorM', 'mandorS', 'material', 'bop'], 0.0);
        foreach ($owners as $owner) {
            $totals['ownerM'] = self::add($totals['ownerM'], self::product($owner['qm'], $owner['m'] ?? 0));
            $totals['ownerS'] = self::add($totals['ownerS'], self::product($owner['qs'], $owner['s'] ?? 0));
            $row = collect($rows)->firstWhere('rowId', $owner['rowId']);
            if (! is_array($row)) {
                self::fail('Designator BOQ Mandor tidak ditemukan.');
            }
            $r = ['rowId' => $owner['rowId']];
            foreach (['qtyM', 'qtyS', 'priceM', 'priceS'] as $key) {
                $r[$key] = self::number($row[$key] ?? null, str_starts_with($key, 'qty'));
            }
            if ($r['qtyM'] > $owner['qm'] || $r['qtyS'] > $owner['qs'] || $r['priceM'] > ($owner['m'] ?? 0) || $r['priceS'] > ($owner['s'] ?? 0)) {
                self::fail('Harga atau volume BOQ Mandor melebihi BOQ Plan per komponen.');
            }
            $b['mandor'][] = $r;
            $totals['mandorM'] = self::add($totals['mandorM'], self::product($r['qtyM'], $r['priceM']));
            $totals['mandorS'] = self::add($totals['mandorS'], self::product($r['qtyS'], $r['priceS']));
        }
        foreach (self::rows($input['materials'] ?? []) as $row) {
            $r = ['id' => $row['id'], 'name' => self::text($row['name'] ?? '', 1000), 'qty' => self::number($row['qty'] ?? null, true), 'unit' => self::text($row['unit'] ?? '', 100), 'price' => self::number($row['price'] ?? null)];
            if ($submit && (! $r['name'] || ! $r['unit'] || ! $r['qty'])) {
                self::fail('Lengkapi Material Non Stock, satuan dan volume positif.');
            }
            $b['materials'][] = $r;
            $totals['material'] = self::add($totals['material'], self::product($r['qty'], $r['price']));
        }
        foreach (self::rows($input['bop'] ?? []) as $row) {
            $r = ['id' => $row['id'], 'description' => self::text($row['description'] ?? '', 1000), 'unit' => self::text($row['unit'] ?? '', 100), 'qty' => self::number($row['qty'] ?? null, true), 'total' => self::number($row['total'] ?? null)];
            if ($submit && (! $r['description'] || ! $r['unit'] || ! $r['qty'])) {
                self::fail('Lengkapi uraian BOP, satuan dan jumlah positif.');
            }
            $b['bop'][] = $r;
            $totals['bop'] = self::add($totals['bop'], $r['total']);
        }
        if ($b['bopLimit'] !== null && $totals['bop'] > $b['bopLimit']) {
            self::fail('Total BOP melebihi batas Cost Structure yang diisi.');
        }
        if ($submit && $totals['bop'] > 0 && ($b['bopLimit'] === null || ! $b['bopReference'])) {
            self::fail('Isi batas BOP dan referensi Cost Structure sebelum pengajuan.');
        }
        $totals['ownerTotal'] = self::add($totals['ownerM'], $totals['ownerS']);
        $totals['mandor'] = self::add($totals['mandorM'], $totals['mandorS']);
        $totals['permit'] = $b['permit'];
        $totals['total'] = self::add(self::add($totals['mandor'], $totals['material']), $totals['bop']);
        if ($totals['total'] > 1000000000000 || $totals['ownerTotal'] > 1000000000000) {
            self::fail('Total anggaran di luar batas yang didukung.');
        }
        $totals['revenue'] = $totals['ownerS'];
        $totals['cogs'] = $totals['total'];
        $totals['grossProfit'] = self::add($totals['revenue'], -$totals['cogs']);
        $totals['margin'] = $totals['revenue'] ? round((float) bcdiv(bcmul((string) $totals['grossProfit'], '100', 6), (string) $totals['revenue'], 6), 2) : null;
        $totals['usage'] = array_fill_keys(array_keys(self::CATEGORIES), 0.0);
        foreach (self::rows($input['usage'] ?? []) as $row) {
            $category = $row['category'] ?? '';
            if (! is_string($category) || ! isset(self::CATEGORIES[$category])) {
                self::fail('Kategori waktu penggunaan tidak valid.');
            }
            $r = ['id' => $row['id'], 'category' => $category, 'term' => self::text($row['term'] ?? '', 100), 'date' => self::text($row['date'] ?? '', 10), 'amount' => self::number($row['amount'] ?? null)];
            if ($r['date'] && ! self::date($r['date'])) {
                self::fail('Tanggal waktu penggunaan tidak valid.');
            }
            if ($submit && (! $r['term'] || ! self::date($r['date']) || ! $r['amount'])) {
                self::fail('Lengkapi termin, tanggal dan nilai positif pada waktu penggunaan.');
            }
            $b['usage'][] = $r;
            $totals['usage'][$category] = self::add($totals['usage'][$category], $r['amount']);
        }
        foreach (self::CATEGORIES as $category => $label) {
            if ($totals['usage'][$category] > $totals[$category] || ($submit && $totals['usage'][$category] !== $totals[$category])) {
                self::fail('Rincian waktu penggunaan '.$label.' harus sesuai total anggarannya.');
            }
        }
        $template = json_decode(file_get_contents(resource_path('data/project-plan-template.json')), true, flags: JSON_THROW_ON_ERROR);
        $risk = $p['riskLevel'] ?? '';
        if (! in_array($risk, $template['riskLevels'], true) && ($submit || $risk !== '')) {
            self::fail('Pilih tingkat risiko sesuai checklist template.');
        }
        $p['riskLevel'] = $risk;
        $p['risk'] = $risk;
        $p['communication'] = implode("\n", array_map(fn ($row) => implode(' | ', $row), $template['communicationRows']));
        $p['quality'] = implode("\n", $template['quality']);
        $p['closing'] = $template['closing'];
        $signatureRows = $p['signatureRows'] ?? self::defaultSignatures();
        foreach (self::rows($signatureRows, 20) as $r) {
            if (! in_array($r['label'] ?? '', ['Dibuat Oleh', 'Diperiksa Oleh', 'Disetujui Oleh'], true) || ! in_array($r['role'] ?? '', self::PRINT_ROLES, true)) {
                self::fail('Baris penandatangan tidak valid.');
            }
        }
        $p['signatureRows'] = array_map(fn ($r) => ['id' => $r['id'], 'label' => $r['label'], 'role' => $r['role'], 'job' => self::text($r['job'] ?? '', 250)], $signatureRows);
        $p['planBudget'] = $b;
        $p['planBudgetTotals'] = $totals;
        $p['costs'] = array_intersect_key($totals, array_flip(['mandor', 'material', 'bop', 'permit']));

        return $p;
    }

    public static function defaultSignatures(): array
    {
        return array_map(fn ($role, $i) => ['id' => 'signature-'.$i, 'label' => $i === 0 ? 'Dibuat Oleh' : ($i < 3 ? 'Diperiksa Oleh' : 'Disetujui Oleh'), 'role' => $role, 'job' => $role], self::PRINT_ROLES, array_keys(self::PRINT_ROLES));
    }

    private static function rows(mixed $rows, int $max = 200): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > $max) {
            self::fail('Maksimal '.$max.' baris per tabel anggaran.');
        }
        $ids = [];
        foreach ($rows as $r) {
            if (! is_array($r) || ! is_string($r['id'] ?? null) || ! preg_match('/^[a-zA-Z0-9-]{3,100}$/D', $r['id']) || in_array($r['id'], $ids, true)) {
                self::fail('Baris anggaran harus memiliki ID unik.');
            }
            $ids[] = $r['id'];
        }

        return $rows;
    }

    private static function number(mixed $n, bool $volume = false): float
    {
        $scale = $volume ? 1000 : 100;
        if ((! is_int($n) && ! is_float($n)) || ! is_finite((float) $n) || $n < 0 || $n > ($volume ? 1000000000 : 1000000000000) || abs($n * $scale - round($n * $scale)) > 0.0001) {
            self::fail('Angka anggaran harus nonnegatif; uang maksimal dua desimal, volume tiga desimal.');
        }

        return (float) $n;
    }

    private static function text(mixed $v, int $max): string
    {
        if (! is_string($v) || mb_strlen($v) > $max) {
            self::fail('Teks anggaran tidak valid atau terlalu panjang.');
        }

        return trim($v);
    }

    private static function date(string $v): bool
    {
        if (! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $v)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $v);

        return $date && $date->format('Y-m-d') === $v;
    }

    private static function product(float|int $q, float|int $p): float
    {
        return (float) bcdiv(bcadd(bcmul(sprintf('%.3F', $q), sprintf('%.2F', $p), 6), '0.005', 6), '1', 2);
    }

    private static function add(float $a, float $b): float
    {
        return (float) bcadd(sprintf('%.2F', $a), sprintf('%.2F', $b), 2);
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['project' => $message]);
    }
}
