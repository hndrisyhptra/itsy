<?php

namespace App\Http\Controllers;

use App\Models\PrototypeWorkspace;
use App\Services\MasterDesignatorService;
use App\Services\PrototypeWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MasterDesignatorController extends Controller
{
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
