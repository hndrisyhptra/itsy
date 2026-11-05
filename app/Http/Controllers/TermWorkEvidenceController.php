<?php

namespace App\Http\Controllers;

use App\Services\PrototypeWorkspaceService;
use App\Services\RelationalWorkflowStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class TermWorkEvidenceController extends Controller
{
    public function store(Request $request, string $term, PrototypeWorkspaceService $service)
    {
        $input = $request->validate([
            'request_id' => 'required|uuid', 'revision' => 'required|integer|min:0',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:10240',
        ]);
        $workspace = $service->workspace($request);
        $state = $service->payload($workspace)['state'];
        abort_unless(collect($state['terms'])->contains('id', $term), 404);
        abort_unless(in_array($state['role'], ['Admin Konstruksi', 'Superadmin'], true), 403);
        $upload = $request->file('file');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath());
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (! $extension || ($mime !== 'application/pdf' && ! @getimagesize($upload->getRealPath()))) {
            throw ValidationException::withMessages(['file' => 'Isi eviden harus PDF atau foto JPG, PNG, WebP yang valid.']);
        }
        $id = (string) Str::uuid();
        $name = mb_substr(preg_replace('/[\x00-\x1F\x7F\\\\\/]/u', '_', basename($upload->getClientOriginalName())), 0, 200);
        $metadata = ['id' => $id, 'name' => $name ?: 'eviden.'.$extension, 'mime' => $mime, 'extension' => $extension,
            'size' => $upload->getSize(), 'sha256' => hash_file('sha256', $upload->getRealPath())];
        $path = $upload->storeAs('work-evidence/'.$workspace->id.'/'.$term, $id.'.'.$extension, 'local');
        abort_unless($path, 500, 'Eviden pekerjaan gagal disimpan.');
        try {
            $result = $service->command($workspace, ['command' => '_attachTermWorkEvidence', 'args' => [$term, $metadata],
                'revision' => (int) $input['revision'], 'request_id' => $input['request_id']]);
            if ($result['replayed'] ?? false) {
                Storage::disk('local')->delete($path);
            }

            return response()->json($result);
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    public function show(Request $request, string $term, string $evidence, PrototypeWorkspaceService $service, RelationalWorkflowStore $store)
    {
        $workspace = $service->workspace($request);
        $record = collect($service->payload($workspace)['state']['terms'])->firstWhere('id', $term);
        abort_unless($record && collect($record['workEvidence'] ?? [])->contains('id', $evidence), 404);
        $file = DB::table('term_work_evidence as e')->join('document_files as f', 'f.id_document_file', '=', 'e.file_id')
            ->where('e.id_term_evidence', $store->id($workspace->id, 'term-evidence', $evidence))
            ->where('e.term_id', $store->id($workspace->id, 'terms', $term))->first();
        abort_unless($file && $file->storage_disk === 'local' && str_starts_with($file->storage_key, 'work-evidence/'.$workspace->id.'/'.$term.'/'), 404);
        abort_unless(Storage::disk('local')->exists($file->storage_key), 404);

        return response()->file(Storage::disk('local')->path($file->storage_key), [
            'Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "frame-ancestors 'self'",
        ])->setContentDisposition($request->boolean('download') ? 'attachment' : 'inline', $file->original_name);
    }
}
