<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/** PP, BA KH and SPK are one immutable submission, backed by the PP's five decisions. */
trait ProjectContractBundle
{
    private ?string $submittingContractBundle = null;

    public static function usesContractBundle(array $project): bool
    {
        return ($project['contractBundle']['version'] ?? null) === 1;
    }

    private function saveProjectBundle(array $input, bool $submit): string
    {
        $old = $this->existing('projects', $input['id'] ?? '');
        if ($old && ($old['workflowVersion'] ?? 1) < 3) {
            $this->fail('PP historis tetap memakai alur tersimpan.');
        }
        if ($old && ! self::usesContractBundle($old) && (! empty($old['approvalHistory']) || ! empty($old['approvals']) || $old['status'] !== 'Draft')) {
            $this->fail('PP dengan riwayat approval tetap memakai alur tersimpan.');
        }
        if ($submit) {
            $this->fail('Simpan PP, lengkapi BA KH dan SPK, lalu Ajukan Paket dari SPK.');
        }
        $id = $this->saveProjectV2($input, false);
        $p = $this->record('projects', $id);
        $p['contractBundle'] = $old['contractBundle'] ?? ['version' => 1, 'submissions' => []];
        $this->put('projects', $p);

        return $id;
    }

    private function bundleDocuments(string $id): array
    {
        $deals = array_values(array_filter($this->state['deals'], fn ($d) => $d['projectId'] === $id));
        $dealIds = array_fill_keys(array_column($deals, 'id'), true);
        $spks = array_values(array_filter($this->state['spks'], fn ($s) => isset($dealIds[$s['dealId']])));

        return [$deals, $spks];
    }

    private function submitProjectBundle(string $id, ?array $import): null
    {
        $this->admin();
        $p = $this->record('projects', $id);
        if (! self::usesContractBundle($p) || ! $this->canReviseSubmission($p)) {
            $this->fail('Paket PP belum dapat diajukan pada status ini.');
        }
        [$deals, $spks] = $this->bundleDocuments($id);
        if (! $deals || count($spks) !== count($deals)) {
            $this->fail('Lengkapi BA KH dan satu SPK untuk setiap BA KH sebelum mengajukan paket PP.');
        }
        $p['_boqImport'] = $import;
        $this->submittingContractBundle = $id;
        try {
            $this->saveProjectV2($p, true);
        } finally {
            $this->submittingContractBundle = null;
        }
        $p = $this->record('projects', $id);
        $manifest = ['id' => (string) Str::uuid(), 'at' => now()->toISOString(), 'documents' => []];
        foreach ($deals as $deal) {
            if ($deal['status'] !== 'Draft') {
                $this->fail('BA KH paket harus berupa draf yang belum disahkan terpisah.');
            }
            $deal = BakhSigner::sync($this->state, $deal, true);
            $this->put('deals', $deal);
            $this->validateDeal($deal, true);
            $manifest['documents']['deals'][$deal['id']] = $this->bundleHash($deal);
        }
        foreach ($spks as $spk) {
            if ($spk['status'] !== 'Draft' || ($spk['payeeVersion'] ?? 0) < 1) {
                $this->fail('Simpan draf SPK dan lengkapi identitas/rekening Mandor sebelum pengajuan paket.');
            }
            $spk = SpkSigner::sync($this->state, $spk, true);
            $this->put('spks', $spk);
            $this->validateSpkDocument($spk, true);
            $manifest['documents']['spks'][$spk['id']] = $this->bundleHash($spk);
        }
        $p['contractBundle']['submissions'][] = $manifest;
        $this->put('projects', $p);
        $this->log('Paket PP, BA KH dan SPK diajukan', $p['no'], '/project-plan/'.$id);

        return null;
    }

    private function bundleHash(array $document): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($document), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function approveProjectBundle(array $p): void
    {
        if (! self::usesContractBundle($p)) {
            return;
        }
        [$deals, $spks] = $this->bundleDocuments($p['id']);
        $submission = end($p['contractBundle']['submissions']);
        foreach (['deals' => $deals, 'spks' => $spks] as $collection => $documents) {
            if (count($documents) !== count($submission['documents'][$collection] ?? [])) {
                $this->fail('Berkas paket berubah; pengajuan harus diperiksa kembali.');
            }
            foreach ($documents as $document) {
                if (($submission['documents'][$collection][$document['id']] ?? '') !== $this->bundleHash($document)) {
                    $this->fail('Isi berkas paket berbeda dari versi yang diajukan.');
                }
                if ($collection === 'deals') {
                    $this->validateDeal($document, true);
                } else {
                    $this->validateSpkDocument($document, true);
                }
                $document['status'] = 'Sah';
                $document['approvedWithProject'] = ['ppId' => $p['id'], 'submissionId' => $submission['id']];
                $this->put($collection, $document);
            }
        }
    }

    private function assertBundleDocumentEditable(array $project, string $action): void
    {
        if (self::usesContractBundle($project) && (! $this->canReviseSubmission($project) || $action !== 'save')) {
            $this->fail('BA KH dan SPK paket hanya dapat diedit sebagai draf oleh Admin sebelum pengajuan. Persetujuan dilakukan bersama PP.');
        }
    }

    private function validateSpkDocument(array $v, bool $formal): void
    {
        $d = $this->record('deals', $v['dealId']);
        if ($formal) {
            $this->required($v, ['place', 'date', 'start', 'end', 'address', 'supervisor', 'phone', 'signer', 'position']);
            $this->date($v['date']);
        }
        $this->date($v['start']);
        $this->date($v['end']);
        if ($v['start'] > $v['end'] || $v['start'] < $d['start'] || $v['end'] > $d['end']) {
            $this->fail('Periode SPK harus dalam periode BA KH.');
        }
    }
}
