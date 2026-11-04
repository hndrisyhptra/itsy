<?php

namespace App\Http\Controllers;

use App\Services\BoqImportStorage;
use App\Services\PrototypeWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PrototypeBoqController extends Controller
{
    public function store(Request $request, PrototypeWorkspaceService $service, BoqImportStorage $imports)
    {
        $request->validate(['file' => 'required|file|extensions:xlsx|max:10240']);
        abort_unless(in_array($request->attributes->get('area3.account')['role'] ?? '', ['Admin Konstruksi', 'Superadmin'], true), 403);
        $workspace = $service->workspace($request);

        return response()->json($imports->store($workspace, $request->file('file'), $service->payload($workspace)['state']['khs']));
    }

    public function show(Request $request, string $project, PrototypeWorkspaceService $service, BoqImportStorage $imports)
    {
        $workspace = $service->workspace($request);
        $p = collect($service->payload($workspace)['state']['projects'])->firstWhere('id', $project);
        abort_unless(isset($p['boqUpload']), 404);
        $path = $imports->directory($workspace, $p['boqUpload']['id']).'/source.xlsx';
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $p['boqUpload']['name'], ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff']);
    }
}
