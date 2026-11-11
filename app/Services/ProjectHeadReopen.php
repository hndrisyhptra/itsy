<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** One explicit maintenance correction; never accepts replacement client state. */
final class ProjectHeadReopen
{
    public const MESSAGE = 'PP sedang menunggu persetujuan ulang Head of PMO. Proses dokumen turunannya ditahan sampai PP Sah kembali.';

    /** Keep old cycles intact; the new cycle carries references to four real decisions. */
    public static function cycles(array $document, bool $project): array
    {
        $history = $project ? ($document['approvalHistory'] ?? $document['approvals'] ?? []) : ($document['history'] ?? $document['approvals'] ?? []);
        $reopen = $project ? ($document['headApprovalReopen'] ?? null) : null;
        $cycles = [[]];
        for ($offset = 0; $offset <= count($history); $offset++) {
            if ($reopen && $offset === $reopen['historyOffset']) {
                $cycles[] = $reopen['carriedApprovals'];
            }
            if ($offset === count($history)) {
                break;
            }
            $decision = $history[$offset];
            $cycles[array_key_last($cycles)][] = $decision;
            if ($decision['decision'] !== 'Setujui') {
                $cycles[] = [];
            }
        }

        return $cycles;
    }

    public static function assertCommandAllowed(array $state, string $command, array $args): void
    {
        $pending = [];
        foreach ($state['projects'] as $p) {
            if (isset($p['headApprovalReopen']) && $p['status'] !== 'Sah') {
                $pending[$p['id']] = true;
            }
        }
        if (! $pending) {
            return;
        }
        $lookup = fn ($collection, $id) => collect($state[$collection])->firstWhere('id', $id) ?? [];
        $deal = fn ($id) => $lookup('deals', $id)['projectId'] ?? '';
        $spk = fn ($id) => $deal($lookup('spks', $id)['dealId'] ?? '');
        $term = fn ($id) => $spk($lookup('terms', $id)['spkId'] ?? '');
        $input = $args[0] ?? null;
        $project = match ($command) {
            'saveDeal' => $input['projectId'] ?? '',
            'saveSpk', 'saveSpkFinance' => $deal($input['dealId'] ?? ''),
            '_recordPayeeFile', '_setPayeeVersion', 'createTerm' => $spk($input),
            'saveTerm', 'submitFinanceTerm' => $term($input['id'] ?? ''),
            'decide', 'adoptFinancePayment', '_issueKasbon', '_attachTransferProof', 'decideFinancePayment', 'requestPartialPayment', 'verifyPartialPayment', 'payPartialPayment', 'recordApm', 'recordPayment', 'verifyTermScan', '_attachTermScan', '_attachTermWorkEvidence', 'removeTermWorkEvidence' => $term($input),
            'attestBapp', 'releaseVolume', 'createContinuation' => $term($lookup('bapps', $input)['termId'] ?? ''),
            default => '',
        };
        if (isset($pending[$project])) {
            throw ValidationException::withMessages(['prototype' => self::MESSAGE]);
        }
    }
}
