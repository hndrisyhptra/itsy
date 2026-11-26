<?php

namespace App\Http\Controllers;

use App\Services\MandorBulkImport;
use App\Services\MandorMaster;
use App\Services\MasterImportWorkbook;
use App\Services\PrototypeWorkspaceService;
use App\Services\UploadQueue;
use App\Services\UploadWorkerLauncher;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MandorMasterController extends Controller
{
    public function template(MandorMaster $master, MasterImportWorkbook $files)
    {
        $master->account();

        return response($files->template('mandor', []), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="template-mandor.xlsx"', 'Cache-Control' => 'private, no-store']);
    }

    public function import(Request $request, MandorMaster $master, MandorBulkImport $imports, PrototypeWorkspaceService $workspaces)
    {
        $master->account();
        $input = $request->validate(['stage' => 'required|in:preview,commit', 'file' => 'required|file|extensions:xlsx|mimes:xlsx|max:5120', 'request_id' => 'required_if:stage,commit|uuid', 'token' => 'required_if:stage,commit|string|size:64', 'sha256' => 'required_if:stage,commit|string|size:64']);
        $sha = hash_file('sha256', $request->file('file')->getRealPath());
        abort_if($input['stage'] === 'commit' && ! hash_equals($sha, $input['sha256']), 422, 'File berubah; periksa file kembali.');
        $rows = $imports->rows($request->file('file')->getRealPath());

        try {
            $result = $imports->run($workspaces->workspace($request), $rows, $sha, $input);
        } catch (QueryException $e) {
            // Recognize this missing deployment prerequisite without querying schema on every import.
            $missingTable = ($e->errorInfo[0] ?? '') === '42S02' && (int) ($e->errorInfo[1] ?? 0) === 1146;
            $missingSqliteTable = ($e->errorInfo[0] ?? '') === 'HY000' && preg_match('/no such table: mandor_imports\b/', $e->errorInfo[2] ?? '');
            if (! str_contains($e->getSql(), 'mandor_imports') || (! $missingTable && ! $missingSqliteTable)) {
                throw $e;
            }

            return response()->json(['code' => 'MANDOR_IMPORT_MIGRATION_REQUIRED', 'message' => 'Impor Mandor belum siap karena pembaruan database belum selesai. Minta administrator menjalankan migration terbaru, lalu coba Simpan Impor kembali.'], 503)->header('Cache-Control', 'private, no-store');
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, MandorMaster $master, PrototypeWorkspaceService $workspaces)
    {
        $master->account();
        $input = $request->validate(['search' => 'sometimes|nullable|string|max:150', 'unit' => 'sometimes|nullable|in:Konstruksi,Operation', 'branch_id' => 'sometimes|nullable|integer|min:1', 'page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|in:10,20,50,100']);

        return response()->json($master->listing($workspaces->workspace($request), $input))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $mandor, MandorMaster $master, PrototypeWorkspaceService $workspaces)
    {
        $master->account();

        return response()->json($master->detail($workspaces->workspace($request), $mandor))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, MandorMaster $master, PrototypeWorkspaceService $workspaces)
    {
        $master->account();
        $input = $request->validate(['id' => 'required|uuid', 'request_id' => 'required|uuid', 'version' => 'required|integer|min:0', 'unit' => 'sometimes|nullable|in:Konstruksi,Operation', 'branch_id' => 'sometimes|nullable|integer|min:1', 'data' => 'required|array:name,address,nik,accountNumber,accountName,bank']);

        return response()->json($master->save($workspaces->workspace($request), $input))->header('Cache-Control', 'private, no-store');
    }

    public function upload(Request $request, string $mandor, string $kind, MandorMaster $master, PrototypeWorkspaceService $workspaces, UploadQueue $queue)
    {
        $master->account();
        $input = $request->validate(['request_id' => 'required|uuid', 'file' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240']);
        $task = $queue->enqueue($workspaces->workspace($request), $request->file('file'), $kind, $mandor, $input['request_id'], null);
        app(UploadWorkerLauncher::class)->start();

        return response()->json(['task' => $task], 202);
    }

    public function file(Request $request, string $file, MandorMaster $master, PrototypeWorkspaceService $workspaces)
    {
        $master->account();
        $f = $master->file($workspaces->workspace($request), $file);

        return response()->file(Storage::disk('local')->path($f->storage_key), ['Content-Type' => $f->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "frame-ancestors 'self'"])->setContentDisposition($request->boolean('download') ? 'attachment' : 'inline', $f->kind.'.'.$f->extension);
    }

    public function apply(Request $request, string $spk, MandorMaster $master, PrototypeWorkspaceService $workspaces)
    {
        $master->account();
        $input = $request->validate(['request_id' => 'required|uuid', 'revision' => 'required|integer|min:0', 'data' => 'required|array:id,version', 'data.id' => 'required|uuid', 'data.version' => 'required|integer|min:1']);

        return response()->json($master->apply($workspaces->workspace($request), $spk, $input));
    }
}
