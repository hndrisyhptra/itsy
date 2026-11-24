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

    public function listing(array $account, string $unit, string $q, int $page, bool $history = false, ?string $importId = null): array
    {
        $this->authorize($account, $unit);
        if ($history && $importId === null) {
            $query = DB::table('budget_pid_imports as i')->join('users as u', 'u.id_user', '=', 'i.actor_user_id')->where('i.unit', $unit);
            if ($q !== '') {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $query->whereRaw("i.filename LIKE ? ESCAPE '!'", [$like]);
            }
            $rows = $query->orderByDesc('i.version')->offset(($page - 1) * 20)->limit(21)->get(['i.*', 'u.name as actor_name']);
            $more = $rows->count() > 20;
            $result = $rows->take(20)->map(function ($r) {
                $r->summary = json_decode($r->summary, true, 512, JSON_THROW_ON_ERROR);

                return $r;
            })->values()->all();

            return ['rows' => $result, 'page' => $page, 'has_more' => $more, 'unit' => $unit, 'units' => $account['workUnits']];
        }
        $query = DB::table('budget_pids as p')->where('p.unit', $unit);
        if ($q !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->where(fn ($b) => $b->whereRaw("p.pid LIKE ? ESCAPE '!'", [$like])->orWhereRaw("p.name LIKE ? ESCAPE '!'", [$like]));
        }
        if ($history) {
            abort_unless(DB::table('budget_pid_imports')->where('id_budget_import', $importId)->where('unit', $unit)->exists(), 404);
            $rows = $query->join('budget_pid_changes as c', 'c.budget_pid_id', '=', 'p.id_budget_pid')
                ->join('budget_pid_imports as i', 'i.id_budget_import', '=', 'c.import_id')
                ->join('users as u', 'u.id_user', '=', 'i.actor_user_id')
                ->where('c.import_id', $importId)->orderBy('p.pid')->offset(($page - 1) * 20)->limit(21)
                ->get(['p.pid', 'p.unit', 'c.*', 'i.filename', 'i.created_at', 'i.version', 'u.name as actor_name']);
            $more = $rows->count() > 20;
            $result = $rows->take(20)->map(function ($r) {
                $r->before_data = $r->before_data ? json_decode($r->before_data, true, 512, JSON_THROW_ON_ERROR) : null;
                $r->after_data = json_decode($r->after_data, true, 512, JSON_THROW_ON_ERROR);

                return $r;
            })->values()->all();
        } else {
            $rows = $query->orderBy('p.pid')->offset(($page - 1) * 20)->limit(21)->get(['p.*']);
            $more = $rows->count() > 20;
            $rows = $rows->take(20);
            $items = DB::table('budget_pid_items')->whereIn('budget_pid_id', $rows->pluck('id_budget_pid'))->get()->groupBy('budget_pid_id');
            $result = $rows->map(function ($r) use ($items) {
                $r->items = $this->items($items->get($r->id_budget_pid, collect()));
                $r->warnings = $this->warnings(['items' => $r->items]);

                return $r;
            })->values()->all();
        }

        return ['rows' => $result, 'page' => $page, 'has_more' => $more, 'unit' => $unit,
            'version' => (int) DB::table('budget_pid_controls')->where('id_budget_control', 1)->value('version'),
            'units' => $account['workUnits']];
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
            $fingerprint = hash_hmac('sha256', json_encode([$actorId, $input['unit'], $sha256, $version], JSON_THROW_ON_ERROR), config('app.key'));
            if ($commit) {
                abort_unless((int) $input['version'] === $version && hash_equals($fingerprint, $input['preview_hash']), 409, 'Master berubah atau pemeriksaan tidak cocok. Periksa file kembali sebelum menyimpan.');
            }
            $existing = [];
            $existingItems = [];
            foreach (array_chunk(array_keys($records), 500) as $codes) {
                $batch = DB::table('budget_pids')->whereIn('pid', $codes)->get();
                foreach ($batch as $p) {
                    abort_unless($p->unit === $input['unit'], 422, "PID {$p->pid} sudah terdaftar pada unit {$p->unit}; tidak dapat dipindah unit.");
                    $existing[$p->pid] = $p;
                }
                foreach (DB::table('budget_pid_items')->whereIn('budget_pid_id', $batch->pluck('id_budget_pid'))->get()->groupBy('budget_pid_id') as $id => $rows) {
                    $existingItems[$id] = $this->items($rows);
                }
            }
            $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'warnings' => 0, 'pids' => count($records)];
            $rows = [];
            $changed = [];
            foreach ($records as $pid => $r) {
                $old = $existing[$pid] ?? null;
                $before = $old ? ['name' => $old->name, 'unit' => $old->unit, 'items' => $existingItems[$old->id_budget_pid]] : null;
                $after = ['name' => $r['name'], 'unit' => $input['unit'], 'items' => $r['items']];
                $action = ! $old ? 'created' : ($before === $after ? 'unchanged' : 'updated');
                $summary[$action]++;
                $warnings = $this->warnings($after);
                if ($warnings) {
                    $summary['warnings']++;
                }
                $available = [];
                foreach (['TOTAL', 'Material', 'Non Material', 'Operasional'] as $category) {
                    $available[$category] = $r['items'][$category]['available'];
                }
                $rows[] = ['pid' => $pid, 'name' => $r['name'], 'row' => $r['items']['TOTAL']['source_row'], 'action' => $action, 'available' => $available, 'warnings' => $warnings];
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
                    $pids[] = ['id_budget_pid' => $c['id'], 'pid' => $c['pid'], 'name' => $c['after']['name'], 'unit' => $input['unit'], 'last_import_id' => $id,
                        'created_at' => $existing[$c['pid']]->created_at ?? $now, 'updated_at' => $now];
                    foreach ($c['after']['items'] as $category => $item) {
                        $items[] = ['id_budget_item' => (string) Str::uuid(), 'budget_pid_id' => $c['id'], 'category' => $category] + $item;
                    }
                    $changes[] = ['id_budget_change' => (string) Str::uuid(), 'import_id' => $id, 'budget_pid_id' => $c['id'], 'action' => $c['action'],
                        'before_data' => $c['before'] ? json_encode($c['before'], JSON_THROW_ON_ERROR) : null, 'after_data' => json_encode($c['after'], JSON_THROW_ON_ERROR)];
                }
                DB::table('budget_pids')->upsert($pids, ['pid'], ['name', 'last_import_id', 'updated_at']);
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
