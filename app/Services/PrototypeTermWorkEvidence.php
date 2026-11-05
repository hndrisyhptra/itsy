<?php

namespace App\Services;

use Illuminate\Support\Str;

trait PrototypeTermWorkEvidence
{
    private function attachTermWorkEvidence(string $id, array $input): null
    {
        $this->admin();
        $term = $this->record('terms', $id);
        if (! $this->canReviseSubmission($term)) {
            $this->fail('Eviden pekerjaan hanya dapat diunggah pada pengajuan yang dapat diperbaiki Admin.');
        }
        if (count(array_filter($term['workEvidence'] ?? [], fn ($f) => empty($f['removedAt']))) >= 10) {
            $this->fail('Maksimal 10 lampiran eviden pekerjaan aktif per termin.');
        }
        $types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (! Str::isUuid($input['id'] ?? '') || ($types[$input['mime'] ?? ''] ?? null) !== ($input['extension'] ?? '')
            || ! is_int($input['size'] ?? null) || $input['size'] <= 0 || $input['size'] > 10 * 1024 * 1024
            || ! preg_match('/^[a-f0-9]{64}$/D', $input['sha256'] ?? '')) {
            $this->fail('Metadata eviden pekerjaan tidak valid.');
        }
        $file = array_intersect_key($input, array_flip(['id', 'name', 'mime', 'extension', 'size', 'sha256']));
        $file['name'] = $this->text($file['name'] ?? '', 200);
        $file['uploadedBy'] = $this->state['actorUserId'];
        $file['uploadedAt'] = now()->toISOString();
        $term['workEvidence'][] = $file;
        $this->put('terms', $term);
        $this->log('Eviden pekerjaan diunggah', 'Termin '.$term['number'].' · '.$file['name'], '/termin/'.$id);

        return null;
    }

    private function removeTermWorkEvidence(string $id, string $fileId): null
    {
        $this->admin();
        $term = $this->record('terms', $id);
        if (! $this->canReviseSubmission($term)) {
            $this->fail('Lampiran terkunci selama approval atau setelah pengajuan selesai.');
        }
        $found = false;
        foreach ($term['workEvidence'] ?? [] as $i => $file) {
            if ($file['id'] === $fileId && empty($file['removedAt'])) {
                $term['workEvidence'][$i]['removedAt'] = now()->toISOString();
                $term['workEvidence'][$i]['removedBy'] = $this->state['actorUserId'];
                $found = true;
                break;
            }
        }
        if (! $found) {
            $this->fail('Lampiran eviden pekerjaan aktif tidak ditemukan.');
        }
        $this->put('terms', $term);
        $this->log('Eviden pekerjaan dikeluarkan dari pengajuan', 'Termin '.$term['number'], '/termin/'.$id);

        return null;
    }
}
