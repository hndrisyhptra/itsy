<?php

namespace App\Http\Controllers;

use App\Services\BoqImportStorage;
use App\Services\FinanceDocuments;
use App\Services\PrototypeWorkspaceService;
use App\Services\UploadQueue;
use App\Services\UploadWorkerLauncher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UploadQueueController extends Controller
{
    public function store(Request $request, PrototypeWorkspaceService $workspaces, UploadQueue $uploads, ?string $term = null)
    {
        $kind = $term ? 'evidence' : 'boq';
        $input = $request->validate(['file' => $term ? 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:10240' : 'required|file|extensions:xlsx|max:10240',
            'request_id' => 'required|uuid', 'document_id' => $term ? 'sometimes|string' : 'required|string|regex:/^[a-zA-Z0-9-]{3,100}$/D', 'revision' => 'sometimes|integer|min:0']);
        $task = $uploads->enqueue($workspaces->workspace($request), $request->file('file'), $kind, $term ?? $input['document_id'], $input['request_id'], isset($input['revision']) ? (int) $input['revision'] : null);
        app(UploadWorkerLauncher::class)->start();

        return response()->json(['task' => $task, 'workerOnline' => $this->online()], 202);
    }

    public function index(Request $request, PrototypeWorkspaceService $workspaces, UploadQueue $uploads)
    {
        $input = $request->validate(['kind' => 'required|in:boq,evidence,mandor-ktp,mandor-bank,transfer-proof', 'document_id' => 'required|string|max:100']);
        $workspace = $workspaces->workspace($request);
        if (in_array($input['kind'], FinanceDocuments::KINDS, true)) {
            app(FinanceDocuments::class)->authorize($workspace, $input['document_id'], $input['kind'] === 'transfer-proof');
        }
        if ($input['kind'] === 'evidence') {
            $uploads->term($workspace, $input['document_id']);
        }
        $rows = DB::table('upload_tasks')->where('workspace_id', $workspace->id)->where('user_id', auth()->id())->where('kind', $input['kind'])
            ->where('document_id', $input['document_id'])->orderByDesc('created_at')->limit(10)->get();

        return response()->json(['tasks' => $rows->map(fn ($t) => $uploads->describe($t)), 'workerOnline' => $this->online()]);
    }

    public function show(Request $request, string $task, PrototypeWorkspaceService $workspaces, UploadQueue $uploads)
    {
        $workspace = $workspaces->workspace($request);
        $row = DB::table('upload_tasks')->where('id_upload_task', $task)->where('workspace_id', $workspace->id)->where('user_id', auth()->id())->first();
        abort_unless($row, 404);
        if (in_array($row->kind, FinanceDocuments::KINDS, true)) {
            app(FinanceDocuments::class)->authorize($workspace, $row->document_id, $row->kind === 'transfer-proof');
        }
        if ($row->kind === 'evidence') {
            $uploads->term($workspace, $row->document_id);
        }
        $data = ['task' => $uploads->describe($row), 'workerOnline' => $this->online()];
        if ($row->status === 'completed' && $row->kind === 'boq' && $request->boolean('result')) {
            $data['result'] = app(BoqImportStorage::class)->read($workspace, $row->id_upload_task, null);
        }

        return response()->json($data);
    }

    private function online(): bool
    {
        $path = storage_path('app/private/upload-worker.heartbeat');

        return is_file($path) && (time() - (int) file_get_contents($path)) < 20;
    }

    public function resume(Request $request, string $task, PrototypeWorkspaceService $workspaces, UploadQueue $uploads)
    {
        abort_unless(in_array($request->attributes->get('area3.account')['role'], ['Admin Konstruksi', 'Superadmin', 'Finance'], true), 403);
        $workspace = $workspaces->workspace($request);
        $row = DB::table('upload_tasks')->where('id_upload_task', $task)->where('workspace_id', $workspace->id)->where('user_id', auth()->id())->first();
        abort_unless($row, 404);
        if (in_array($row->kind, FinanceDocuments::KINDS, true)) {
            app(FinanceDocuments::class)->authorize($workspace, $row->document_id, $row->kind === 'transfer-proof');
        }
        if ($row->kind === 'evidence') {
            $uploads->term($workspace, $row->document_id);
        }
        abort_unless(in_array($row->status, ['queued', 'processing'], true), 409, 'Upload sudah selesai.');
        abort_unless(config('uploads.autostart'), 503, 'Worker harus dijalankan oleh administrator server.');
        app(UploadWorkerLauncher::class)->start();

        return response()->json(['message' => 'Worker diminta melanjutkan antrean.'], 202);
    }
}
