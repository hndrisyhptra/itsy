<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\PrototypeWorkspaceService;
use App\Services\UploadQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class ProjectDraftConcurrencyTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->asRole('Admin Konstruksi');
        $this->getJson('/api/prototype/state')->assertOk();
    }

    private function project(string $id = 'pp-new-concurrent'): array
    {
        $p = app(PrototypeWorkspaceService::class)->scenario('B')['projects'][0];
        $p['id'] = $p['rootId'] = $id;
        $p['name'] = 'New concurrent draft';
        $p['projectCode'] = 'PID-'.$id;
        $p['unit'] = 'Konstruksi';
        $p['branch'] = 'DENPASAR';
        $p['date'] = '2026-11-01';
        $p['workStartDate'] = '2026-11-02';
        $p['boq'] = [];
        unset($p['boqUpload'], $p['pmUserId'], $p['managerUserId'], $p['approverUserIds']);

        return $p;
    }

    private function send(string $command, array $args, int|string $revision = 0, ?string $uuid = null)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => $revision, 'request_id' => $uuid ?? (string) Str::uuid()]);
    }

    private function unrelatedChange(): void
    {
        $this->asRole('Superadmin');
        $this->send('setNumberFormat', ['CURRENT/{SEQ}'], PrototypeWorkspace::first()->revision)->assertOk();
        $this->asRole('Admin Konstruksi');
    }

    public static function createCommands(): array
    {
        return [['saveProjectBundle', 'Konstruksi'], ['saveProject', 'Konstruksi'], ['saveProjectBundle', 'Operation'], ['saveProject', 'Operation']];
    }

    #[DataProvider('createCommands')]
    public function test_new_draft_after_real_queued_boq_uses_current_locked_state_without_overwriting_other_changes(string $command, string $unit): void
    {
        if ($unit === 'Operation') {
            $this->asRole('Admin Operation');
        }
        $p = $this->project();
        $p['unit'] = $unit;
        $task = $this->post('/api/uploads/boq', ['file' => UploadedFile::fake()->createWithContent('concurrent.xlsx', file_get_contents(base_path('tests/Fixtures/boq/osp-fo-paket10.xlsx'))),
            'document_id' => $p['id'], 'request_id' => (string) Str::uuid()], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
        app(UploadQueue::class)->process($task);
        $result = $this->getJson('/api/uploads/'.$task.'?result=1')->assertOk()->assertJsonPath('task.status', 'completed')->json('result');
        $this->assertSame(0, PrototypeWorkspace::first()->revision, 'BOQ processing alone does not change the workspace revision.');
        $p['boq'] = $result['rows'];
        $p['boqUpload'] = $result['source'];
        $p['boq'][0]['m'] = 1;
        $this->unrelatedChange();
        if ($unit === 'Operation') {
            $this->asRole('Admin Operation');
        }
        $archive = PrototypeWorkspace::first()->getRawOriginal('state');
        $uuid = (string) Str::uuid();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->send($command, [$p, false], 0, $uuid)->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('state.settings.ppNumberFormat', 'CURRENT/{SEQ}');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(80, $queries);
        $saved = collect($response->json('state.projects'))->firstWhere('id', $p['id']);
        $this->assertSame('Draft', $saved['status']);
        $this->assertSame($unit, $saved['unit']);
        $this->assertCount(26, $saved['boq']);
        $this->assertSame($result['rows'][0]['m'], $saved['boq'][0]['m']);
        $this->assertSame($task, $saved['boqUpload']['id']);
        $this->assertStringStartsWith('CURRENT/', $saved['no']);
        $this->assertSame($archive, PrototypeWorkspace::first()->getRawOriginal('state'));
        $this->assertDatabaseCount('project_plans', 1);
        $this->assertDatabaseCount('budget_pid_allocations', 1);
        $this->assertDatabaseHas('budget_pid_allocations', ['status' => 'draft', 'material' => 0, 'service' => 0]);
        $this->assertDatabaseHas('command_receipts', ['request_uuid' => $uuid, 'expected_revision' => 0, 'resulting_revision' => 2]);
        $this->send($command, [$p, false], 0, $uuid)->assertOk()->assertJsonPath('replayed', true);
        $p['name'] = 'Changed replay';
        $this->send($command, [$p, false], 0, $uuid)->assertConflict();
        $this->assertDatabaseCount('prototype_audits', 2);
        fwrite(STDERR, "\nConcurrent new {$unit} {$command} /26 BOQ: {$queries} HTTP queries.\n");
    }

    public function test_new_creates_with_same_old_revision_keep_both_records_and_allocate_distinct_current_numbers(): void
    {
        $first = $this->send('saveProjectBundle', [$this->project('pp-concurrent-one'), false])->assertOk()->json('state.projects.0');
        $response = $this->send('saveProjectBundle', [$this->project('pp-concurrent-two'), false])->assertOk()->assertJsonCount(2, 'state.projects')->assertJsonPath('revision', 2);
        $projects = collect($response->json('state.projects'))->keyBy('id');
        $this->assertSame($first, $projects['pp-concurrent-one']);
        $this->assertNotSame($first['no'], $projects['pp-concurrent-two']['no']);
        $this->assertDatabaseCount('budget_pid_allocations', 2);
    }

    public function test_existing_draft_collision_still_rejects_stale_edits_without_any_writes(): void
    {
        $saved = $this->send('saveProjectBundle', [$this->project(), false])->assertOk()->json('state.projects.0');
        $this->unrelatedChange();
        $saved['name'] = 'Must not overwrite';
        $this->send('saveProjectBundle', [$saved, false], 1)->assertConflict()->assertJsonPath('revision', 2);
        $this->assertDatabaseCount('prototype_audits', 2);
        $this->assertDatabaseCount('budget_pid_allocation_changes', 1);
        $document = json_decode(DB::table('project_plans')->where('source_id', $saved['id'])->value('document_data'), true);
        $this->assertSame('New concurrent draft', $document['name']);
    }

    public function test_future_revision_submit_and_continuation_shapes_cannot_use_the_new_draft_exception(): void
    {
        $this->unrelatedChange();
        $p = $this->project();
        $this->send('saveProjectBundle', [$p, false], 2)->assertConflict();
        $this->send('saveProjectBundle', [$p, true])->assertConflict();
        $this->send('saveProject', [$p, true])->assertConflict();
        $p['parentId'] = 'some-parent';
        $this->send('saveProjectBundle', [$p, false])->assertConflict();
        $p['parentId'] = null;
        $p['rootId'] = 'some-root';
        $this->send('saveProjectBundle', [$p, false])->assertConflict();
        $this->assertDatabaseCount('project_plans', 0);
        $this->assertDatabaseCount('prototype_audits', 1);
    }

    public function test_valid_integer_revision_strings_compare_as_numbers_but_real_mismatch_remains_a_conflict(): void
    {
        $this->asRole('Superadmin');
        $this->send('setNumberFormat', ['CURRENT/{SEQ}'], '0')->assertOk()->assertJsonPath('revision', 1);
        $this->send('setNumberFormat', ['STALE/{SEQ}'], '0')->assertConflict();
        $this->assertDatabaseCount('prototype_audits', 1);
    }

    public function test_new_draft_exception_keeps_current_role_unit_and_branch_authorization(): void
    {
        $this->unrelatedChange();
        $p = $this->project();
        $this->asRole('Finance');
        $this->send('saveProjectBundle', [$p, false])->assertForbidden();
        $this->asRole('Admin Operation');
        $this->send('saveProjectBundle', [$p, false])->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $p['branch'] = 'UNKNOWN BRANCH';
        $this->send('saveProjectBundle', [$p, false])->assertForbidden();
        $this->assertDatabaseCount('project_plans', 0);
        $this->assertDatabaseCount('prototype_audits', 1);
    }

    public function test_invalid_new_header_rolls_back_without_consuming_a_number_or_budget(): void
    {
        $this->unrelatedChange();
        $p = $this->project();
        $p['name'] = '';
        $this->send('saveProjectBundle', [$p, false])->assertUnprocessable();
        $this->assertSame(1, PrototypeWorkspace::first()->revision);
        $this->assertDatabaseCount('project_plans', 0);
        $this->assertDatabaseCount('budget_pid_allocations', 0);
        $this->assertDatabaseCount('command_receipts', 1);
        $p['name'] = 'Valid header';
        $this->send('saveProjectBundle', [$p, false])->assertOk()->assertJsonPath('revision', 2);
        $this->assertDatabaseCount('project_plans', 1);
    }
}
