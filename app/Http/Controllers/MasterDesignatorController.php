<?php

namespace App\Http\Controllers;

use App\Models\PrototypeWorkspace;
use App\Services\MasterDesignatorService;
use App\Services\MasterImportWorkbook;
use App\Services\PrototypeWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MasterDesignatorController extends Controller
{
    public function template(Request $request, string $entity, MasterDesignatorService $masters, MasterImportWorkbook $files)
    {
        abort_unless($request->attributes->get('area3.account')['code'] === 'SUPERADMIN', 403);

        return response($files->template($entity, $masters->templateCatalog($entity)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="template-'.$entity.'.xlsx"',
        ]);
    }

    public function import(Request $request, string $entity, PrototypeWorkspaceService $workspaces, MasterDesignatorService $masters, MasterImportWorkbook $files)
    {
        abort_unless($request->attributes->get('area3.account')['code'] === 'SUPERADMIN', 403);
        $input = $request->validate(['file' => 'required|file|extensions:xlsx|mimes:xlsx|max:5120',
            'mode' => ['required', Rule::in(['create', 'upsert'])], 'stage' => ['required', Rule::in(['preview', 'commit'])],
            'revision' => 'required|integer|min:0', 'request_id' => 'required_if:stage,commit|uuid',
            'sha256' => 'required_if:stage,commit|string|size:64']);
        // Multipart fields arrive as strings; validation checks but does not cast them.
        $input['revision'] = (int) $input['revision'];
        $hash = hash_file('sha256', $request->file('file')->getRealPath());
        abort_if($input['stage'] === 'commit' && ! hash_equals($hash, $input['sha256']), 422, 'File berbeda dari hasil pemeriksaan. Periksa file kembali.');
        $input['sha256'] = $hash;
        $rows = $files->read($request->file('file')->getRealPath(), $entity);

        return response()->json($masters->import($workspaces->workspace($request), $entity, $input, $rows, $input['stage'] === 'preview'));
    }

    public function index(Request $request, PrototypeWorkspaceService $workspaces, MasterDesignatorService $masters)
    {
        $workspace = $workspaces->workspace($request);

        return DB::transaction(function () use ($workspace, $masters) {
            $row = PrototypeWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            return response()->json(['catalog' => $masters->catalog(), 'revision' => $row->revision]);
        });
    }

    public function store(Request $request, string $entity, PrototypeWorkspaceService $workspaces, MasterDesignatorService $masters)
    {
        abort_unless($request->attributes->get('area3.account')['code'] === 'SUPERADMIN', 403);
        $input = $request->validate(['action' => ['required', Rule::in(['save', 'delete'])], 'id' => 'sometimes|integer|min:1',
            'data' => 'sometimes|array', 'revision' => 'required|integer|min:0', 'request_id' => 'required|uuid']);

        return response()->json($masters->change($workspaces->workspace($request), $entity, $input));
    }
}
