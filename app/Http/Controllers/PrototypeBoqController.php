<?php

namespace App\Http\Controllers;

use App\Services\AuthenticatedWorkspace;
use App\Services\BoqImportStorage;
use App\Services\PrototypeWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PrototypeBoqController extends Controller
{
    public function store(Request $request, PrototypeWorkspaceService $service, BoqImportStorage $imports)
    {
        $request->validate(['file' => 'required|file|extensions:xlsx|max:10240']);
        abort_unless(in_array($request->attributes->get('area3.account')['role'] ?? '', ['Admin Konstruksi', 'Superadmin'], true), 403);
        $workspace = $service->workspace($request);

        $metadata = json_decode(DB::table('workflow_metadata')->where('id_workspace', $workspace->id)->value('metadata'), true, 512, JSON_THROW_ON_ERROR);

        return response()->json($imports->store($workspace, $request->file('file'), app(AuthenticatedWorkspace::class)->catalog($metadata['khs'])));
    }

    public function show(Request $request, string $project, PrototypeWorkspaceService $service, BoqImportStorage $imports)
    {
        $workspace = $service->workspace($request);
        $record = DB::table('project_plans as p')->where('p.workspace_id', $workspace->id)->where('p.source_id', $project)
            ->whereIn('p.branch_id', app(AuthenticatedWorkspace::class)->account()['branchIds'])->value('p.document_data');
        $p = $record ? json_decode($record, true, 512, JSON_THROW_ON_ERROR) : null;
        abort_unless(isset($p['boqUpload']), 404);
        $path = $imports->directory($workspace, $p['boqUpload']['id']).'/source.xlsx';
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $p['boqUpload']['name'], ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff']);
    }
}
