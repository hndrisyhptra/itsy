<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Read-only aggregates for dashboard viewers; master CRUD remains Finance/Superadmin only. */
class DashboardBudget
{
    public function read(array $account, array $input): array
    {
        $unit = $input['unit'];
        $branch = trim($input['branch'] ?? '');
        abort_unless(in_array($unit, $account['workUnits'] ?? [], true), 403, 'Unit kerja di luar akses akun.');
        $branches = DB::table('branches')->whereIn('id_branch', $account['branchIds'] ?? [])->where('is_active', true)->whereNull('deleted_at')->get(['id_branch', 'name']);
        if ($branch !== '') {
            $branches = $branches->filter(fn ($b) => mb_strtoupper($b->name) === mb_strtoupper($branch));
            abort_if($branches->isEmpty(), 403, 'Branch di luar akses akun.');
        }
        $ids = $branches->pluck('id_branch')->all();
        $usage = DB::table('budget_pid_allocations')->where('unit', $unit)->whereIn('branch_id', $ids)->where('status', 'reserved')
            ->groupBy('pid_code', 'branch_id')->selectRaw('pid_code, branch_id, SUM(material) as material, SUM(service) as service, SUM(bop) as bop');
        $query = DB::table('budget_pids as p')->join('branches as b', 'b.id_branch', '=', 'p.branch_id')
            ->where('p.unit', $unit)->whereIn('p.branch_id', $ids)
            ->leftJoinSub($usage, 'u', fn ($j) => $j->on('u.pid_code', '=', 'p.pid')->on('u.branch_id', '=', 'p.branch_id'));
        foreach (['total' => 'TOTAL', 'material' => 'Material', 'service' => 'Non Material', 'bop' => 'Operasional'] as $alias => $category) {
            $query->join('budget_pid_items as '.$alias, fn ($j) => $j->on($alias.'.budget_pid_id', '=', 'p.id_budget_pid')->where($alias.'.category', $category));
        }
        $search = trim($input['q'] ?? '');
        if ($search !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($q) => $q->whereRaw("p.pid LIKE ? ESCAPE '!'", [$like])->orWhereRaw("p.name LIKE ? ESCAPE '!'", [$like]));
        }
        $remaining = 'total.available - COALESCE(u.material, 0) - COALESCE(u.service, 0)';
        $deficit = "({$remaining}) < 0 OR material.available - COALESCE(u.material, 0) < 0 OR service.available - COALESCE(u.service, 0) < 0 OR bop.available - COALESCE(u.bop, 0) < 0";
        try {
            // One grouped subquery and one aggregate, independent of master size; no per-PID reads.
            $summary = (clone $query)->selectRaw("COUNT(*) as total, COALESCE(SUM({$remaining}), 0) as remaining, COALESCE(SUM(CASE WHEN ({$remaining}) <= 0 THEN 1 ELSE 0 END), 0) as exhausted, COALESCE(SUM(CASE WHEN {$deficit} THEN 1 ELSE 0 END), 0) as deficit")->first();
            $rows = [];
            $page = (int) ($input['page'] ?? 1);
            if ($input['detail'] ?? false) {
                $rows = (clone $query)->orderBy('p.pid')->offset(($page - 1) * 20)->limit(20)
                    ->select(['p.id_budget_pid as id', 'p.pid', 'p.name', 'b.name as branch'])->selectRaw("total.available as available, COALESCE(u.material, 0) + COALESCE(u.service, 0) as used, {$remaining} as remaining, CASE WHEN {$deficit} THEN 1 ELSE 0 END as deficit")
                    ->get()->map(fn ($r) => ['id' => $r->id, 'pid' => $r->pid, 'name' => $r->name, 'branch' => $r->branch, 'available' => (int) $r->available, 'used' => (int) $r->used, 'remaining' => (int) $r->remaining, 'deficit' => (bool) $r->deficit])->all();
            }

            return ['unit' => $unit, 'branch' => $branch, 'q' => $search, 'page' => $page, 'per_page' => 20, 'total' => (int) $summary->total, 'remaining' => (int) $summary->remaining, 'exhausted' => (int) $summary->exhausted, 'deficit' => (int) $summary->deficit, 'rows' => $rows, 'new_pp_only' => true];
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['42S02', 'HY000'], true) && preg_match('/(?:no such table|doesn.t exist)/i', $error->getMessage())) {
                abort(503, 'Ringkasan Budget PID belum tersedia. Periksa kelengkapan migration budgeting.');
            }
            throw $error;
        }
    }
}
