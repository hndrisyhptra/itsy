<?php

namespace App\Http\Controllers;

use App\Services\FinancePaymentCatalog;
use Illuminate\Http\Request;

final class FinancePaymentCatalogController extends Controller
{
    public function __invoke(Request $request, FinancePaymentCatalog $catalog)
    {
        $input = $request->validate(['q' => 'sometimes|nullable|string|max:100', 'branch' => 'sometimes|nullable|string|max:150', 'status' => 'sometimes|in:all,paid,unpaid', 'page' => 'sometimes|integer|min:1|max:10000']);
        $input['q'] = trim($input['q'] ?? '');

        return response()->json($catalog->listing($request->attributes->get('area3.account'), $input))->header('Cache-Control', 'private, no-store');
    }
}
