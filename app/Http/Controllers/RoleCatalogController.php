<?php

namespace App\Http\Controllers;

use App\Services\RoleCatalog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleCatalogController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->attributes->get('area3.account')['code'] === 'SUPERADMIN', 403);
    }

    public function index(Request $request, RoleCatalog $roles)
    {
        $this->authorizeAdmin($request);
        $input = $request->validate(['q' => 'sometimes|nullable|string|max:100', 'page' => 'sometimes|integer|min:1|max:3277']);

        return response()->json($roles->listing(trim($input['q'] ?? ''), (int) ($input['page'] ?? 1)))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, RoleCatalog $roles)
    {
        $this->authorizeAdmin($request);
        $input = $request->validate(['action' => ['required', Rule::in(['save', 'delete'])], 'id' => 'required_if:action,delete|integer|min:1|max:65535',
            'code' => ['required', 'string', 'max:40', 'regex:/\A[A-Z][A-Z0-9_]{1,39}\z/'],
            'name' => ['required_if:action,save', 'string', 'max:100', 'regex:/\A[^\p{C}<>]+\z/u'],
            'version' => 'required_with:id|string|size:64', 'request_id' => 'required|uuid']);
        // Stable canonical payload for actor-bound UUID replay; ignore all unsupported fields.
        $input = ['action' => $input['action'], 'code' => $input['code'], 'name' => isset($input['name']) ? trim($input['name']) : null,
            'request_id' => $input['request_id']] + array_intersect_key($input, array_flip(['id', 'version']));
        if (isset($input['id'])) {
            $input['id'] = (int) $input['id'];
        }

        return response()->json($roles->change((int) $request->user()->getKey(), $input))->header('Cache-Control', 'private, no-store');
    }
}
