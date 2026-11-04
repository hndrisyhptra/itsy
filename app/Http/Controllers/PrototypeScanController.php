<?php

namespace App\Http\Controllers;

use App\Services\PrototypeWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PrototypeScanController extends Controller
{
    public function store(Request $request, string $term, PrototypeWorkspaceService $service)
    {
        $input = $request->validate([
            'request_id' => 'required|uuid', 'revision' => 'required|integer|min:0',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);
        $workspace = $service->workspace($request);
        $state = $service->payload($workspace)['state'];
        abort_unless(collect($state['terms'])->contains('id', $term), 404);
        abort_unless(in_array($state['role'], ['Admin Konstruksi', 'Superadmin'], true), 403);
        $upload = $request->file('file');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath());
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
        if (! $extension) {
            throw ValidationException::withMessages(['file' => 'Isi scan harus PDF, JPG atau PNG yang valid.']);
        }
        $id = (string) Str::uuid();
        $directory = 'prototype-scans/'.$workspace->id.'/'.$term;
        $path = $upload->storeAs($directory, $id.'.'.$extension, 'local');
        abort_unless($path, 500, 'Scan gagal disimpan.');
        $metadata = ['id' => $id, 'name' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', basename($upload->getClientOriginalName())), 0, 200), 'mime' => $mime, 'extension' => $extension, 'size' => $upload->getSize(), 'sha256' => hash_file('sha256', $upload->getRealPath())];
        try {
            $result = $service->command($workspace, ['command' => '_attachTermScan', 'args' => [$term, $metadata], 'revision' => (int) $input['revision'], 'request_id' => $input['request_id']]);
            if ($result['replayed'] ?? false) {
                Storage::disk('local')->delete($path);
            }

            return response()->json($result);
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    public function show(Request $request, string $term, PrototypeWorkspaceService $service)
    {
        $workspace = $service->workspace($request);
        $record = collect($service->payload($workspace)['state']['terms'])->firstWhere('id', $term);
        abort_unless(isset($record['scan']), 404);
        $scan = $record['scan'];
        $path = 'prototype-scans/'.$workspace->id.'/'.$term.'/'.$scan['id'].'.'.$scan['extension'];
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $scan['name'], ['Content-Type' => $scan['mime'], 'X-Content-Type-Options' => 'nosniff']);
    }
}
