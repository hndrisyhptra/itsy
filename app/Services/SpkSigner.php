<?php

namespace App\Services;

final class SpkSigner
{
    private const FIELDS = ['signerUserId', 'signerNik', 'signerRole', 'signerSource'];

    public static function sync(array $state, array $spk, bool $required = false): array
    {
        $saved = collect($state['spks'])->first(fn ($s) => $s['id'] === $spk['id'] && $s['dealId'] === $spk['dealId']);
        $spk = array_diff_key($spk, array_flip(self::FIELDS));
        if ($saved && in_array($saved['status'], ['Terbit', 'Sah'], true)) {
            return array_merge($spk, array_intersect_key($saved, array_flip(['signer', 'position', ...self::FIELDS])));
        }
        $deal = collect($state['deals'])->firstWhere('id', $spk['dealId']);
        $project = collect($state['projects'])->firstWhere('id', $deal['projectId'] ?? '');
        if (($project['workflowVersion'] ?? 1) < 3) {
            return $spk;
        }

        return array_merge($spk, BakhSigner::projectIdentity($state, $project, $required), ['position' => 'HEAD OF PMO AREA JAWA BALI']);
    }
}
