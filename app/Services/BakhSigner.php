<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class BakhSigner
{
    private const FIELDS = ['signerUserId', 'signerNik', 'signerRole', 'signerSource'];

    public static function sync(array $state, array $deal, bool $required = false): array
    {
        $saved = collect($state['deals'])->first(fn ($d) => $d['id'] === $deal['id'] && $d['projectId'] === $deal['projectId']);
        $deal = array_diff_key($deal, array_flip(self::FIELDS));
        if ($saved && in_array($saved['status'], ['Terbit', 'Sah'], true)) {
            return array_merge($deal, array_intersect_key($saved, array_flip(['signer', ...self::FIELDS])));
        }
        $project = collect($state['projects'])->firstWhere('id', $deal['projectId']);
        if (($project['workflowVersion'] ?? 1) < 3) {
            return $deal;
        }

        return array_merge($deal, self::projectIdentity($state, $project, $required));
    }

    /** Resolve only the selected PP Head, using already loaded account/decision snapshots. */
    public static function projectIdentity(array $state, array $project, bool $required = false): array
    {
        $role = 'Head of PMO';
        $id = $project['approverUserIds'][$role] ?? '';
        $decision = $id ? collect($project['approvals'] ?? [])->first(fn ($a) => $a['role'] === $role && ($a['userId'] ?? '') === $id && $a['decision'] === 'Setujui') : null;
        $snapshot = $project['signatureUsers'][$role] ?? [];
        if (! $id || ($snapshot['userId'] ?? '') !== $id) {
            $snapshot = [];
        }
        $user = $id ? collect($state['users'])->first(fn ($u) => $u['id'] === $id && $u['role'] === $role) : null;
        $first = static function (...$values): string {
            foreach ($values as $value) {
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }

            return '';
        };
        $name = $first($decision['actorName'] ?? null, $snapshot['name'] ?? null, $user['name'] ?? null);
        $nik = $first($decision['actorNik'] ?? null, $snapshot['nik'] ?? null, $user['nik'] ?? null);
        if ($required && (! $id || ! $name)) {
            throw ValidationException::withMessages(['prototype' => ['Head of PMO terpilih pada PP belum memiliki identitas penandatangan yang valid.']]);
        }

        return ['signer' => $name, 'signerUserId' => $id, 'signerNik' => $nik, 'signerRole' => $role, 'signerSource' => 'pp-head-pmo'];
    }
}
