<?php

namespace App\Services;

trait PrototypeApprovalChain
{
    public const APPROVERS_V3 = ['PM Konstruksi', 'Admin Area', 'Mgr Project Support', 'Mgr Deployment', 'Head of PMO'];

    public const APPROVERS_V3_PREVIOUS = ['PM Konstruksi', 'Admin Area', 'Mgr Deployment', 'Mgr Project Support', 'Head of PMO'];

    public const APPROVERS_V3_LEGACY = ['PM Konstruksi', 'Admin Area', 'Mgr Deployment', 'Mgr Bussiness Support', 'Head of PMO'];

    public static function usesBusinessSupport(array $document): bool
    {
        return ($document['approvalScheme'] ?? '') === 'business-support'
            || (! isset($document['approvalScheme']) && array_key_exists('Mgr Bussiness Support', $document['approverUserIds'] ?? []) && ! array_key_exists('Mgr Project Support', $document['approverUserIds'] ?? []));
    }

    private function canReviseSubmission(array $document): bool
    {
        return in_array($document['status'], ['Draft', 'Dikembalikan'], true)
            || (($document['workflowVersion'] ?? 1) >= 3 && $document['status'] === 'Ditolak');
    }

    public static function approvalRoles(array $document, bool $project = false): array
    {
        if (($document['workflowVersion'] ?? 1) >= 3) {
            return self::usesBusinessSupport($document) ? self::APPROVERS_V3_LEGACY : self::APPROVERS_V3;
        }

        return $project || ($document['workflowVersion'] ?? 1) >= 2
            ? ['PM Konstruksi', 'Manager Konstruksi'] : ['PM Konstruksi', 'Manager Konstruksi', 'Finance'];
    }

    /** Original SQL run positions are immutable; routing uses approvalRoles instead. */
    public static function storedApprovalRoles(array $document, bool $project = false): array
    {
        return ($document['workflowVersion'] ?? 1) >= 3 && ! self::usesBusinessSupport($document)
            && ($document['approvalScheme'] ?? '') !== 'project-support-first'
                ? self::APPROVERS_V3_PREVIOUS : self::approvalRoles($document, $project);
    }

    public static function nextApprovalRole(array $document, bool $project = false): ?string
    {
        $approved = array_column(array_filter($document['approvals'] ?? [], fn ($a) => $a['decision'] === 'Setujui'), 'role');
        foreach (self::approvalRoles($document, $project) as $role) {
            if (! in_array($role, $approved, true)) {
                return $role;
            }
        }

        return null;
    }

    private function selectedApprover(array $document, string $role): string
    {
        return ($document['workflowVersion'] ?? 1) >= 3
            ? ($document['approverUserIds'][$role] ?? '')
            : ($document[$role === 'PM Konstruksi' ? 'pmUserId' : 'managerUserId'] ?? '');
    }

    private function validateAssignments(array $input, int $version, string $branch, bool $required = true, string $scheme = 'project-support-first'): array
    {
        $document = $input;
        $document['workflowVersion'] = $version;
        $document['approvalScheme'] = $scheme;
        $result = [];
        foreach ($this->approvalRoles($document, true) as $role) {
            $id = $this->selectedApprover($document, $role);
            if (! $required && $id === '') {
                continue;
            }
            $user = $this->approver($id, $role);
            if (isset($user['branches']) && ! in_array($branch, $user['branches'], true)) {
                $this->fail('Seluruh approver harus ditugaskan pada Branch PP.');
            }
            $result[$role] = $id;
        }

        return $result;
    }

    private function assignPaymentApprovers(string $id, array $assignments): null
    {
        $this->admin();
        $p = $this->record('projects', $id);
        if ((($p['workflowVersion'] ?? 1) >= 3 && ! self::usesBusinessSupport($p)) || $p['status'] !== 'Sah') {
            $this->fail('Penugasan pembayaran berikutnya hanya untuk PP historis yang sudah Sah.');
        }
        $p['paymentApproverUserIds'] = $this->validateAssignments(['approverUserIds' => $assignments], 3, $p['branch']);
        $this->put('projects', $p);
        $this->log('Lima approver pembayaran berikutnya ditetapkan', $p['no'], '/project-plan/'.$id);

        return null;
    }

    private function adoptApprovalOrder(string $kind, string $id, string $supportUserId): null
    {
        $this->admin();
        $this->enum($kind, ['projects', 'terms']);
        $document = $this->record($kind, $id);
        $history = $document[$kind === 'projects' ? 'approvalHistory' : 'history'] ?? [];
        if (($document['workflowVersion'] ?? 1) !== 3 || $document['status'] !== 'Diajukan' || ! self::usesBusinessSupport($document)) {
            $this->fail('Penyesuaian ini hanya untuk pengajuan berjalan yang masih memakai Business Support.');
        }
        // Never rewrite a decision or an earlier rejected/returned cycle.
        if (count($history) !== count($document['approvals'] ?? []) || collect($history)->contains('role', 'Mgr Bussiness Support')) {
            $this->fail('Pengajuan memiliki siklus/keputusan Business Support terdahulu; penyesuaian otomatis ditolak agar riwayat tetap utuh.');
        }
        $project = $kind === 'projects' ? $document : $this->record('projects', $this->record('deals', $this->record('spks', $document['spkId'])['dealId'])['projectId']);
        $assignments = $document['approverUserIds'];
        $previous = $assignments['Mgr Bussiness Support'] ?? null;
        unset($assignments['Mgr Bussiness Support']);
        $assignments['Mgr Project Support'] = $supportUserId;
        $document['approverUserIds'] = $this->validateAssignments(['approverUserIds' => $assignments], 3, $project['branch']);
        // Existing SQL run IDs/positions stay intact. Inbox routing is support-first.
        $document['approvalScheme'] = 'project-support';
        $document['approvalOrderChange'] = ['previousBusinessSupportUserId' => $previous, 'projectSupportUserId' => $supportUserId, 'actorUserId' => $this->state['actorUserId'] ?? null, 'at' => now()->toISOString()];
        if ($kind === 'projects') {
            $user = $this->record('users', $supportUserId);
            $document['signatureUsers']['Mgr Project Support'] = ['userId' => $user['id'], 'name' => $user['name'], 'nik' => $user['nik'] ?? ''];
        }
        $this->put($kind, $document);
        $this->log('Urutan approval diperbarui · Project Support sebelum Deployment', $kind === 'projects' ? $document['no'] : 'Termin '.$document['number'], $kind === 'projects' ? '/project-plan/'.$id : '/termin/'.$id);

        return null;
    }
}
