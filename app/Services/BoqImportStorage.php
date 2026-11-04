<?php

namespace App\Services;

use App\Models\PrototypeWorkspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class BoqImportStorage
{
    public function directory(PrototypeWorkspace $workspace, string $id): string
    {
        abort_unless(Str::isUuid($id), 422, 'Referensi upload BOQ tidak valid.');

        return 'prototype-boq/'.$workspace->id.'/'.$id;
    }

    public function store(PrototypeWorkspace $workspace, UploadedFile $file, array $catalog): array
    {
        $parsed = app(BoqWorkbookImport::class)->parse($file->getRealPath(), $catalog);
        $id = (string) Str::uuid();
        $directory = $this->directory($workspace, $id);
        $parsed['source'] += ['id' => $id, 'name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename($file->getClientOriginalName())), 0, 200), 'sha256' => hash_file('sha256', $file->getRealPath()), 'size' => $file->getSize(), 'uploadedBy' => (string) auth()->id(), 'uploadedAt' => now()->toISOString()];
        try {
            abort_unless($file->storeAs($directory, 'source.xlsx', 'local'), 500, 'File BOQ gagal disimpan.');
            abort_unless(Storage::disk('local')->put($directory.'/manifest.json', json_encode($parsed, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)), 500, 'Hasil pembacaan BOQ gagal disimpan.');
        } catch (Throwable $error) {
            Storage::disk('local')->deleteDirectory($directory);
            throw $error;
        }

        return $parsed;
    }

    public function read(PrototypeWorkspace $workspace, string $id, ?array $project): array
    {
        $directory = $this->directory($workspace, $id);
        abort_unless(Storage::disk('local')->exists($directory.'/manifest.json'), 422, 'File BOQ belum ditemukan. Upload ulang file Excel.');
        $parsed = json_decode(Storage::disk('local')->get($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        abort_unless($parsed['source']['uploadedBy'] === (string) auth()->id() || ($project['boqUpload']['id'] ?? '') === $id, 403, 'Upload BOQ belum ditautkan ke PP yang dapat Anda edit.');

        return $parsed;
    }
}
