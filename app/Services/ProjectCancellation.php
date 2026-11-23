<?php

namespace App\Services;

use Illuminate\Support\Arr;

/** A terminal package status, separate from a returned or revoked approval. */
final class ProjectCancellation
{
    public const MESSAGE = 'Paket PP sudah Cancel. Perubahan, pengajuan ulang dan proses turunannya tidak dapat dilanjutkan.';

    public static function plan(array $s, array $p): array
    {
        $actor = collect($s['users'])->firstWhere('id', $s['actorUserId'] ?? '');
        $unit = ($p['unit'] ?? '') === 'Operation' ? 'Operation' : 'Konstruksi';
        $role = $unit === 'Operation' ? 'Officer 1 Operation' : 'Admin Area';
        $own = $actor && ($actor['active'] ?? false) && $actor['role'] === $s['role']
            && in_array($s['role'], [$role, 'Superadmin'], true)
            && in_array($p['branch'], $actor['branches'] ?? [], true)
            && in_array($unit, $actor['workUnits'] ?? [$unit], true);
        $deals = array_filter($s['deals'], fn ($d) => $d['projectId'] === $p['id']);
        $dealIds = array_fill_keys(array_column($deals, 'id'), true);
        $spks = array_filter($s['spks'], fn ($v) => isset($dealIds[$v['dealId']]));
        $spkIds = array_fill_keys(array_column($spks, 'id'), true);
        $reason = '';
        if (! in_array($p['status'], ['Draft', 'Dikembalikan', 'Ditolak', 'Diajukan', 'Sah'], true)) {
            $reason = 'Paket PP sudah Cancel atau statusnya tidak dapat dibatalkan.';
        } elseif (isset($p['headApprovalReopen']) && $p['status'] !== 'Sah') {
            $reason = 'Selesaikan koreksi khusus Head sebelum membatalkan paket.';
        } elseif (collect($s['terms'])->contains(fn ($t) => isset($spkIds[$t['spkId']]))) {
            $reason = 'Paket sudah memiliki BA RP/pengajuan pembayaran dan tidak dapat di-Cancel.';
        } elseif (collect($s['projects'])->contains(fn ($child) => $child['id'] !== $p['id'] && (($child['parentId'] ?? '') === $p['id'] || ($child['rootId'] ?? '') === $p['id']))) {
            $reason = 'Paket sudah memiliki PP lanjutan dan tidak dapat di-Cancel.';
        }

        return ['own' => (bool) $own, 'reason' => $reason, 'documentIds' => [$p['id'], ...array_column($deals, 'id'), ...array_column($spks, 'id')],
            'expected' => ['status' => $p['status'], 'historyCount' => count($p['approvalHistory'] ?? []), 'approvals' => $p['approvals'] ?? [], 'submissionId' => collect($p['contractBundle']['submissions'] ?? [])->last()['id'] ?? null]];
    }

    public static function apply(array $s, array $p, array $expected, string $reason, array $context): array
    {
        $plan = self::plan($s, $p);
        abort_unless($plan['own'], 403, 'Cancel hanya untuk Admin Area Konstruksi, Officer 1 Operation sesuai unit, atau Superadmin dalam penugasan.');
        abort_if($plan['reason'] !== '', 422, $plan['reason']);
        abort_unless(Arr::sortRecursive($expected) === Arr::sortRecursive($plan['expected']), 409, 'Status atau approval paket berubah. Muat ulang sebelum Cancel.');
        abort_if(trim($reason) === '' || mb_strlen($reason) > 1000, 422, 'Isi alasan Cancel maksimal 1000 karakter.');
        $actor = collect($s['users'])->firstWhere('id', $s['actorUserId']);
        $p['cancellation'] = ['version' => 1, 'previousStatus' => $p['status'], 'actorUserId' => $actor['id'], 'actorName' => $actor['name'], 'role' => $s['role'], 'at' => now()->toISOString(), 'reason' => trim($reason), 'sourceCycleNo' => count(ProjectHeadReopen::cycles($p, true)), ...array_intersect_key($context, array_flip(['requestId', 'payloadHash']))];
        $p['status'] = 'Cancel';

        return $p;
    }

    /** All existing mutation paths resolve their actual saved parent, including worker commands. */
    public static function assertCommandAllowed(array $s, string $command, array $args): void
    {
        if ($command === 'cancelProject' || ! collect($s['projects'])->contains('status', 'Cancel')) {
            return;
        }
        $find = fn ($kind, $id) => collect($s[$kind])->firstWhere('id', $id) ?? [];
        $deal = fn ($id) => $find('deals', $id)['projectId'] ?? '';
        $spk = fn ($id) => $deal($find('spks', $id)['dealId'] ?? '');
        $term = fn ($id) => $spk($find('terms', $id)['spkId'] ?? '');
        $input = $args[0] ?? null;
        $id = match ($command) {
            'saveProject', 'saveProjectBundle' => $input['id'] ?? '',
            'decideProject', 'submitProjectBundle', 'resetProjectApproval', 'assignProjectApprovers', 'assignPaymentApprovers', 'reviewBoq', 'finalizeBoq', 'importDemo' => $input,
            'adoptApprovalOrder' => $input === 'projects' ? ($args[1] ?? '') : $term($args[1] ?? ''),
            'saveDeal' => $deal($input['id'] ?? '') ?: ($input['projectId'] ?? ''),
            'saveSpk', 'saveSpkFinance' => $spk($input['id'] ?? '') ?: $deal($input['dealId'] ?? ''),
            '_recordPayeeFile', '_setPayeeVersion', 'createTerm', 'createSettlementTerm' => $spk($input),
            'saveTerm', 'submitFinanceTerm', 'attestBarp' => $term($input['id'] ?? ''),
            'attestBapp', 'releaseVolume', 'createContinuation' => $term($find('bapps', $input)['termId'] ?? ''),
            default => '',
        };
        abort_if(($find('projects', $id)['status'] ?? '') === 'Cancel', 422, self::MESSAGE);
    }
}
