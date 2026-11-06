<?php

namespace Tests\Feature;

use App\Jobs\ProcessUpload;
use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use App\Services\UploadQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;
use ZipArchive;

class UploadQueueTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->asRole('Admin Konstruksi');
    }

    private function boq(?string $uuid = null, ?string $content = null)
    {
        return $this->post('/api/uploads/boq', ['file' => UploadedFile::fake()->createWithContent('boq.xlsx', $content ?? file_get_contents(base_path('tests/Fixtures/boq/osp-fo-paket10.xlsx'))),
            'document_id' => 'pp-queue-test', 'request_id' => $uuid ?? (string) Str::uuid()], ['Accept' => 'application/json']);
    }

    private function evidence(string $term, ?string $uuid = null, ?int $revision = null)
    {
        return $this->post('/api/uploads/payments/'.$term.'/evidence', ['file' => UploadedFile::fake()->createWithContent('bukti.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
            'request_id' => $uuid ?? (string) Str::uuid(), 'revision' => $revision ?? PrototypeWorkspace::firstOrFail()->revision], ['Accept' => 'application/json']);
    }

    private function api(string $command, array $args)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args, 'revision' => PrototypeWorkspace::first()->revision, 'request_id' => (string) Str::uuid()]);
    }

    private function term(): string
    {
        $s = $this->seedWorkflow('D');
        $this->asRole('Admin Konstruksi');
        $terms = $this->api('createTerm', [$s['spks'][0]['id']])->assertOk()->json('state.terms');

        return collect($terms)->firstWhere('status', 'Draft')['id'];
    }

    public function test_boq_returns_202_before_parsing_worker_completes_real_private_result_and_replay_is_single_job(): void
    {
        $uuid = (string) Str::uuid();
        $id = $this->boq($uuid)->assertStatus(202)->assertJsonPath('task.status', 'queued')->json('task.id');
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('project_plans', 0);
        $this->assertFalse(Storage::disk('local')->exists('prototype-boq/'.PrototypeWorkspace::first()->id.'/'.$id.'/manifest.json'));
        $this->boq($uuid)->assertStatus(202)->assertJsonPath('task.id', $id);
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['connection' => 'uploads', '--queue' => 'area3-uploads', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $result = $this->getJson('/api/uploads/'.$id.'?result=1')->assertOk()->assertJsonPath('task.status', 'completed')->assertJsonCount(26, 'result.rows')->assertJsonPath('result.source.sourceTotal', 140056789);
        $this->assertArrayNotHasKey('storage_key', $result->json('task'));
        $this->assertSame($id, $result->json('result.source.id'));
        $this->assertDatabaseCount('jobs', 0);
        $this->getJson('/api/uploads?kind=boq&document_id=pp-queue-test')->assertOk()->assertJsonCount(1, 'tasks');
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_bad_workbook_fails_in_worker_with_safe_error_and_staging_cleanup(): void
    {
        $id = $this->boq(null, 'invalid workbook')->assertStatus(202)->json('task.id');
        app(UploadQueue::class)->process($id);
        $this->getJson('/api/uploads/'.$id.'?result=1')->assertOk()->assertJsonPath('task.status', 'failed')->assertJsonMissingPath('result');
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('prototype_audits', 0);
    }

    public function test_queued_boq_accepts_blank_prices_and_saved_numeric_formula_results(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'area3-boq-queue-');
        try {
            copy(base_path('tests/Fixtures/boq/osp-fo-paket10.xlsx'), $path);
            $zip = new ZipArchive;
            $zip->open($path);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $xml = preg_replace('/<c\b[^>]*\br="F75"[^>]*>.*?<\/c>/s', '<c r="F75"/>', $xml);
            $xml = preg_replace('/<c\b[^>]*\br="G75"[^>]*>.*?<\/c>/s', '<c r="G75"><f>5+6</f><v>11</v></c>', $xml);
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->close();
            $id = $this->boq(null, file_get_contents($path))->assertStatus(202)->json('task.id');
            app(UploadQueue::class)->process($id);
            $result = $this->getJson('/api/uploads/'.$id.'?result=1')->assertOk()
                ->assertJsonPath('task.status', 'completed')->assertJsonCount(26, 'result.rows')->json('result');
            $this->assertSame(11, $result['rows'][0]['qty']);
            $service = collect($result['rows'])->first(fn ($r) => $r['sourceRow'] === 75 && $r['s'] !== null);
            $this->assertSame(0, $service['excelS']);
            $this->assertFalse($service['reviewed']);
            $this->assertDatabaseCount('project_plans', 0);
        } finally {
            unlink($path);
        }
    }

    public function test_evidence_queue_preserves_intervening_edits_appends_once_and_previews_by_branch(): void
    {
        $term = $this->term();
        $id = $this->evidence($term)->assertStatus(202)->json('task.id');
        $this->assertDatabaseCount('term_work_evidence', 0);
        // Other valid commands advance the shared revision while the worker is pending.
        $this->asRole();
        $this->api('setNumberFormat', ['{SEQ}/QUEUE/{BRANCH}/{MM}-{YYYY}'])->assertOk();
        $this->asRole('Admin Konstruksi');
        app(UploadQueue::class)->process($id);
        app(UploadQueue::class)->process($id);
        $this->getJson('/api/uploads/'.$id)->assertOk()->assertJsonPath('task.status', 'completed');
        $this->assertDatabaseCount('term_work_evidence', 1);
        $file = DB::table('document_files')->where('original_name', 'bukti.pdf')->first();
        $this->assertSame('work-evidence/'.PrototypeWorkspace::first()->id.'/'.$term.'/'.$id.'.pdf', $file->storage_key);
        Storage::disk('local')->assertExists($file->storage_key);
        $this->asRole('PM Konstruksi');
        $this->get('/api/prototype/payments/'.$term.'/work-evidence/'.$id)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('/api/uploads/'.$id)->assertNotFound();
        $this->assertSame('{SEQ}/QUEUE/{BRANCH}/{MM}-{YYYY}', DB::table('numbering_rules')->value('pattern'));
    }

    public function test_worker_rechecks_revoked_account_and_changed_term_status_without_attaching_files(): void
    {
        $term = $this->term();
        $user = auth()->user();
        $id = $this->evidence($term)->assertStatus(202)->json('task.id');
        $user->active = false;
        $user->save();
        app(UploadQueue::class)->process($id);
        $this->assertDatabaseHas('upload_tasks', ['id_upload_task' => $id, 'status' => 'failed']);
        $this->assertDatabaseCount('term_work_evidence', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_pending_upload_blocks_submission_owner_and_branch_are_enforced_and_stale_upload_does_not_stage(): void
    {
        $term = $this->term();
        $revision = PrototypeWorkspace::first()->revision;
        $this->evidence($term, null, $revision - 1)->assertConflict();
        $id = $this->evidence($term)->assertStatus(202)->json('task.id');
        $s = $this->getJson('/api/prototype/state')->json('state');
        $t = collect($s['terms'])->firstWhere('id', $term);
        $this->api('saveTerm', [$t, true])->assertUnprocessable()->assertSee('antrean upload');
        $this->asRole('PM Konstruksi');
        $this->getJson('/api/uploads/'.$id)->assertNotFound();
        $this->evidence($term)->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $user = auth()->user();
        app(AccountAccess::class)->assign($user, 'ADMIN_KONSTRUKSI', [DB::table('branches')->where('name', 'KUPANG')->value('id_branch')]);
        app(UploadQueue::class)->process($id);
        $this->assertDatabaseHas('upload_tasks', ['id_upload_task' => $id, 'status' => 'failed']);
        $this->assertDatabaseCount('term_work_evidence', 0);
    }

    public function test_queue_uuid_reuse_with_other_bytes_is_rejected_and_guests_cannot_upload(): void
    {
        $uuid = (string) Str::uuid();
        $this->boq($uuid)->assertStatus(202);
        $this->boq($uuid, 'different')->assertConflict();
        $this->assertDatabaseCount('jobs', 1);
        $this->post('/logout');
        $this->getJson('/api/uploads?kind=boq&document_id=pp-queue-test')->assertUnauthorized();
        $this->postJson('/api/uploads/boq', [])->assertUnauthorized();
    }

    public function test_terminal_failure_is_reported_cleanly_and_never_leaves_unlinked_private_evidence(): void
    {
        $term = $this->term();
        $id = $this->evidence($term)->assertStatus(202)->json('task.id');
        $record = DB::table('payment_terms')->where('source_id', $term)->first();
        $document = json_decode($record->document_data, true);
        $document['status'] = 'Diajukan';
        DB::table('payment_terms')->where('source_id', $term)->update(['document_data' => json_encode($document)]);
        app(UploadQueue::class)->process($id);
        $this->assertDatabaseHas('upload_tasks', ['id_upload_task' => $id, 'status' => 'failed']);
        $this->assertDatabaseCount('term_work_evidence', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        (new ProcessUpload($id))->failed(new \RuntimeException('server detail'));
        $this->getJson('/api/uploads/'.$id)->assertOk()->assertDontSee('server detail');
    }

    public function test_resume_is_owner_scoped_operational_csrf_protected_and_cannot_spawn_a_worker_in_tests(): void
    {
        $id = $this->boq()->assertStatus(202)->json('task.id');
        config(['uploads.autostart' => false]);
        $this->postJson('/api/uploads/'.$id.'/resume')->assertStatus(503);
        config(['uploads.autostart' => true]);
        $this->postJson('/api/uploads/'.$id.'/resume')->assertStatus(202);
        $this->assertDatabaseCount('jobs', 1);
        $this->asRole('PM Konstruksi');
        $this->postJson('/api/uploads/'.$id.'/resume')->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $this->app->instance('env', 'local');
        $this->postJson('/api/uploads/'.$id.'/resume')->assertStatus(419);
        $this->postJson('/api/uploads/boq')->assertStatus(419);
    }

    public function test_queued_batch_can_append_without_a_stale_page_revision_as_other_files_complete(): void
    {
        $term = $this->term();
        for ($i = 0; $i < 2; $i++) {
            $response = $this->post('/api/uploads/payments/'.$term.'/evidence', [
                'file' => UploadedFile::fake()->createWithContent('batch-'.$i.'.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"),
                'request_id' => (string) Str::uuid(),
            ], ['Accept' => 'application/json'])->assertStatus(202);
            app(UploadQueue::class)->process($response->json('task.id'));
        }
        $this->assertDatabaseCount('term_work_evidence', 2);
        $this->assertSame(2, DB::table('upload_tasks')->where('status', 'completed')->count());
    }
}
