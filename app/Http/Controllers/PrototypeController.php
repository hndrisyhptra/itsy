<?php

namespace App\Http\Controllers;

use App\Services\PrototypeWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PrototypeController extends Controller
{
    public function show(Request $request, PrototypeWorkspaceService $service)
    {
        $workspace = $service->workspace($request);

        return view('prototype', ['boot' => $service->payload($workspace) + [
            'basePath' => $request->getBaseUrl(), 'scenarios' => [],
            'account' => $request->attributes->get('area3.account'),
            'accountUrls' => ['logout' => route('logout'), 'manage' => route('accounts.index'), 'login' => route('login')],
            'urls' => ['state' => route('prototype.state'), 'commands' => route('prototype.commands'), 'csv' => route('prototype.csv'), 'backup' => route('prototype.backup')],
        ]]);
    }

    public function state(Request $request, PrototypeWorkspaceService $service)
    {
        return response()->json($service->payload($service->workspace($request)));
    }

    public function command(Request $request, PrototypeWorkspaceService $service)
    {
        abort_if(strlen($request->getContent()) > 2 * 1024 * 1024, 413, 'Data transaksi terlalu besar.');
        $input = $request->validate([
            'request_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:0'],
            'command' => ['required', 'string', 'in:removeTermWorkEvidence,saveProject,decideProject,assignProjectApprovers,assignPaymentApprovers,setNumberFormat,selectActor,verifyTermScan,importDemo,reviewBoq,finalizeBoq,saveDeal,saveSpk,createTerm,saveTerm,decide,recordApm,recordPayment,attestBapp,releaseVolume,createContinuation,updateKhs,saveUser,saveProfile,role,reset'],
            'args' => ['present', 'array', 'max:5'], 'result_id' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9-]{3,100}$/D'],
        ]);
        $types = [
            'decideProject' => ['string', 'string', 'string'], 'assignProjectApprovers' => ['string', 'string', 'string'],
            'setNumberFormat' => ['string'], 'selectActor' => ['string'], 'verifyTermScan' => ['string', 'string', 'string'],
            'assignPaymentApprovers' => ['string', 'array'],
            'removeTermWorkEvidence' => ['string', 'string'], 'saveProject' => ['array', 'boolean'], 'importDemo' => ['string'], 'reviewBoq' => ['string', 'string', 'string'], 'finalizeBoq' => ['string'],
            'saveDeal' => ['array', 'string'], 'saveSpk' => ['array', 'string'], 'createTerm' => ['string'], 'saveTerm' => ['array', 'boolean'],
            'decide' => ['string', 'string', 'string'], 'recordApm' => ['string', 'string', 'boolean'], 'recordPayment' => ['string', 'numeric', 'string'],
            'attestBapp' => ['string', 'string', 'string'], 'releaseVolume' => ['string', 'array'], 'createContinuation' => ['string', 'string', 'array', 'string'],
            'updateKhs' => ['array', 'boolean'], 'saveUser' => ['array', 'boolean'], 'saveProfile' => ['string', 'string'], 'role' => ['string'], 'reset' => ['string'],
        ];
        $rules = [];
        foreach ($types[$input['command']] as $i => $type) {
            $rules['args.'.$i] = ['sometimes', $type];
        }
        $nested = match ($input['command']) {
            'saveProject' => ['args.0.id' => 'required|string|max:100', 'args.0.schedule' => 'required|array|size:5', 'args.0.schedule.*' => 'array', 'args.0.team' => 'present|array|max:100', 'args.0.team.*' => 'array', 'args.0.costs' => 'required|array', 'args.0.boq' => 'present|array|max:500', 'args.0.boq.*' => 'array', 'args.0.boqUpload' => 'sometimes|nullable|array', 'args.0.boqUpload.id' => 'required_with:args.0.boqUpload|uuid'],
            'saveDeal' => ['args.0.id' => 'required|string|max:100', 'args.0.rows' => 'required|array|max:500', 'args.0.rows.*' => 'array'],
            'saveTerm' => ['args.0.id' => 'required|string|max:100', 'args.0.actuals' => 'present|array|max:500', 'args.0.actuals.*' => 'array'],
            'releaseVolume' => ['args.1.*' => 'array'],
            'createContinuation' => ['args.2.*' => 'array'],
            'saveSpk', 'updateKhs', 'saveUser' => ['args.0.id' => 'required|string|max:100'],
            default => [],
        };
        $rules = array_merge($rules, $nested);
        Validator::make($input, $rules)->validate();

        return response()->json($service->command($service->workspace($request), $input));
    }

    public function backup(Request $request, PrototypeWorkspaceService $service)
    {
        $state = $service->payload($service->workspace($request))['state'];

        return response()->streamDownload(fn () => print (json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'its-area3-cadangan.json', ['Content-Type' => 'application/json; charset=UTF-8']);
    }
}
