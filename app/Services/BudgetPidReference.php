<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Exact, unit-scoped reference for PP authors. This is not a budget reservation. */
class BudgetPidReference
{
    public static function code(string $pid): string
    {
        return mb_strtoupper(trim($pid));
    }

    public function authorize(array $account, string $unit, string $branch): void
    {
        abort_unless(in_array($account['code'] ?? '', ['ADMIN_KONSTRUKSI', 'ADMIN_OPS', 'SUPERADMIN'], true)
            && in_array($unit, $account['workUnits'] ?? [], true)
            && in_array($branch, $account['branches'] ?? [], true), 403, 'Referensi PID hanya tersedia untuk Admin pada unit dan Branch penugasan.');
    }

    public function lookup(array $account, string $pid, string $unit, string $branch): array
    {
        $this->authorize($account, $unit, $branch);
        $pid = self::code($pid);
        $scope = ['pid' => $pid, 'unit' => $unit, 'branch' => $branch];
        $master = DB::table('budget_pids')->where('pid', $pid)->first(['id_budget_pid', 'pid', 'unit', 'branch_id', 'name', 'last_import_id', 'updated_at']);
        if (! $master) {
            return $scope + ['status' => 'not_found'];
        }
        if ($master->unit !== $unit) {
            // Do not reveal the other unit's name, values, batch or identities.
            return $scope + ['status' => 'unit_conflict'];
        }
        if ($master->branch_id === null) {
            return $scope + ['status' => 'branch_unmapped'];
        }
        $branchId = DB::table('branches')->where('name', $branch)->whereIn('id_branch', $account['branchIds'])->where('is_active', true)->whereNull('deleted_at')->value('id_branch');
        if ((int) $master->branch_id !== (int) $branchId) {
            return $scope + ['status' => 'branch_conflict'];
        }
        $items = DB::table('budget_pid_items')->where('budget_pid_id', $master->id_budget_pid)
            ->whereIn('category', ['TOTAL', 'Material', 'Non Material', 'Operasional'])->get(['category', 'available']);
        abort_unless($items->count() === 4, 503, 'Rincian budgeting PID belum lengkap. Hubungi Finance untuk memeriksa master.');
        $available = [];
        foreach ($items as $item) {
            $available[$item->category] = (int) $item->available;
        }

        return $scope + ['status' => 'found', 'name' => $master->name, 'import_id' => $master->last_import_id,
            'updated_at' => $master->updated_at, 'available' => $available];
    }

    /** Explicit editable saves only; old documents without selection incur no lookup or adoption. */
    public function prepare(array $input, ?array $old, array $branches = []): array
    {
        unset($input['_budgetPidReference']);
        $selection = $input['budgetPidSelection'] ?? null;
        unset($input['budgetPidSelection']);
        $sameCode = $old && is_string($input['projectCode'] ?? null) && self::code($input['projectCode']) === self::code($old['projectCode']);
        if (! empty($old['parentId']) || ($sameCode && empty($selection))) {
            return $input;
        }
        if (! is_string($input['projectCode'] ?? null)) {
            return $input;
        }
        $pid = self::code($input['projectCode']);
        if ($pid === '') {
            return $input; // Existing Header validation supplies the required-field message.
        }
        $unit = $input['unit'] ?? '';
        // Same lock order as imports/mapping. Fresh locking reads avoid a stale transaction snapshot.
        DB::table('budget_pid_controls')->where('id_budget_control', 1)->lockForUpdate()->first();
        $master = DB::table('budget_pids')->where('pid', $pid)->lockForUpdate()->first(['pid', 'unit', 'branch_id', 'name', 'last_import_id']);
        abort_if($master && $master->unit !== $unit, 422, 'ID Project sudah terdaftar pada unit kerja lain. Periksa ID Project dan Unit Kerja.');
        $branch = collect($branches)->firstWhere('name', $input['branch'] ?? '');
        $branchId = (int) str_replace('db-branch-', '', $branch['id'] ?? '0');
        abort_if($master && $master->branch_id !== null && (int) $master->branch_id !== $branchId, 422, 'ID Project dipetakan ke Branch lain. Periksa ID Project dan Branch PP.');
        $usable = $master && $master->branch_id !== null;
        if ($selection !== null) {
            abort_unless(is_array($selection) && ($selection['pid'] ?? null) === $pid && ($selection['unit'] ?? null) === $unit
                && ($selection['branch'] ?? null) === ($input['branch'] ?? null)
                && array_key_exists('importId', $selection) && $selection['importId'] === ($usable ? $master->last_import_id : null), 409, 'Referensi PID berubah atau tidak sesuai formulir. Periksa ulang PID sebelum menyimpan.');
        }
        if ($usable) {
            $input['name'] = $master->name;
        }

        return $input;
    }
}
