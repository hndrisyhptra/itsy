<?php

namespace Tests\Feature;

use App\Http\Controllers\PrototypeController;
use App\Models\PrototypeWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class InboxRevisionTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    public function test_revision_probe_reads_one_counter_without_loading_creating_or_writing_workflow_data(): void
    {
        $this->seedWorkflow('B');
        $this->asRole('PM Konstruksi');
        $w = PrototypeWorkspace::first();
        $before = $w->getAttributes();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = app(PrototypeController::class)->revision();
        $service = DB::getQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/prototype/revision?workspace_id=untrusted')->assertOk()->assertExactJson(['revision' => $w->revision])->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $http = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $service);
        $this->assertLessThanOrEqual(6, count($http));
        foreach ([...$service, ...$http] as $query) {
            $this->assertMatchesRegularExpression('/^select /i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/workflow_metadata|document_data|designator_package_prices|finance_kasbons|payment_terms|project_plans/', $query['query']);
        }
        $this->assertSame($before, $w->fresh()->getAttributes());
        $this->assertSame(['revision' => $w->revision], $response->getData(true));
        fwrite(STDERR, "\nInbox revision: ".count($service).' service / '.count($http)." authenticated HTTP queries.\n");
    }

    public function test_probe_never_creates_workspace_and_requires_active_authenticated_account(): void
    {
        $this->getJson('/api/prototype/revision')->assertUnauthorized();
        $user = $this->asRole('Admin Konstruksi');
        $this->getJson('/api/prototype/revision')->assertStatus(503);
        $this->assertDatabaseCount('prototype_workspaces', 0);
        DB::table('users')->where('id_user', $user->getKey())->update(['active' => false]);
        $this->actingAs($user->fresh());
        $this->getJson('/api/prototype/revision')->assertUnauthorized();
    }
}
