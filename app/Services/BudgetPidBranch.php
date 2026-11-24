<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Explicit mapping only: PID prefixes never confer Branch ownership or access. */
class BudgetPidBranch
{
    public function options(array $account): array
    {
        return DB::table('branches')->whereIn('id_branch', $account['branchIds'])->where('is_active', true)->whereNull('deleted_at')
            ->orderBy('name')->get(['id_branch', 'name'])->all();
    }

    public function history(array $account, string $unit, int $page): array
    {
        app(BudgetPidMaster::class)->authorize($account, $unit);
        $rows = DB::table('budget_pid_branch_changes as c')->join('users as u', 'u.id_user', '=', 'c.actor_user_id')
            ->join('branches as b', 'b.id_branch', '=', 'c.branch_id')->where('c.unit', $unit)->whereIn('c.branch_id', $account['branchIds'])
            ->orderByDesc('c.version')->offset(($page - 1) * 20)->limit(21)->get(['c.*', 'u.name as actor_name', 'b.name as branch']);

        return ['rows' => $rows->take(20)->map(function ($r) {
            $r->before_data = json_decode($r->before_data, true, 512, JSON_THROW_ON_ERROR);
            $r->after_data = json_decode($r->after_data, true, 512, JSON_THROW_ON_ERROR);

            return $r;
        })->values()->all(), 'page' => $page, 'has_more' => $rows->count() > 20];
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
                DB::table('budget_pids')->whereIn('id_budget_pid', array_column($after, 'id_budget_pid'))->update(['branch_id' => (int) $input['branch_id']]);
            }
            DB::table('budget_pid_branch_changes')->insert(['id_budget_branch_change' => $input['request_id'], 'actor_user_id' => $actorId, 'unit' => $input['unit'], 'branch_id' => (int) $input['branch_id'], 'version' => $version + 1,
                'payload_hash' => $hash, 'before_data' => json_encode($before, JSON_THROW_ON_ERROR), 'after_data' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            DB::table('budget_pid_controls')->where('id_budget_control', 1)->update(['version' => $version + 1]);

            return ['version' => $version + 1, 'mapped' => count($after)];
        });
    }
}
