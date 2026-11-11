<?php

namespace App\Services;

use App\Jobs\ProcessUpload;
use App\Models\PrototypeWorkspace;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class UploadQueue
{
    public function term(PrototypeWorkspace $workspace, string $id): array
    {
        $account = app(AuthenticatedWorkspace::class)->account();
        $record = DB::table('payment_terms as t')->join('contracts as c', 'c.id_contract', '=', 't.contract_id')
            ->join('project_plans as p', 'p.id_pp', '=', 'c.pp_id')->where('t.workspace_id', $workspace->id)->where('t.source_id', $id)
            ->whereIn('p.branch_id', $account['branchIds'])->first(['t.document_data']);
        abort_unless($record, 404);
        $term = json_decode($record->document_data, true, 512, JSON_THROW_ON_ERROR);

        return $term;
    }

    public function enqueue(PrototypeWorkspace $workspace, UploadedFile $file, string $kind, string $document, string $requestId, ?int $revision): array
    {
        $account = app(AuthenticatedWorkspace::class)->account();
        abort_unless(in_array($account['role'], $kind === 'transfer-proof' ? ['Finance'] : ['Admin Konstruksi', 'Superadmin'], true), 403);
        if (in_array($kind, FinanceDocuments::KINDS, true)) {
            abort_unless(DB::table('migrations')->where('migration', '2026_11_11_000001_create_finance_kasbon_workflow')->exists(), 503, 'Upload dokumen kasbon belum siap. Administrator perlu menjalankan migration kasbon dan memuat ulang worker aplikasi.');
        }
        $hash = hash_file('sha256', $file->getRealPath());

        return DB::transaction(function () use ($workspace, $file, $kind, $document, $requestId, $revision, $hash) {
            $row = PrototypeWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('upload_tasks')->where('request_uuid', $requestId)->first();
            if ($existing) {
                if (in_array($kind, FinanceDocuments::KINDS, true)) {
                    app(FinanceDocuments::class)->authorize($row, $document, $kind === 'transfer-proof');
                }
                abort_unless((int) $existing->user_id === (int) auth()->id() && $existing->workspace_id === $row->id && $existing->kind === $kind && $existing->document_id === $document && $existing->sha256 === $hash, 409, 'ID upload sudah digunakan untuk file lain.');

                return $this->describe($existing);
            }
            abort_if($revision !== null && $row->revision !== $revision, 409, 'Data berubah. Muat ulang sebelum mengunggah.');
            $context = null;
            if (in_array($kind, FinanceDocuments::KINDS, true)) {
                $record = app(FinanceDocuments::class)->authorizeUpload($row, $document, $kind);
                if ($kind === 'transfer-proof') {
                    $context = json_decode($record->term_data, true, 512, JSON_THROW_ON_ERROR)['kasbon']['id'];
                }
            }
            if ($kind === 'evidence') {
                $term = $this->term($row, $document);
                abort_unless($this->editable($term), 422, 'Lampiran terkunci; pengajuan harus dapat diperbaiki Admin.');
                $pending = DB::table('upload_tasks')->where('workspace_id', $row->id)->where('kind', 'evidence')->where('document_id', $document)->whereIn('status', ['queued', 'processing'])->count();
                abort_if(count(array_filter($term['workEvidence'] ?? [], fn ($f) => empty($f['removedAt']))) + $pending >= 10, 422, 'Maksimal 10 eviden aktif, termasuk yang sedang antre.');
            }
            abort_if(DB::table('upload_tasks')->where('user_id', auth()->id())->whereIn('status', ['queued', 'processing'])->count() >= 10, 422, 'Maksimal 10 upload sedang diproses. Tunggu antrean selesai.');
            $id = (string) Str::uuid();
            $name = mb_substr(preg_replace('/[\x00-\x1F\x7F\\\\\/]/u', '_', basename($file->getClientOriginalName())), 0, 200);
            $path = $file->storeAs('upload-queue/'.$row->id.'/'.$id, 'source', 'local');
            abort_unless($path, 500, 'File gagal disimpan.');
            try {
                $task = ['id_upload_task' => $id, 'workspace_id' => $row->id, 'user_id' => auth()->id(), 'request_uuid' => $requestId,
                    'kind' => $kind, 'document_id' => $document, 'status' => 'queued', 'original_name' => in_array($kind, FinanceDocuments::KINDS, true) ? $kind : ($name ?: 'upload'), 'storage_key' => $path,
                    'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(), 'sha256' => $hash, 'created_at' => now(), 'updated_at' => now()];
                // BOQ/work evidence must also run on the pre-kasbon upload schema.
                // Only transfer proof needs the immutable kasbon-version binding.
                if ($kind === 'transfer-proof') {
                    $task['context_id'] = $context;
                }
                DB::table('upload_tasks')->insert($task);
                // Same database transaction for task and job: there is no unqueued task after commit.
                ProcessUpload::dispatch($id)->beforeCommit();
            } catch (Throwable $error) {
                Storage::disk('local')->deleteDirectory(dirname($path));
                throw $error;
            }

            return $this->describe(DB::table('upload_tasks')->where('id_upload_task', $id)->first());
        });
    }

    public function describe(object $task): array
    {
        return ['id' => $task->id_upload_task, 'kind' => $task->kind, 'documentId' => $task->document_id, 'name' => $task->original_name,
            'status' => $task->status, 'attempts' => $task->attempts, 'error' => $task->error, 'createdAt' => $task->created_at];
    }

    private function editable(array $term): bool
    {
        return in_array($term['status'], ['Draft', 'Dikembalikan'], true) || (($term['workflowVersion'] ?? 1) >= 3 && $term['status'] === 'Ditolak');
    }

    public function process(string $id): void
    {
        $task = DB::table('upload_tasks')->where('id_upload_task', $id)->first();
        if (! $task || in_array($task->status, ['completed', 'failed'], true)) {
            return;
        }
        $previous = Auth::user();
        try {
            $user = User::find($task->user_id);
            abort_unless(app(AccountAccess::class)->context($user), 403, 'Akses akun pengunggah sudah tidak aktif.');
            Auth::setUser($user);
            abort_unless(in_array(app(AuthenticatedWorkspace::class)->account()['role'], $task->kind === 'transfer-proof' ? ['Finance'] : ['Admin Konstruksi', 'Superadmin'], true), 403, 'Peran pengunggah tidak lagi diizinkan.');
            DB::table('upload_tasks')->where('id_upload_task', $id)->increment('attempts', 1, ['status' => 'processing', 'started_at' => now(), 'updated_at' => now()]);
            $disk = Storage::disk('local');
            abort_unless($disk->exists($task->storage_key) && hash_file('sha256', $disk->path($task->storage_key)) === $task->sha256, 422, 'File upload tidak ditemukan atau tidak utuh. Unggah ulang file.');
            $workspace = PrototypeWorkspace::findOrFail($task->workspace_id);
            if ($task->kind === 'boq') {
                $metadata = json_decode(DB::table('workflow_metadata')->where('id_workspace', $workspace->id)->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
                $catalog = app(AuthenticatedWorkspace::class)->catalog($metadata['khs']);
                app(BoqImportStorage::class)->store($workspace, new UploadedFile($disk->path($task->storage_key), $task->original_name, null, null, true), $catalog, $id);
                DB::table('upload_tasks')->where('id_upload_task', $id)->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now(), 'error' => null]);
            } else {
                $private = in_array($task->kind, FinanceDocuments::KINDS, true);
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($disk->path($task->storage_key));
                $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
                abort_unless($ext && ($ext === 'pdf' || @getimagesize($disk->path($task->storage_key))), 422, 'Isi eviden harus PDF atau foto JPG, PNG, WebP yang valid.');
                abort_if($private && $task->kind !== 'transfer-proof' && $ext === 'pdf', 422, 'KTP/buku tabungan harus berupa foto.');
                $target = ($private ? 'finance-private/' : 'work-evidence/').$workspace->id.'/'.$task->document_id.'/'.$id.'.'.$ext;
                abort_unless($disk->copy($task->storage_key, $target), 500, 'Eviden gagal disiapkan.');
                try {
                    if ($private) {
                        app(FinanceDocuments::class)->attach($workspace, $task, $mime, $ext, $target);
                    } else {
                        DB::transaction(function () use ($workspace, $task, $id, $mime, $ext) {
                            $row = PrototypeWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
                            $this->term($row, $task->document_id); // Recheck current Branch assignment, not enqueue-time permissions.
                            app(PrototypeWorkspaceService::class)->command($row, ['command' => '_attachTermWorkEvidence', 'args' => [$task->document_id,
                                ['id' => $id, 'name' => $task->original_name, 'mime' => $mime, 'extension' => $ext, 'size' => (int) $task->size, 'sha256' => $task->sha256]],
                                'revision' => $row->revision, 'request_id' => $task->request_uuid], false);
                            DB::table('upload_tasks')->where('id_upload_task', $id)->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now(), 'error' => null]);
                        });
                    }
                } catch (Throwable $error) {
                    // Preserve bytes on uncertain/transient DB failures; terminal cleanup below
                    // removes only unlinked files, never a successfully committed attachment.
                    throw $error;
                }
            }
            $disk->deleteDirectory(dirname($task->storage_key));
        } catch (ValidationException $error) {
            $this->fail($id, implode(' ', array_merge(...array_values($error->errors()))));
        } catch (Throwable $error) {
            if ($error instanceof HttpExceptionInterface && $error->getStatusCode() < 500) {
                $this->fail($id, $error->getMessage() ?: 'Akses atau status dokumen berubah. Periksa dokumen lalu unggah ulang.');
            } else {
                DB::table('upload_tasks')->where('id_upload_task', $id)->where('status', '!=', 'completed')->update(['status' => 'queued', 'updated_at' => now()]);
                throw $error;
            }
        } finally {
            if ($previous) {
                Auth::setUser($previous);
            } else {
                Auth::forgetUser();
            }
        }
    }

    public function fail(string $id, string $message): void
    {
        $task = DB::table('upload_tasks')->where('id_upload_task', $id)->whereNotIn('status', ['completed', 'failed'])->first();
        if (! $task) {
            return;
        }
        DB::table('upload_tasks')->where('id_upload_task', $id)->update(['status' => 'failed', 'error' => mb_substr($message, 0, 1500), 'completed_at' => now(), 'updated_at' => now()]);
        Storage::disk('local')->deleteDirectory(dirname($task->storage_key));
        if ($task->kind === 'evidence' || in_array($task->kind, FinanceDocuments::KINDS, true)) {
            $private = in_array($task->kind, FinanceDocuments::KINDS, true);
            $prefix = ($private ? 'finance-private/' : 'work-evidence/').$task->workspace_id.'/'.$task->document_id.'/'.$task->id_upload_task;
            foreach (['pdf', 'jpg', 'png', 'webp'] as $extension) {
                $key = $prefix.'.'.$extension;
                if (! ($private ? DB::table('finance_private_files')->where('storage_key', $key)->exists() : DB::table('document_files')->where('storage_disk', 'local')->where('storage_key', $key)->exists())) {
                    Storage::disk('local')->delete($key);
                }
            }
        }
    }
}
