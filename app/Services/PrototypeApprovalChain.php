<?php

namespace App\Services;

trait PrototypeApprovalChain
{
    public const APPROVERS_V3 = ['PM Konstruksi', 'Admin Area', 'Mgr Deployment', 'Mgr Project Support', 'Head of PMO'];

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

    private function selectedApprover(array $document, string $role): string
    {
        return ($document['workflowVersion'] ?? 1) >= 3
            ? ($document['approverUserIds'][$role] ?? '')
            : ($document[$role === 'PM Konstruksi' ? 'pmUserId' : 'managerUserId'] ?? '');
    }

    private function validateAssignments(array $input, int $version, string $branch, bool $required = true, string $scheme = 'project-support'): array
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
}
