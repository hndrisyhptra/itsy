<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\PrototypeReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrototypeApplicationTest extends TestCase
{
    use RefreshDatabase, \Tests\Concerns\AuthenticatesArea3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedWorkflow();
    }

    public static function pages(): array
    {
        return array_map(fn ($path) => [$path], ['/dashboard', '/project-plan', '/project-plan/new', '/project-plan/pp-1', '/project-plan/pp-1/timeline', '/project-plan/pp-5/edit', '/boq', '/boq/pp-1/import', '/ba-kh', '/ba-kh/new', '/spk', '/spk/new', '/termin', '/termin/new', '/inbox', '/ba-pp', '/master-khs', '/laporan', '/pengaturan']);
    }

    #[DataProvider('pages')]
    public function test_routes_render_the_shared_laravel_shell(string $path): void
    {
        $this->get($path)->assertOk()->assertSee('window.__AREA3__', false)->assertSee('csrf-token', false)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->assertDatabaseCount('prototype_workspaces', 1);
    }

    private function command(string $name, array $args, int $revision, ?string $requestId = null)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $name, 'args' => $args, 'revision' => $revision, 'request_id' => $requestId ?? (string) Str::uuid()]);
    }

    public function test_workspace_is_shared_between_accounts_and_legacy_session_data_is_preserved(): void
    {
        $legacy = PrototypeWorkspace::create(['owner_key' => hash('sha256', 'visitor-a'), 'state' => ['historical' => true], 'revision' => 9]);
        $this->command('setNumberFormat', ['QA/{SEQ}'], 0)->assertOk();
        $this->asRole('PM Konstruksi');
        $this->withSession(['area3_owner' => 'visitor-a']);
        $this->getJson('/api/prototype/state')->assertOk()->assertJsonPath('state.role', 'PM Konstruksi')->assertJsonPath('state.settings.ppNumberFormat', 'QA/{SEQ}')->assertJsonPath('revision', 1);
        $this->assertSame(['historical' => true], $legacy->fresh()->state);
        $this->assertDatabaseCount('prototype_workspaces', 2);
    }

    public function test_stale_revision_is_rejected_without_overwriting_data(): void
    {
        $this->command('setNumberFormat', ['NEW/{SEQ}'], 0)->assertOk();
        $this->command('setNumberFormat', ['OLD/{SEQ}'], 0)->assertConflict()->assertJsonPath('state.settings.ppNumberFormat', 'NEW/{SEQ}');
        $this->assertDatabaseCount('prototype_audits', 1);
    }

    public function test_retry_with_the_same_request_id_is_idempotent_even_after_revision_changes(): void
    {
        $id = (string) Str::uuid();
        $this->command('setNumberFormat', ['QA/{SEQ}'], 0, $id)->assertOk();
        $this->command('setNumberFormat', ['QA/{SEQ}'], 0, $id)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('revision', 1);
        $this->assertDatabaseCount('prototype_audits', 1);
        $this->assertDatabaseHas('prototype_audits', ['actor_user_id' => $this->accounts['Superadmin']->getKey(), 'actor_role' => 'Superadmin']);
    }

    public function test_role_forgery_and_demo_reset_are_rejected(): void
    {
        $this->command('role', ['Finance'], 0)->assertForbidden();
        $this->command('reset', ['A'], 0)->assertForbidden();
        $this->asRole('Finance');
        $this->command('finalizeBoq', ['pp-1'], 0)->assertForbidden();
        $this->getJson('/api/prototype/state')->assertJsonPath('revision', 0)->assertJsonPath('state.role', 'Finance');
        $this->assertDatabaseCount('prototype_audits', 0);
    }

    public function test_csrf_is_required_on_real_mutation_requests(): void
    {
        $this->app->instance('env', 'local');
        $this->command('saveProfile', ['CSRF QA', 'csrf@example.com'], 0)->assertStatus(419);
        $this->assertDatabaseCount('prototype_audits', 0);
    }

    public function test_malformed_commands_fail_validation_without_writing(): void
    {
        $this->command('unknown', [], 0)->assertUnprocessable();
        $this->command('saveProject', [['id' => 'new-pp', 'schedule' => 'invalid']], 0)->assertUnprocessable();
        $this->assertDatabaseCount('prototype_audits', 0);
    }

    public function test_native_csv_is_generated_from_saved_workspace_and_validated_filters(): void
    {
        $response = $this->get('/api/prototype/exports/csv?report=Outstanding%20Payment&branch=SURABAYA');
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->assertDownload('area3-outstanding-payment.csv');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('PP/026/AREA3', $csv);
        $this->assertStringNotContainsString('PP/018/AREA3', $csv);
        $this->assertStringContainsString('45825200,10000000,35825200', $csv);
        $this->getJson('/api/prototype/exports/csv?report=Unknown')->assertUnprocessable();
        $this->getJson('/api/prototype/exports/csv?report=Monitoring%20Project%20Plan&from=2026-11-10&to=2026-11-01')->assertUnprocessable();
        $this->get('/api/prototype/exports/csv?report=Monitoring%20Project%20Plan&to=2026-11-03')->assertOk();
    }

    public function test_all_nine_native_report_types_can_be_downloaded(): void
    {
        foreach (PrototypeReportService::REPORTS as $report) {
            $this->get('/api/prototype/exports/csv?'.http_build_query(['report' => $report]))->assertOk();
        }
    }

    public function test_backup_exports_only_current_visitors_demo_data(): void
    {
        $this->withSession(['area3_owner' => 'backup-owner']);

        $response = $this->get('/api/prototype/backup')->assertDownload('its-area3-cadangan.json');
        $data = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Superadmin Test', $data['profile']['name']);
        $this->assertArrayNotHasKey('owner_key', $data);
    }

    public function test_failed_business_validation_is_atomic_and_not_audited_as_success(): void
    {
        $this->seedWorkflow('A');
        $before = PrototypeWorkspace::first()->state;
        $this->command('finalizeBoq', ['pp-1'], 0)->assertUnprocessable();
        $this->assertEquals($before, PrototypeWorkspace::first()->state);
        $this->assertSame(0, PrototypeWorkspace::first()->revision);
        $this->assertSame(0, DB::table('prototype_audits')->count());
    }
}
