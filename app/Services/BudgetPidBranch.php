<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Manual corrections remain explicit; automatic import mapping never grants account access. */
class BudgetPidBranch
{
    public function options(array $account): array
    {
        return DB::table('branches')->whereIn('id_branch', $account['branchIds'])->where('is_active', true)->whereNull('deleted_at')
            ->orderBy('name')->get(['id_branch', 'name'])->all();
    }

    public function history(array $account, string $unit, int $page, int $perPage = 20): array
    {
        app(BudgetPidMaster::class)->authorize($account, $unit);
        abort_unless(in_array($perPage, [10, 20, 50, 100], true), 422);
        $query = DB::table('budget_pid_branch_changes as c')->join('users as u', 'u.id_user', '=', 'c.actor_user_id')
            ->join('branches as b', 'b.id_branch', '=', 'c.branch_id')->where('c.unit', $unit)->whereIn('c.branch_id', $account['branchIds']);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('c.version')->offset(($page - 1) * $perPage)->limit($perPage + 1)->get(['c.*', 'u.name as actor_name', 'b.name as branch']);

        return ['rows' => $rows->take($perPage)->map(function ($r) {
            $r->before_data = json_decode($r->before_data, true, 512, JSON_THROW_ON_ERROR);
            $r->after_data = json_decode($r->after_data, true, 512, JSON_THROW_ON_ERROR);

            return $r;
        })->values()->all(), 'page' => $page, 'has_more' => $rows->count() > $perPage, 'total' => $total, 'per_page' => $perPage];
    }

    public function change(int $actorId, array $input): array
    {
        $ids = $input['ids'];
        sort($ids, SORT_STRING);
        $hash = hash('sha256', json_encode([$actorId, $input['unit'], $ids, (int) $input['branch_id'], (int) $input['version']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actorId, $input, $ids, $hash) {
            $version = (int) DB::table('budget_pid_controls')->where('id_budget_control', 1)->lockForUpdate()->value('version');
            $actor = User::whereKey($actorId)->lockForUpdate()->firstOrFail();
            $account = app(AccountAccess::class)->context($actor) ?? [];
            app(BudgetPidMaster::class)->authorize($account, $input['unit']);
            abort_unless(DB::table('branches')->where('id_branch', $input['branch_id'])->whereIn('id_branch', $account['branchIds'])->where('is_active', true)->whereNull('deleted_at')->exists(), 403, 'Branch tujuan di luar penugasan aktif.');
            if ($prior = DB::table('budget_pid_branch_changes')->where('id_budget_branch_change', $input['request_id'])->first()) {
                abort_unless(hash_equals($prior->payload_hash, $hash), 409, 'UUID pemetaan sudah digunakan untuk data lain.');

                return ['version' => (int) $prior->version, 'mapped' => count(json_decode($prior->after_data, true)), 'replayed' => true];
            }
            abort_unless($version === (int) $input['version'], 409, 'Master berubah; muat ulang daftar lalu periksa pemetaan.');
            $rows = DB::table('budget_pids')->whereIn('id_budget_pid', $ids)->orderBy('pid')->lockForUpdate()->get(['id_budget_pid', 'pid', 'unit', 'branch_id']);
            abort_unless($rows->count() === count($ids), 422, 'PID terpilih tidak lengkap.');
            abort_if(DB::table('budget_pid_allocations')->whereIn('pid_code', $rows->pluck('pid'))->where('unit', $input['unit'])->where('status', 'reserved')
                ->where('branch_id', '<>', (int) $input['branch_id'])->lockForUpdate()->first(['id_budget_allocation']) !== null, 422, 'PID telah dipakai PP baru pada Branch lain. Periksa pemetaan sebelum menyimpan.');
            $before = $after = $moving = [];
            foreach ($rows as $r) {
                abort_unless($r->unit === $input['unit'] && ($r->branch_id === null || in_array((int) $r->branch_id, $account['branchIds'], true)), 403, 'PID di luar unit atau Branch penugasan.');
                if ((int) $r->branch_id === (int) $input['branch_id']) {
                    continue;
                }
                $before[] = ['id_budget_pid' => $r->id_budget_pid, 'pid' => $r->pid, 'branch_id' => $r->branch_id];
                $after[] = ['id_budget_pid' => $r->id_budget_pid, 'pid' => $r->pid, 'branch_id' => (int) $input['branch_id']];
                if ($r->branch_id !== null) {
                    $moving[] = $r->pid;
                }
            }
            if ($moving) {
                abort_if(DB::table('project_plans as pp')->join('projects as p', 'p.id_project', '=', 'pp.project_id')->join('work_units as w', 'w.id_work_unit', '=', 'pp.work_unit_id')
                    ->whereIn('p.project_code', $moving)->where('w.code', $input['unit'] === 'Operation' ? 'OPERATION' : 'KONSTRUKSI')->select('pp.id_pp')->lockForUpdate()->first() !== null, 422, 'PID sudah digunakan PP. Pemindahan Branch ditahan agar relasi dokumen lama tetap aman.');
            }
            if ($after) {
                // Legacy TIMESTAMP definitions may carry implicit ON UPDATE CURRENT_TIMESTAMP.
                // Explicit self-assignment preserves source dates without another read or a clock rewrite.
                DB::table('budget_pids')->whereIn('id_budget_pid', array_column($after, 'id_budget_pid'))->update([
                    'branch_id' => (int) $input['branch_id'],
                    'created_at' => DB::raw('created_at'),
                    'updated_at' => DB::raw('updated_at'),
                ]);
            }
            DB::table('budget_pid_branch_changes')->insert(['id_budget_branch_change' => $input['request_id'], 'actor_user_id' => $actorId, 'unit' => $input['unit'], 'branch_id' => (int) $input['branch_id'], 'version' => $version + 1,
                'payload_hash' => $hash, 'before_data' => json_encode($before, JSON_THROW_ON_ERROR), 'after_data' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            DB::table('budget_pid_controls')->where('id_budget_control', 1)->update(['version' => $version + 1]);

            return ['version' => $version + 1, 'mapped' => count($after)];
        });
    }
}
