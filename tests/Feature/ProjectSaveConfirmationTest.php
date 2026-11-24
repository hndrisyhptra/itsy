<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\PrototypeWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class ProjectSaveConfirmationTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->asRole('Admin Konstruksi');
        $this->getJson('/api/prototype/state')->assertOk();
    }

    private function save(?array $p = null): array
    {
        $p ??= app(PrototypeWorkspaceService::class)->scenario('B')['projects'][0];
        $p['id'] = $p['rootId'] = 'pp-confirmation';
        $p['unit'] = 'Konstruksi';
        $p['branch'] = 'DENPASAR';
        $p['boq'] = [];
        unset($p['boqUpload'], $p['pmUserId'], $p['managerUserId'], $p['approverUserIds']);
        $uuid = (string) Str::uuid();
        $response = $this->postJson('/api/prototype/commands', ['request_id' => $uuid, 'revision' => PrototypeWorkspace::first()->revision,
            'command' => 'saveProjectBundle', 'args' => [$p, false]])->assertOk();

        return [$uuid, collect($response->json('state.projects'))->firstWhere('id', $p['id'])];
    }

    private function confirmation(string $uuid, string $project = 'pp-confirmation')
    {
        return $this->getJson('/api/prototype/state?'.http_build_query(['request_id' => $uuid, 'project_id' => $project]));
    }

    public function test_read_confirms_exact_actor_request_and_returns_current_canonical_state_without_business_writes(): void
    {
        [$uuid, $p] = $this->save();
        $workspace = PrototypeWorkspace::first();
        $before = DB::table('project_plans')->get()->toJson();
        $counts = [DB::table('prototype_audits')->count(), DB::table('command_receipts')->count(), DB::table('budget_pid_allocation_changes')->count()];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/prototype/state')->assertOk()->assertJsonMissingPath('confirmation');
        $normal = count(DB::getQueryLog());
        DB::flushQueryLog();
        $response = $this->confirmation($uuid)->assertOk()->assertJsonPath('confirmation', ['requestId' => $uuid, 'projectId' => $p['id'], 'command' => 'saveProjectBundle', 'expectedRevision' => 0, 'resultingRevision' => 1])
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertJsonPath('state.projects.0.no', $p['no']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($normal + 1, count($queries));
        $receipts = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'from "command_receipts"')));
        $this->assertCount(1, $receipts);
        $this->assertStringContainsString('"request_uuid" = ?', $receipts[0]['query']);
        $this->assertStringNotContainsString('sha256', $receipts[0]['query']);
        $this->assertCount(5, $response->json('confirmation'));
        $this->assertSame($before, DB::table('project_plans')->get()->toJson());
        $this->assertSame($counts, [DB::table('prototype_audits')->count(), DB::table('command_receipts')->count(), DB::table('budget_pid_allocation_changes')->count()]);
        $this->assertSame($workspace->revision, $workspace->fresh()->revision);
        fwrite(STDERR, "\nPP confirmation: ".count($queries)." HTTP queries versus normal {$normal}; one indexed receipt SELECT.\n");
    }

    public function test_unknown_request_other_actor_and_non_pp_command_never_confirm(): void
    {
        [$uuid] = $this->save();
        $this->confirmation((string) Str::uuid())->assertOk()->assertJsonPath('confirmation', null);
        $this->confirmation($uuid, 'missing-pp')->assertOk()->assertJsonPath('confirmation', null);
        $this->asRole('Superadmin');
        $this->confirmation($uuid)->assertOk()->assertJsonPath('confirmation', null);
        $other = (string) Str::uuid();
        $this->postJson('/api/prototype/commands', ['request_id' => $other, 'revision' => 1, 'command' => 'setNumberFormat', 'args' => ['CONFIRM/{SEQ}']])->assertOk();
        $this->confirmation($other)->assertOk()->assertJsonPath('confirmation', null);
        $this->assertDatabaseCount('command_receipts', 2);
    }

    public function test_receipt_committed_after_workspace_lookup_refreshes_metadata_before_reading_state(): void
    {
        [$uuid] = $this->save();
        $stale = PrototypeWorkspace::runtimeQuery()->first();
        $stale->revision = 0;
        $this->partialMock(PrototypeWorkspaceService::class, function ($mock) use ($stale) {
            $mock->shouldReceive('workspace')->once()->andReturn($stale);
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->confirmation($uuid)->assertOk()->assertJsonPath('revision', 1)->assertJsonPath('confirmation.resultingRevision', 1);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $metadata = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'from "prototype_workspaces"')));
        $this->assertCount(1, $metadata);
        $this->assertStringNotContainsString('"state"', $metadata[0]['query']);
        $this->assertDatabaseCount('command_receipts', 1);
        $this->assertSame(1, PrototypeWorkspace::first()->revision);
    }

    public function test_foreign_workspace_and_non_workspace_receipts_are_not_recovered(): void
    {
        [$uuid] = $this->save();
        DB::table('command_receipts')->where('request_uuid', $uuid)->update(['aggregate_id' => (string) Str::uuid()]);
        $this->confirmation($uuid)->assertOk()->assertJsonPath('confirmation', null);
        DB::table('command_receipts')->where('request_uuid', $uuid)->update(['aggregate_id' => PrototypeWorkspace::first()->id, 'aggregate_type' => 'PAYMENT']);
        $this->confirmation($uuid)->assertOk()->assertJsonPath('confirmation', null);
    }

    public function test_current_branch_scope_and_validation_still_apply_to_confirmation_reads(): void
    {
        [$uuid] = $this->save();
        $user = $this->accounts['Admin Konstruksi'];
        $branch = DB::table('branches')->where('name', 'SURABAYA')->value('id_branch');
        app(AccountAccess::class)->assign($user, 'ADMIN_KONSTRUKSI', [$branch]);
        $this->actingAs($user);
        $this->confirmation($uuid)->assertOk()->assertJsonCount(0, 'state.projects')->assertJsonPath('confirmation', null);
        $this->getJson('/api/prototype/state?request_id=bad&project_id=pp-confirmation')->assertUnprocessable();
        $this->getJson('/api/prototype/state?request_id='.$uuid)->assertUnprocessable();
        $this->assertDatabaseCount('command_receipts', 1);
    }

    public function test_saved_edit_has_its_own_receipt_without_reissuing_the_creation(): void
    {
        [$first, $p] = $this->save();
        $p['name'] = 'Edited Draft';
        [$second] = $this->save($p);
        $this->confirmation($second)->assertOk()->assertJsonPath('confirmation.expectedRevision', 1)->assertJsonPath('confirmation.resultingRevision', 2)->assertJsonPath('state.projects.0.name', 'Edited Draft');
        $this->assertNotSame($first, $second);
        $this->assertDatabaseCount('project_plans', 1);
    }

    public function test_new_root_skips_workspace_wide_movement_scans_and_preserves_existing_physical_balances(): void
    {
        $this->seedWorkflow('B');
        $this->asRole('Admin Konstruksi');
        $before = DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson();
        $moves = DB::table('volume_movements')->count();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->save();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $q) {
            $this->assertDoesNotMatchRegularExpression('/^select "volume_balances"\.\*.*inner join "project_plans" as "p".*"p"\."workspace_id"/', $q['query']);
        }
        $this->assertSame($before, DB::table('volume_balances')->orderBy('boq_component_id')->get()->toJson());
        $this->assertSame($moves, DB::table('volume_movements')->count());
        $this->assertDatabaseCount('project_plans', 2);
    }
}
