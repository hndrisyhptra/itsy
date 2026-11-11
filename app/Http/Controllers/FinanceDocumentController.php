<?php

namespace App\Http\Controllers;

use App\Services\FinanceDocuments;
use App\Services\PrototypeWorkspaceService;
use App\Services\UploadQueue;
use App\Services\UploadWorkerLauncher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FinanceDocumentController extends Controller
{
    public function payee(Request $request, string $spk, FinanceDocuments $docs, PrototypeWorkspaceService $workspaces)
    {
        $w = $workspaces->workspace($request);
        if ($request->isMethod('post')) {
            $input = $request->validate(['request_id' => 'required|uuid', 'revision' => 'required|integer|min:0', 'data' => 'required|array|max:10']);

            return response()->json($docs->savePayee($w, $spk, $input));
        }

        return response()->json($docs->payee($w, $spk, ! $request->boolean('profile_only')))->header('Cache-Control', 'private, no-store');
    }

    public function kasbon(Request $request, string $term, FinanceDocuments $docs, PrototypeWorkspaceService $workspaces)
    {
        $w = $workspaces->workspace($request);
        if ($request->isMethod('post')) {
            $input = $request->validate(['request_id' => 'required|uuid', 'revision' => 'required|integer|min:0', 'data' => 'required|array|max:15']);

            return response()->json($docs->issue($w, $term, $input));
        }

        return response()->json($docs->kasbon($w, $term, ! $request->boolean('documents_only')))->header('Cache-Control', 'private, no-store');
    }

    public function upload(Request $request, string $kind, string $document, PrototypeWorkspaceService $workspaces, UploadQueue $queue)
    {
        $input = $request->validate(['file' => $kind === 'transfer-proof' ? 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:10240' : 'required|file|mimes:jpg,jpeg,png,webp|max:10240', 'request_id' => 'required|uuid', 'revision' => 'required|integer|min:0']);
        $w = $workspaces->workspace($request);
        $task = $queue->enqueue($w, $request->file('file'), $kind, $document, $input['request_id'], (int) $input['revision']);
        app(UploadWorkerLauncher::class)->start();

        return response()->json(['task' => $task], 202);
    }

    public function file(Request $request, string $file, FinanceDocuments $docs, PrototypeWorkspaceService $workspaces)
    {
        $f = $docs->file($workspaces->workspace($request), $file);

        return response()->file(Storage::disk('local')->path($f->storage_key), ['Content-Type' => $f->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "frame-ancestors 'self'"])
            ->setContentDisposition($request->boolean('download') ? 'attachment' : 'inline', $f->kind.'.'.$f->extension);
    }
}
