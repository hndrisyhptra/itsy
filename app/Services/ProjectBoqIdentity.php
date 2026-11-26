<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Exact saved PP/catalog identities only. Never match siblings, names or item order. */
class ProjectBoqIdentity
{
    public const MESSAGE = 'Item BOQ masih digunakan pada BA KH/SPK atau PP lanjutan. Pertahankan item dan komponen yang sudah digunakan; perubahan tidak disimpan.';

    public static function stabilize(array $rows, ?array $old, mixed $budget): array
    {
        if (! $old || ! empty($old['parentId'])) {
            return [$rows, $budget];
        }
        $byKhs = array_column($old['boq'], null, 'khsId');
        $byId = array_column($old['boq'], null, 'id');
        $aliases = [];
        foreach ($rows as &$row) {
            if (isset($byId[$row['id']]) && $byId[$row['id']]['khsId'] !== $row['khsId']) {
                throw ValidationException::withMessages(['boq' => 'ID baris BOQ tersimpan tidak boleh dipakai untuk designator berbeda.']);
            }
            $saved = $byKhs[$row['khsId']] ?? null;
            if ($saved && $saved['id'] !== $row['id']) {
                $aliases[$row['id']] = $saved['id'];
                $row['id'] = $saved['id'];
            }
        }
        unset($row);
        if (is_array($budget) && $aliases) {
            foreach (is_array($budget['mandor'] ?? null) ? $budget['mandor'] : [] as $i => $r) {
                if (is_array($r) && isset($r['rowId']) && is_string($r['rowId'])) {
                    $budget['mandor'][$i]['rowId'] = $aliases[$r['rowId']] ?? $r['rowId'];
                }
            }
            foreach (is_array($budget['materials'] ?? null) ? $budget['materials'] : [] as $i => $r) {
                if (is_array($r) && isset($r['sourceRowId']) && is_string($r['sourceRowId'])) {
                    $budget['materials'][$i]['sourceRowId'] = $aliases[$r['sourceRowId']] ?? $r['sourceRowId'];
                }
            }
            if (is_array($budget['excludedMaterialRowIds'] ?? null)) {
                $budget['excludedMaterialRowIds'] = array_map(fn ($id) => is_string($id) ? ($aliases[$id] ?? $id) : $id, $budget['excludedMaterialRowIds']);
            }
        }

        return [$rows, $budget];
    }

    /** Loaded graph checks also cover child allocations even when no SQL contract exists yet. */
    public static function references(array $state, array $project): array
    {
        if (! empty($project['parentId'])) {
            return [];
        }
        $rows = array_column($project['boq'], null, 'id');
        $related = $used = [];
        foreach ($state['projects'] as $p) {
            if ($p['rootId'] !== $project['id']) {
                continue;
            }
            $related[$p['id']] = true;
            if (! empty($p['parentId'])) {
                foreach ($p['allocations'] as $id => $quantities) {
                    foreach (['m', 's'] as $c) {
                        if (($quantities[$c] ?? 0) > 0) {
                            $used[$id][$c] = true;
                        }
                    }
                }
            }
        }
        foreach ($state['deals'] as $d) {
            if (! isset($related[$d['projectId']])) {
                continue;
            }
            foreach ($d['rows'] as $r) {
                foreach (['m', 's'] as $c) {
                    if (! empty($r[$c])) {
                        $used[$r['rowId']][$c] = true;
                    }
                }
            }
        }
        foreach ($used as $id => $components) {
            foreach ($components as $c => $_) {
                if (! isset($rows[$id]) || $rows[$id][$c] === null) {
                    throw ValidationException::withMessages(['boq' => self::MESSAGE]);
                }
            }
        }

        return $used;
    }
}
