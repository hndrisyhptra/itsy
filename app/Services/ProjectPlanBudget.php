<?php

namespace App\Services;

use App\Support\WholeRupiah;
use Illuminate\Validation\ValidationException;

final class ProjectPlanBudget
{
    public const MATERIAL_REVENUE_POLICY = 'mandor-material-revenue-v1';

    public const OWNER_MATERIAL_POLICY = 'selected-owner-material-nonstock-v2';

    public const EDITABLE_NONSTOCK_POLICY = 'selected-owner-material-nonstock-v3';

    public const CATEGORIES = ['mandor' => 'Jasa Mandor', 'material' => 'Material Non Stock', 'bop' => 'BOP', 'permit' => 'Perizinan (Preliminary) Reimburse'];

    public const PRINT_ROLES = ['Admin Konstruksi', 'PM Konstruksi', 'Admin Area', 'Mgr Deployment', 'Mgr Bussiness Support', 'Mgr Project Support', 'Head of PMO', ...WorkUnitAccess::OPS_APPROVERS];

    public static function apply(array $p, array $owners, bool $submit, bool $adoptMaterialRevenue = false): array
    {
        $whole = WholeRupiah::enabled($p);
        $number = fn ($n, $volume = false) => ! $volume && $whole ? WholeRupiah::round(self::number($n)) : self::number($n, $volume);
        $product = fn ($q, $price) => self::product($q, $price, $whole);
        $input = $p['planBudget'] ?? [];
        if (! is_array($input)) {
            self::fail('Form anggaran tidak valid.');
        }
        $b = ['version' => 2, 'mandor' => [], 'materials' => [], 'bop' => [], 'usage' => [], 'permit' => $number($input['permit'] ?? $p['costs']['permit'] ?? 0)];
        // Explicit editable saves adopt the rule; maintenance retains each saved policy.
        $editableMaterials = $adoptMaterialRevenue || ($input['marginPolicy'] ?? null) === self::EDITABLE_NONSTOCK_POLICY;
        $selectedMaterial = $editableMaterials || ($input['marginPolicy'] ?? null) === self::OWNER_MATERIAL_POLICY;
        $materialRevenue = $selectedMaterial || ($input['marginPolicy'] ?? null) === self::MATERIAL_REVENUE_POLICY;
        if ($materialRevenue) {
            $b['marginPolicy'] = $editableMaterials ? self::EDITABLE_NONSTOCK_POLICY : ($selectedMaterial ? self::OWNER_MATERIAL_POLICY : self::MATERIAL_REVENUE_POLICY);
        }
        $excluded = [];
        if ($editableMaterials) {
            $ids = $input['excludedMaterialRowIds'] ?? [];
            $allowed = array_fill_keys(array_column(array_filter($owners, fn ($row) => $row['m'] !== null), 'rowId'), true);
            if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 500) {
                self::fail('Daftar penghapusan Material Non Stock tidak valid.');
            }
            foreach ($ids as $id) {
                if (! is_string($id) || ! isset($allowed[$id]) || isset($excluded[$id])) {
                    self::fail('Item Material Non Stock yang dihapus harus berasal dari BOQ PP ini dan tidak duplikat.');
                }
                $excluded[$id] = true;
            }
            $b['excludedMaterialRowIds'] = array_keys($excluded);
        }
        $revenueMaterial = 0.0;
        $savedMaterials = ! $adoptMaterialRevenue && is_array($input['materials'] ?? null) ? array_column($input['materials'], null, 'sourceRowId') : [];
        $rows = $input['mandor'] ?? array_map(fn ($r) => ['rowId' => $r['rowId'], 'qtyM' => $r['qm'], 'qtyS' => $r['qs'], 'priceM' => 0, 'priceS' => 0], $owners);
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== count($owners) || count(array_unique(array_column($rows, 'rowId'))) !== count($owners)) {
            self::fail('Designator BOQ Mandor harus sesuai BOQ Plan.');
        }
        $mandorByRow = array_column($rows, null, 'rowId');
        $totals = array_fill_keys(['ownerM', 'ownerS', 'mandorM', 'mandorS', 'material', 'bop'], 0.0);
        foreach ($owners as $owner) {
            $totals['ownerM'] = self::add($totals['ownerM'], $product($owner['qm'], $owner['m'] ?? 0));
            $totals['ownerS'] = self::add($totals['ownerS'], $product($owner['qs'], $owner['s'] ?? 0));
            $row = $mandorByRow[$owner['rowId']] ?? null;
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
            if ($whole) {
                $r['priceM'] = WholeRupiah::round($r['priceM']);
                $r['priceS'] = WholeRupiah::round($r['priceS']);
            }
            $b['mandor'][] = $r;
            $totals['mandorM'] = self::add($totals['mandorM'], $product($r['qtyM'], $r['priceM']));
            $totals['mandorS'] = self::add($totals['mandorS'], $product($r['qtyS'], $r['priceS']));
            if ($selectedMaterial && $r['qtyM'] > 0 && $r['priceM'] > 0 && $owner['m'] !== null) {
                $revenueMaterial = self::add($revenueMaterial, $product($r['qtyM'], $owner['m']));
                if (isset($excluded[$owner['rowId']])) {
                    continue;
                }
                $saved = $savedMaterials[$owner['rowId']] ?? [];
                $b['materials'][] = ['id' => 'material-mandor-'.(count($b['materials']) + 1), 'sourceRowId' => $owner['rowId'],
                    'name' => $owner['name'] ?? $saved['name'] ?? $owner['rowId'], 'unit' => $owner['unit'] ?? $saved['unit'] ?? '',
                    'qty' => $r['qtyM'], 'price' => $r['priceM']] + ($editableMaterials ? ['code' => $owner['code'] ?? $saved['code'] ?? $owner['rowId']] : []);
            }
        }
        foreach ($selectedMaterial ? $b['materials'] : self::rows($input['materials'] ?? []) as $row) {
            $r = ['id' => $row['id'], 'name' => self::text($row['name'] ?? '', 1000), 'qty' => $number($row['qty'] ?? null, true), 'unit' => self::text($row['unit'] ?? '', 100), 'price' => $number($row['price'] ?? null)];
            if (! $selectedMaterial && $submit && (! $r['name'] || ! $r['unit'] || ! $r['qty'])) {
                self::fail('Lengkapi Material Non Stock, satuan dan volume positif.');
            }
            if (! $selectedMaterial) {
                $b['materials'][] = $r;
            }
            $totals['material'] = self::add($totals['material'], $product($r['qty'], $r['price']));
        }
        if ($editableMaterials) {
            $materials = $input['materials'] ?? [];
            if (! is_array($materials) || ! array_is_list($materials) || count($materials) > 700) {
                self::fail('Maksimal 500 item sumber dan 200 tambahan Material Non Stock.');
            }
            $manual = [];
            foreach ($materials as $row) {
                if (! is_array($row)) {
                    self::fail('Baris Material Non Stock tidak valid.');
                }
                if (! array_key_exists('sourceRowId', $row)) {
                    $manual[] = $row;
                }
            }
            foreach (self::rows($manual) as $row) {
                if (str_starts_with($row['id'], 'material-mandor-')) {
                    self::fail('ID tambahan Material Non Stock memakai kode yang dicadangkan.');
                }
                $r = ['id' => $row['id'], 'name' => self::text($row['name'] ?? '', 1000), 'qty' => $number($row['qty'] ?? null, true), 'unit' => self::text($row['unit'] ?? '', 100), 'price' => $number($row['price'] ?? null)];
                if ($submit && (! $r['name'] || ! $r['unit'] || ! $r['qty'])) {
                    self::fail('Lengkapi designator Material Non Stock tambahan, satuan dan volume positif.');
                }
                $b['materials'][] = $r;
                $totals['material'] = self::add($totals['material'], $product($r['qty'], $r['price']));
            }
        }
        foreach (self::rows($input['bop'] ?? []) as $row) {
            $r = ['id' => $row['id'], 'description' => self::text($row['description'] ?? '', 1000), 'unit' => self::text($row['unit'] ?? '', 100), 'qty' => $number($row['qty'] ?? null, true), 'total' => $number($row['total'] ?? null)];
            if ($submit && (! $r['description'] || ! $r['unit'] || ! $r['qty'])) {
                self::fail('Lengkapi uraian BOP, satuan dan jumlah positif.');
            }
            $b['bop'][] = $r;
            $totals['bop'] = self::add($totals['bop'], $r['total']);
        }
        $totals['ownerTotal'] = self::add($totals['ownerM'], $totals['ownerS']);
        $totals['mandor'] = self::add($totals['mandorM'], $totals['mandorS']);
        $totals['permit'] = $b['permit'];
        $totals['total'] = self::add(self::add($selectedMaterial ? $totals['mandorS'] : $totals['mandor'], $totals['material']), $totals['bop']);
        if ($totals['total'] > 1000000000000 || $totals['ownerTotal'] > 1000000000000) {
            self::fail('Total anggaran di luar batas yang didukung.');
        }
        $totals['revenue'] = self::add($totals['ownerS'], $selectedMaterial ? $revenueMaterial : ($materialRevenue ? $totals['mandorM'] : 0));
        $totals['cogs'] = $totals['total'];
        $totals['grossProfit'] = self::add($totals['revenue'], -$totals['cogs']);
        $totals['margin'] = $totals['revenue'] ? round((float) bcdiv(bcmul((string) $totals['grossProfit'], '100', 6), (string) $totals['revenue'], 6), 2) : null;
        $totals['usage'] = array_fill_keys(array_keys(self::CATEGORIES), 0.0);
        // Point IV.5 is a server-owned recap, not a payment/installment schedule.
        // Ignore old/client usage rows, including term/date/amount and version overrides.
        foreach ([['mandor', 's', 'mandorS'], ['mandor', 'm', 'mandorM'], ['material', null, 'material'], ['bop', null, 'bop'], ['permit', null, 'permit']] as [$category, $component, $source]) {
            if ($selectedMaterial && $source === 'mandorM') {
                continue;
            }
            if ($totals[$source] <= 0) {
                continue;
            }
            $r = ['id' => 'usage-'.$category.($component ? '-'.$component : ''), 'category' => $category, 'amount' => $totals[$source]];
            if ($component) {
                $r['component'] = $component;
            }
            $b['usage'][] = $r;
            $totals['usage'][$category] = self::add($totals['usage'][$category], $r['amount']);
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
        $signatureRows = WorkUnitAccess::operation($p) ? self::defaultSignatures($p) : ($p['signatureRows'] ?? self::defaultSignatures());
        foreach (self::rows($signatureRows, 20) as $r) {
            if (! in_array($r['label'] ?? '', ['Dibuat Oleh', 'Diperiksa Oleh', 'Disetujui Oleh'], true) || ! in_array($r['role'] ?? '', self::PRINT_ROLES, true)) {
                self::fail('Baris penandatangan tidak valid.');
            }
        }
        $p['signatureRows'] = array_map(fn ($r) => ['id' => $r['id'], 'label' => $r['label'], 'role' => $r['role'], 'job' => self::text($r['job'] ?? '', 250)], $signatureRows);
        if ($whole) {
            $b['moneyPolicy'] = WholeRupiah::POLICY;
        }
        $p['planBudget'] = $b;
        $p['planBudgetTotals'] = $totals;
        $p['costs'] = array_intersect_key($totals, array_flip(['mandor', 'material', 'bop', 'permit']));
        if ($selectedMaterial) {
            $p['costs']['mandor'] = $totals['mandorS'];
        }

        return $p;
    }

    public static function defaultSignatures(array $p = []): array
    {
        if (WorkUnitAccess::operation($p)) {
            return array_map(fn ($role, $i) => ['id' => 'signature-'.$i, 'label' => $i === 0 ? 'Dibuat Oleh' : ($i === 3 ? 'Disetujui Oleh' : 'Diperiksa Oleh'), 'role' => $role, 'job' => $role], [WorkUnitAccess::OPS_APPROVERS[0], ...array_slice(WorkUnitAccess::OPS_APPROVERS, 2)], range(0, 3));
        }
        $rows = json_decode(file_get_contents(resource_path('data/project-plan-template.json')), true, flags: JSON_THROW_ON_ERROR)['signatures'];

        return array_map(fn ($row, $i) => ['id' => 'signature-'.$i] + $row, $rows, array_keys($rows));
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

    private static function product(float|int $q, float|int $p, bool $whole = false): float
    {
        return WholeRupiah::round(bcmul(sprintf('%.3F', $q), sprintf('%.2F', $p), 6), $whole);
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
