<?php

namespace App\Http\Controllers;

use App\Services\NegotiationProjectCatalog;
use Illuminate\Http\Request;

class NegotiationProjectController extends Controller
{
    public function projects(Request $request, NegotiationProjectCatalog $catalog)
    {
        if (is_string($request->query('q'))) {
            $request->merge(['q' => trim($request->query('q'))]);
        }
        $input = $request->validate(['q' => 'required|string|min:2|max:100', 'page' => 'sometimes|integer|min:1|max:1000']);

        return response()->json($catalog->projects($request->attributes->get('area3.account'), $input['q'], (int) ($input['page'] ?? 1)));
    }

    public function plans(Request $request, string $project, NegotiationProjectCatalog $catalog)
    {
        $input = $request->validate(['page' => 'sometimes|integer|min:1|max:1000']);

        return response()->json($catalog->plans($request->attributes->get('area3.account'), $project, (int) ($input['page'] ?? 1)));
    }
}
