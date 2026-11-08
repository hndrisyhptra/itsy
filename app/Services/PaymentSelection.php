<?php

namespace App\Services;

/** Read-only routing hint; createTerm remains the authority for all workflow validation. */
final class PaymentSelection
{
    public static function action(array $terms, bool $ppApproved): array
    {
        usort($terms, fn ($a, $b) => $a['number'] <=> $b['number']);
        $latest = $terms ? $terms[array_key_last($terms)] : null;
        if ($latest && ($latest['status'] !== 'Disetujui' || $latest['apmStatus'] !== 'Valid')) {
            $editable = in_array($latest['status'], ['Draft', 'Dikembalikan'], true) || ($latest['status'] === 'Ditolak' && $latest['version'] >= 3);

            return ['action' => 'open', 'label' => $editable ? ($latest['status'] === 'Draft' ? 'Lanjutkan Draf' : 'Perbaiki Pengajuan') : 'Buka Pengajuan', 'termId' => $latest['id'], 'nextNumber' => null, 'reason' => ''];
        }
        if (count($terms) >= 3 || collect($terms)->contains('type', 'Final')) {
            return ['action' => 'open', 'label' => 'Buka Pengajuan', 'termId' => $latest['id'], 'nextNumber' => null, 'reason' => 'Termin Final sudah dibuat atau batas tiga termin tercapai.'];
        }

        return ['action' => $ppApproved ? 'create' : 'blocked', 'label' => 'Pilih SPK', 'termId' => '', 'nextNumber' => count($terms) + 1, 'reason' => $ppApproved ? '' : 'PP belum Sah.'];
    }
}
