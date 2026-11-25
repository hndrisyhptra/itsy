<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Independent master import lock/version: never loads or rewrites the workflow graph. */
class BudgetPidMaster
{
    public function authorize(array $account, ?string $unit = null): void
    {
        abort_unless(in_array($account['code'] ?? '', ['FINANCE', 'SUPERADMIN'], true), 403);
        if ($unit !== null) {
            abort_unless(in_array($unit, $account['workUnits'] ?? [], true), 403, 'Unit kerja di luar akses akun.');
        }
    }

    public function listing(array $account, string $unit, string $q, int $page, bool $history = false, ?string $importId = null, int $perPage = 20): array
    {
        $this->authorize($account, $unit);
        abort_unless(in_array($perPage, [10, 20, 50, 100], true), 422);
        if ($history && $importId === null) {
            $query = DB::table('budget_pid_imports as i')->join('users as u', 'u.id_user', '=', 'i.actor_user_id')->where('i.unit', $unit);
            if ($q !== '') {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $query->whereRaw("i.filename LIKE ? ESCAPE '!'", [$like]);
            }
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('i.version')->offset(($page - 1) * $perPage)->limit($perPage + 1)->get(['i.*', 'u.name as actor_name']);
            $more = $rows->count() > $perPage;
            $result = $rows->take($perPage)->map(function ($r) {
                $r->summary = json_decode($r->summary, true, 512, JSON_THROW_ON_ERROR);

                return $r;
            })->values()->all();

            return ['rows' => $result, 'page' => $page, 'has_more' => $more, 'total' => $total, 'per_page' => $perPage, 'unit' => $unit, 'units' => $account['workUnits']];
        }
        $query = DB::table('budget_pids as p')->where('p.unit', $unit)
            ->where(fn ($q) => $q->whereNull('p.branch_id')->orWhereIn('p.branch_id', $account['branchIds']));
        if ($q !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->where(fn ($b) => $b->whereRaw("p.pid LIKE ? ESCAPE '!'", [$like])->orWhereRaw("p.name LIKE ? ESCAPE '!'", [$like]));
        }
        if ($history) {
            abort_unless(DB::table('budget_pid_imports')->where('id_budget_import', $importId)->where('unit', $unit)->exists(), 404);
            $query->join('budget_pid_changes as c', 'c.budget_pid_id', '=', 'p.id_budget_pid')
                ->join('budget_pid_imports as i', 'i.id_budget_import', '=', 'c.import_id')
                ->join('users as u', 'u.id_user', '=', 'i.actor_user_id')
                ->where('c.import_id', $importId);
            $total = (clone $query)->count();
            $rows = $query->orderBy('p.pid')->offset(($page - 1) * $perPage)->limit($perPage + 1)->get(['p.pid', 'p.unit', 'c.*', 'i.filename', 'i.created_at', 'i.version', 'u.name as actor_name']);
            $more = $rows->count() > $perPage;
            $result = $rows->take($perPage)->map(function ($r) {
                $r->before_data = $r->before_data ? json_decode($r->before_data, true, 512, JSON_THROW_ON_ERROR) : null;
                $r->after_data = json_decode($r->after_data, true, 512, JSON_THROW_ON_ERROR);

                return $r;
            })->values()->all();
        } else {
            $total = (clone $query)->count();
            $rows = $query->leftJoin('branches as b', 'b.id_branch', '=', 'p.branch_id')->orderBy('p.pid')->offset(($page - 1) * $perPage)->limit($perPage + 1)->get(['p.*', 'b.name as branch']);
            $more = $rows->count() > $perPage;
            $rows = $rows->take($perPage);
            $items = DB::table('budget_pid_items')->whereIn('budget_pid_id', $rows->pluck('id_budget_pid'))->get()->groupBy('budget_pid_id');
            $usage = DB::table('budget_pid_allocations as a')->join('budget_pids as p', 'p.pid', '=', 'a.pid_code')
                ->whereIn('p.id_budget_pid', $rows->pluck('id_budget_pid'))->where('a.unit', $unit)->whereIn('a.branch_id', $account['branchIds'])->where('a.status', 'reserved')
                ->groupBy('p.id_budget_pid', 'a.branch_id')->selectRaw('p.id_budget_pid, a.branch_id, SUM(a.material) as material, SUM(a.service) as service, SUM(a.bop) as bop')->get()->keyBy(fn ($r) => $r->id_budget_pid.'|'.$r->branch_id);
            $result = $rows->map(function ($r) use ($items, $usage) {
                $r->items = $this->items($items->get($r->id_budget_pid, collect()));
                $r->warnings = $this->warnings(['items' => $r->items]);
                $u = $usage->get($r->id_budget_pid.'|'.$r->branch_id);
                $r->application_used = $r->remaining = [];
                foreach (BudgetPidAllocation::CATEGORIES as $category => $column) {
                    $r->application_used[$category] = BudgetPidAllocation::integer($u->$column ?? 0);
                }
                $r->application_used['TOTAL'] = BudgetPidAllocation::integer(bcadd((string) $r->application_used['Material'], (string) $r->application_used['Non Material'], 0));
                foreach ($r->application_used as $category => $value) {
                    $r->remaining[$category] = $r->items[$category]['available'] - $value;
                }

                return $r;
            })->values()->all();
        }

        return ['rows' => $result, 'page' => $page, 'has_more' => $more, 'total' => $total, 'per_page' => $perPage, 'unit' => $unit,
            'version' => (int) DB::table('budget_pid_controls')->where('id_budget_control', 1)->value('version'),
            'units' => $account['workUnits'], 'branches' => app(BudgetPidBranch::class)->options($account)];
    }

