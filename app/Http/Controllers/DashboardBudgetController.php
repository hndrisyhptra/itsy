<?php

namespace App\Http\Controllers;

use App\Services\DashboardBudget;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardBudgetController extends Controller
{
    public function __invoke(Request $request, DashboardBudget $budget)
    {
        $input = $request->validate(['unit' => ['required', Rule::in(['Konstruksi', 'Operation'])], 'branch' => 'sometimes|nullable|string|max:100', 'page' => 'sometimes|integer|min:1|max:100000', 'q' => 'sometimes|nullable|string|max:100', 'detail' => 'sometimes|boolean']);

        return response()->json($budget->read($request->attributes->get('area3.account'), $input))->header('Cache-Control', 'private, no-store');
    }
}
