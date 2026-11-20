<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/** Explicit editable PP revision only. Never converts a submitted or approved history on read. */
final class OperationProjectRevision
{
    public static function scheme(?array $old, string $unit): string
    {
        if ($unit === 'Operation' && (! $old || (($old['workflowVersion'] ?? 3) >= 3 && in_array($old['status'], ['Draft', 'Dikembalikan', 'Ditolak'], true)))) {
            return 'operation-v1';
        }

        return $old && (! empty($old['approvalHistory']) || ! empty($old['approvals']))
            ? (PrototypeDomain::usesBusinessSupport($old) ? 'business-support' : ($old['approvalScheme'] ?? 'project-support'))
            : ($unit === 'Operation' ? 'operation-v1' : 'project-support-first');
    }

    public static function marker(?array $old, string $scheme, string $actor, array $state): ?array
    {
        if (isset($old['operationApprovalRevision'])) {
            return $old['operationApprovalRevision'];
        }
        if (! $old || $scheme !== 'operation-v1' || WorkUnitAccess::operation($old) || empty($old['approvalHistory'])) {
            return null;
        }
        $deals = array_column(array_filter($state['deals'], fn ($d) => $d['projectId'] === $old['id']), 'id');
        $orders = array_column(array_filter($state['spks'], fn ($s) => in_array($s['dealId'], $deals, true)), 'id');
        if (isset($old['headApprovalReopen']) || collect($state['terms'])->contains(fn ($t) => in_array($t['spkId'], $orders, true)) || collect($state['projects'])->contains(fn ($p) => ($p['parentId'] ?? '') === $old['id'])) {
            throw ValidationException::withMessages(['prototype' => 'PP Operation historis sudah memiliki proses lanjutan atau koreksi khusus. Perubahan alur approval melalui revisi ditahan.']);
        }
        $cycles = ProjectHeadReopen::cycles($old, true);
        if (! empty($old['approvals']) || end($cycles) !== []) {
            throw ValidationException::withMessages(['prototype' => 'Alur Operation hanya dapat dimulai setelah pengembalian/revisi menutup siklus approval sebelumnya.']);
        }

        return ['version' => 1, 'firstCycleNo' => count($cycles),
            'before' => Arr::only($old, ['workflowVersion', 'approvalScheme', 'approverUserIds', 'pmUserId', 'managerUserId', 'approvalOrderChange']),
            'actorUserId' => $actor, 'at' => now()->toISOString()];
    }

    public static function cycleDocument(array $document, int $cycleNo): array
    {
        $marker = $document['operationApprovalRevision'] ?? null;

        return $marker && $cycleNo < $marker['firstCycleNo']
            ? array_replace(Arr::except($document, ['approvalScheme', 'approverUserIds', 'pmUserId', 'managerUserId', 'approvalOrderChange']), $marker['before'])
            : $document;
    }
}