    private function items($rows): array
    {
        $items = [];
        foreach ($rows as $item) {
            $items[$item->category] = ['source_title' => $item->source_title, 'source_row' => (int) $item->source_row,
                'release' => (int) $item->release, 'commitment' => (int) $item->commitment, 'actual' => (int) $item->actual, 'available' => (int) $item->available];
        }
        $ordered = [];
        foreach (['TOTAL', ...BudgetPidWorkbook::CATEGORIES] as $category) {
            if (isset($items[$category])) {
                $ordered[$category] = $items[$category];
            }
        }

        return $ordered;
    }

    public function warnings(array $record): array
    {
        $items = $record['items'];
        $warnings = [];
        foreach (['TOTAL', 'Material', 'Non Material', 'Operasional'] as $category) {
            if ($items[$category]['available'] < 0) {
                $warnings[] = "{$category}: available cost negatif (defisit).";
            }
        }
        foreach (['release' => 'Release', 'commitment' => 'Tot.cst.cm', 'actual' => 'Act.costs'] as $key => $label) {
            $sum = array_sum(array_map(fn ($c) => $items[$c][$key], BudgetPidWorkbook::CATEGORIES));
            if ($sum !== $items['TOTAL'][$key]) {
                $warnings[] = "{$label} Level 02 berbeda dari jumlah lima rincian Level 04; nilai sumber dipertahankan.";
            }
        }

        return $warnings;
    }

