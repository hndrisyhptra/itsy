<?php

namespace App\Http\Controllers;

use App\Services\BudgetPidMaster;
use App\Services\BudgetPidWorkbook;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BudgetPidController extends Controller
{
    public function index(Request $request, BudgetPidMaster $master)
    {
        $account = $request->attributes->get('area3.account');
        $master->authorize($account);
        $input = $request->validate(['unit' => ['required', Rule::in(['Konstruksi', 'Operation'])], 'q' => 'nullable|string|max:100',
            'page' => 'sometimes|integer|min:1|max:100000', 'history' => 'sometimes|boolean', 'import_id' => 'nullable|uuid']);

        return response()->json($master->listing($account, $input['unit'], trim($input['q'] ?? ''), (int) ($input['page'] ?? 1), (bool) ($input['history'] ?? false), $input['import_id'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function import(Request $request, BudgetPidMaster $master, BudgetPidWorkbook $reader)
    {
        $account = $request->attributes->get('area3.account');
        $master->authorize($account);
        $input = $request->validate(['unit' => ['required', Rule::in(['Konstruksi', 'Operation'])], 'stage' => ['required', Rule::in(['preview', 'commit'])],
            'file' => 'required|file|extensions:xlsx|max:20480', 'version' => 'required_if:stage,commit|integer|min:0',
            'preview_hash' => 'required_if:stage,commit|string|size:64', 'request_id' => 'required_if:stage,commit|uuid']);
        $master->authorize($account, $input['unit']);
        $file = $request->file('file');
        $records = $reader->read($file->getRealPath());

        return response()->json($master->import((int) $request->user()->getKey(), $records, $input, hash_file('sha256', $file->getRealPath()), $file->getClientOriginalName()))->header('Cache-Control', 'private, no-store');
    }
}
