<?php

namespace App\Http\Controllers;

use App\Services\PaymentProjectCatalog;
use Illuminate\Http\Request;

final class PaymentProjectController extends Controller
{
    public function projects(Request $request, PaymentProjectCatalog $catalog)
    {
        if (is_string($request->query('q'))) {
            $request->merge(['q' => trim($request->query('q'))]);
        }
        $input = $request->validate(['q' => 'required|string|min:2|max:100', 'page' => 'sometimes|integer|min:1|max:1000']);

        return response()->json($catalog->projects($request->attributes->get('area3.account'), $input['q'], (int) ($input['page'] ?? 1)));
    }

    public function orders(Request $request, string $project, PaymentProjectCatalog $catalog)
    {
        $input = $request->validate(['page' => 'sometimes|integer|min:1|max:1000']);

        return response()->json($catalog->orders($request->attributes->get('area3.account'), $project, (int) ($input['page'] ?? 1)));
    }
}