    public function import(int $actorId, array $records, array $input, string $sha256, string $filename): array
    {
        return DB::transaction(function () use ($actorId, $records, $input, $sha256, $filename) {
            $control = DB::table('budget_pid_controls')->where('id_budget_control', 1)->lockForUpdate()->first();
            abort_unless($control, 503, 'Migration Master Budgeting PID belum lengkap.');
            $actor = User::whereKey($actorId)->lockForUpdate()->firstOrFail();
            $account = app(AccountAccess::class)->context($actor) ?? [];
            $this->authorize($account, $input['unit']);
            $commit = $input['stage'] === 'commit';
            if ($commit && ($prior = DB::table('budget_pid_imports')->where('id_budget_import', $input['request_id'])->first())) {
                abort_unless((int) $prior->actor_user_id === $actorId && $prior->unit === $input['unit'] && hash_equals($prior->sha256, $sha256)
                    && hash_equals($prior->preview_hash, $input['preview_hash']), 409, 'Identitas permintaan sudah digunakan untuk impor berbeda.');

                return ['replayed' => true, 'import_id' => $prior->id_budget_import, 'version' => (int) $prior->version, 'summary' => json_decode($prior->summary, true, 512, JSON_THROW_ON_ERROR)];
            }
            $version = (int) $control->version;
            // One active Branch catalog read; codes do not confer account access.
            $branches = DB::table('branches')->where('is_active', true)->whereNull('deleted_at')->orderBy('id_branch')->lockForUpdate()->get(['id_branch', 'kode_branch', 'name']);
            $byCode = $branches->groupBy(fn ($b) => strtoupper($b->kode_branch));
            $byId = $branches->keyBy('id_branch');
            $existing = [];
            $existingItems = [];
            foreach (array_chunk(array_keys($records), 500) as $codes) {
                abort_if(DB::table('budget_pid_allocations')->whereIn('pid_code', $codes)->where('status', '<>', 'cancelled')->where('unit', '<>', $input['unit'])
                    ->lockForUpdate()->first(['id_budget_allocation']) !== null, 422, 'File memuat PID yang dipakai PP baru pada unit kerja lain.');
                $batch = DB::table('budget_pids')->whereIn('pid', $codes)->get();
                foreach ($batch as $p) {
                    abort_unless($p->unit === $input['unit'], 422, "PID {$p->pid} sudah terdaftar pada unit {$p->unit}; tidak dapat dipindah unit.");
                    abort_unless($p->branch_id === null || in_array((int) $p->branch_id, $account['branchIds'], true), 403, 'File memuat PID di luar Branch penugasan.');
                    $existing[$p->pid] = $p;
                }
                foreach (DB::table('budget_pid_items')->whereIn('budget_pid_id', $batch->pluck('id_budget_pid'))->get()->groupBy('budget_pid_id') as $id => $rows) {
                    $existingItems[$id] = $this->items($rows);
                }
            }
            $mapping = [];
            foreach (array_chunk(array_keys($records), 500) as $codes) {
                $targets = [];
                foreach ($codes as $pid) {
                    $old = $existing[$pid] ?? null;
                    $branch = $old?->branch_id;
                    if ($branch === null) {
                        preg_match_all('/(?<![A-Z])R[0-9]{3}(?=-|$)/i', $pid, $matches);
                        $candidates = count($matches[0]) === 1 ? $byCode->get(strtoupper($matches[0][0])) : null;
                        if ($candidates?->count() === 1) {
                            $branch = (int) $candidates->first()->id_branch;
                            abort_unless(in_array($branch, $account['branchIds'], true), 403, 'Kode Branch dalam file berada di luar penugasan aktif.');
                            $targets[$branch][] = $pid;
                        }
                    }
                    $mapping[$pid] = $branch === null ? null : (int) $branch;
                }
                if ($targets) {
                    abort_if(DB::table('budget_pid_allocations')->where('unit', $input['unit'])->where('status', 'reserved')->where(function ($q) use ($targets) {
                        foreach ($targets as $branch => $pids) {
                            $q->orWhere(fn ($part) => $part->whereIn('pid_code', $pids)->where('branch_id', '<>', $branch));
                        }
                    })->lockForUpdate()->first(['id_budget_allocation']) !== null, 422, 'PID telah dipakai PP baru pada Branch lain. Periksa pemetaan manual sebelum impor.');
                }
            }
            // Bind the reviewed mapping too: changed Branch codes/activation require a fresh preview.
            $fingerprint = hash_hmac('sha256', json_encode([$actorId, $input['unit'], $sha256, $version, $mapping], JSON_THROW_ON_ERROR), config('app.key'));
            if ($commit) {
                abort_unless((int) $input['version'] === $version && hash_equals($fingerprint, $input['preview_hash']), 409, 'Master atau pemetaan berubah; periksa file kembali sebelum menyimpan.');
            }
            $summary = ['auto_mapped' => 0, 'unmapped' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'warnings' => 0, 'pids' => count($records)];
            $rows = [];
            $changed = [];
            foreach ($records as $pid => $r) {
                $old = $existing[$pid] ?? null;
                $before = $old ? ['name' => $old->name, 'unit' => $old->unit, 'branch_id' => $old->branch_id === null ? null : (int) $old->branch_id, 'items' => $existingItems[$old->id_budget_pid]] : null;
                $after = ['name' => $r['name'], 'unit' => $input['unit'], 'branch_id' => $mapping[$pid], 'items' => $r['items']];
                $action = ! $old ? 'created' : ($before === $after ? 'unchanged' : 'updated');
                $summary[$action]++;
                $summary['auto_mapped'] += ($old?->branch_id === null && $mapping[$pid] !== null) ? 1 : 0;
                $summary['unmapped'] += $mapping[$pid] === null ? 1 : 0;
                $warnings = $this->warnings($after);
                if ($warnings) {
                    $summary['warnings']++;
                }
                $available = [];
                foreach (['TOTAL', 'Material', 'Non Material', 'Operasional'] as $category) {
                    $available[$category] = $r['items'][$category]['available'];
                }
                $rows[] = ['pid' => $pid, 'name' => $r['name'], 'row' => $r['items']['TOTAL']['source_row'], 'action' => $action, 'available' => $available, 'warnings' => $warnings, 'branch_id' => $mapping[$pid], 'branch' => $byId->get($mapping[$pid])?->name];
                if ($action !== 'unchanged') {
                    $changed[] = ['pid' => $pid, 'id' => $old?->id_budget_pid ?? (string) Str::uuid(), 'before' => $before, 'after' => $after, 'action' => $action];
                }
            }
            if (! $commit) {
                return ['version' => $version, 'preview_hash' => $fingerprint, 'sha256' => $sha256, 'unit' => $input['unit'], 'summary' => $summary, 'rows' => $rows];
            }
            $now = now();
            $id = $input['request_id'];
            DB::table('budget_pid_imports')->insert(['id_budget_import' => $id, 'actor_user_id' => $actorId, 'unit' => $input['unit'], 'filename' => mb_substr(basename(str_replace('\\', '/', $filename)), 0, 200),
                'sha256' => $sha256, 'preview_hash' => $fingerprint, 'version' => $version + 1, 'summary' => json_encode($summary, JSON_THROW_ON_ERROR), 'created_at' => $now]);
            // Bound write buffers independently of workbook size; no 30k-item SQL array.
            foreach (array_chunk($changed, 100) as $chunk) {
                $pids = [];
                $items = [];
                $changes = [];
                foreach ($chunk as $c) {
                    $pids[] = ['id_budget_pid' => $c['id'], 'pid' => $c['pid'], 'name' => $c['after']['name'], 'unit' => $input['unit'], 'branch_id' => $c['after']['branch_id'], 'last_import_id' => $id,
                        'created_at' => $existing[$c['pid']]->created_at ?? $now, 'updated_at' => $now];
                    foreach ($c['after']['items'] as $category => $item) {
                        $items[] = ['id_budget_item' => (string) Str::uuid(), 'budget_pid_id' => $c['id'], 'category' => $category] + $item;
                    }
                    $changes[] = ['id_budget_change' => (string) Str::uuid(), 'import_id' => $id, 'budget_pid_id' => $c['id'], 'action' => $c['action'],
                        'before_data' => $c['before'] ? json_encode($c['before'], JSON_THROW_ON_ERROR) : null, 'after_data' => json_encode($c['after'], JSON_THROW_ON_ERROR)];
                }
                // Incoming created_at already holds the original saved timestamp for existing PID.
                // Assign it explicitly to suppress legacy implicit ON UPDATE timestamp behavior.
                DB::table('budget_pids')->upsert($pids, ['pid'], ['name', 'branch_id', 'last_import_id', 'created_at', 'updated_at']);
                foreach (array_chunk($items, 50) as $batch) {
                    DB::table('budget_pid_items')->upsert($batch, ['budget_pid_id', 'category'], ['source_title', 'source_row', 'release', 'commitment', 'actual', 'available']);
                }
                DB::table('budget_pid_changes')->insert($changes);
            }
            DB::table('budget_pid_controls')->where('id_budget_control', 1)->update(['version' => $version + 1]);

            return ['import_id' => $id, 'version' => $version + 1, 'summary' => $summary];
        });
    }
}
