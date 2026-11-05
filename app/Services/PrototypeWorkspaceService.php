<?php

namespace App\Services;

use App\Models\PrototypeWorkspace;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrototypeWorkspaceService
{
    public const SCENARIOS = ['overview', 'A', 'B', 'C', 'D', 'E'];

    public function scenario(string $name): array
    {
        if (! in_array($name, self::SCENARIOS, true)) {
            throw ValidationException::withMessages(['scenario' => 'Skenario tidak dikenal.']);
        }

        $state = PrototypeDomain::upgradeState(json_decode(file_get_contents(database_path('demo/'.$name.'.json')), true, 512, JSON_THROW_ON_ERROR));
        // These are explicitly synthetic assignee choices for demo checkpoints only.
        foreach ($state['projects'] as &$project) {
            $project['pmUserId'] = 'user-1';
            $project['managerUserId'] = 'user-2';
            if ($name === 'A' && $project['id'] === 'pp-1') {
                $project['status'] = 'Draft';
                $project['unit'] = 'Konstruksi';
            }
        }
        unset($project);
        foreach ($state['terms'] as &$term) {
            if (in_array($term['status'], ['Draft', 'Diajukan', 'Dikembalikan'], true) && count($term['approvals']) < 2) {
                $term['workflowVersion'] = 2;
                $term['pmUserId'] = 'user-1';
                $term['managerUserId'] = 'user-2';
            }
        }
        unset($term);

        return $state;
    }

    public function workspace(Request $request): PrototypeWorkspace
    {
        app(AuthenticatedWorkspace::class)->account();
        $key = hash('sha256', 'area3-authenticated-workflow-v1');

        $existing = PrototypeWorkspace::where('owner_key', $key)->first();
        if ($existing) {
            return $existing;
        }
        $state = array_fill_keys(['projects', 'deals', 'spks', 'terms', 'bapps', 'events', 'khs', 'users', 'branches'], []);
        $state += ['schema' => 3, 'role' => 'Admin Konstruksi', 'profile' => ['name' => '', 'email' => ''],
            'settings' => ['ppNumberFormat' => PrototypeDomain::DEFAULT_PP_NUMBER_FORMAT, 'nextPpSequence' => 1]];
        $state['generation'] = 'accounts-'.Str::uuid();
        $state['scenario'] = 'Ruang kerja akun';
        $state['lastSaved'] = now()->toISOString();

        return DB::transaction(function () use ($key, $state) {
            $workspace = PrototypeWorkspace::firstOrCreate(['owner_key' => $key], ['state' => app(AuthenticatedWorkspace::class)->bind($state), 'revision' => 0]);
            app(RelationalWorkflowStore::class)->activate($workspace);

            return $workspace->fresh();
        });
    }

    public function payload(PrototypeWorkspace $workspace): array
    {
        return ['state' => app(AuthenticatedWorkspace::class)->visible(app(RelationalWorkflowStore::class)->read($workspace)), 'revision' => $workspace->revision, 'csrf' => csrf_token()];
    }

    public function command(PrototypeWorkspace $workspace, array $input): array
    {
        return DB::transaction(function () use ($workspace, $input) {
            $row = PrototypeWorkspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $access = app(AuthenticatedWorkspace::class);
            $before = $access->bind(app(RelationalWorkflowStore::class)->read($row));
            $access->authorize($before, $input['command'], $input['args']);
            $replay = DB::table('prototype_audits')->where('workspace_id', $row->id)->where('request_id', $input['request_id'])->first();
            if ($replay) {
                abort_unless($replay->actor_user_id === auth()->id() && $replay->command === $input['command'], 409, 'ID permintaan sudah digunakan.');

                return $this->payload($row) + ['replayed' => true];
            }
            if ($row->revision !== $input['revision']) {
                throw new HttpResponseException(response()->json($this->payload($row) + ['message' => 'Data berubah di tab lain. Muat ulang data terbaru; perubahan tidak ditimpa.'], 409));
            }
            if ($input['command'] === 'saveProject') {
                // Never trust client provenance, comparison prices or an arbitrary file path.
                unset($input['args'][0]['_boqImport']);
                if (! empty($input['args'][0]['boqUpload']['id'])) {
                    $project = collect($before['projects'])->firstWhere('id', $input['args'][0]['id'] ?? '');
                    $input['args'][0]['_boqImport'] = app(BoqImportStorage::class)->read($row, $input['args'][0]['boqUpload']['id'], $project);
                }
            }
            $state = app(PrototypeDomain::class)->execute($before, $input['command'], $input['args'], $input['result_id'] ?? null)['state'];
            $balanceQuery = fn () => DB::table('volume_balances')->join('boq_components as c', 'c.id_boq_component', '=', 'volume_balances.boq_component_id')
                ->join('boq_lines as b', 'b.id_boq', '=', 'c.boq_line_id')->join('project_plans as p', 'p.id_pp', '=', 'b.root_pp_id')
                ->where('p.workspace_id', $row->id)->select('volume_balances.*');
            $balances = $balanceQuery()->get()->keyBy('boq_component_id');
            $row->revision++;
            app(RelationalWorkflowStore::class)->write($row, $state);
            $row->save();
            DB::table('prototype_audits')->insert([
                'workspace_id' => $row->id, 'request_id' => $input['request_id'], 'command' => $input['command'],
                'actor_role' => $before['role'], 'actor_user_id' => auth()->id(), 'revision' => $row->revision,
                'before_hash' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
                'after_hash' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), 'created_at' => now(),
            ]);
            $receipt = (string) Str::uuid();
            DB::table('command_receipts')->insert([
                'id_command_receipt' => $receipt, 'request_uuid' => $input['request_id'],
                'actor_user_id' => auth()->id(), 'command_name' => $input['command'],
                'aggregate_type' => 'WORKSPACE', 'aggregate_id' => $row->id,
                'expected_revision' => $input['revision'], 'resulting_revision' => $row->revision,
                'before_sha256' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
                'after_sha256' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), 'created_at' => now(),
            ]);
            foreach ($balanceQuery()->get() as $balance) {
                $old = $balances->get($balance->boq_component_id);
                // Opening stock/draft BOQ quantity changes are recorded by the receipt,
                // not presented as transfers between the four ledger buckets.
                if (! $old || bccomp((string) $old->original_quantity, (string) $balance->original_quantity, 3) !== 0) {
                    continue;
                }
                $deltas = [];
                foreach (['available', 'reserved', 'committed', 'accepted'] as $field) {
                    $deltas[$field.'_delta'] = bcsub((string) $balance->$field, (string) ($old->$field ?? 0), 3);
                }
                if (array_filter($deltas, fn ($v) => bccomp($v, '0', 3) !== 0)) {
                    DB::table('volume_movements')->insert($deltas + [
                        'id_volume_movement' => (string) Str::uuid(), 'receipt_id' => $receipt,
                        'boq_component_id' => $balance->boq_component_id, 'movement_type' => 'WORKFLOW',
                        'source_type' => $input['command'], 'source_id' => $row->id, 'created_at' => now(),
                    ]);
                }
            }

            return $this->payload($row);
        }, 3);
    }
}
