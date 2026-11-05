<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AccountAccess;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class TermWorkEvidenceTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private string $termId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $state = $this->seedWorkflow('D');
        $this->asRole('Admin Konstruksi');
        $s = $this->api('createTerm', [$state['spks'][0]['id']])->assertOk()->json('state');
        $this->termId = collect($s['terms'])->firstWhere('spkId', $state['spks'][0]['id'])['id'];
    }

    private function api(string $command, array $args)
    {
        return $this->postJson('/api/prototype/commands', ['command' => $command, 'args' => $args,
            'revision' => $this->getJson('/api/prototype/state')->assertOk()->json('revision'), 'request_id' => (string) Str::uuid()]);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('eviden.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF");
    }

    private function upload(?UploadedFile $file = null, ?string $request = null, ?int $revision = null)
    {
        return $this->post('/api/prototype/payments/'.$this->termId.'/work-evidence', [
            'file' => $file ?? $this->pdf(), 'request_id' => $request ?? (string) Str::uuid(),
            'revision' => $revision ?? $this->getJson('/api/prototype/state')->assertOk()->json('revision'),
            'path' => '../../forged.pdf', 'uploadedBy' => 'db-user-999999',
        ], ['Accept' => 'application/json']);
    }

    private function term(?array $state = null): array
    {
        return collect($state ?? $this->getJson('/api/prototype/state')->assertOk()->json('state.terms'))->firstWhere('id', $this->termId);
    }

    private function file(array $term): array
    {
        return array_values(array_filter($term['workEvidence'] ?? [], fn ($f) => empty($f['removedAt'])))[0];
    }

    private function url(string $fileId): string
    {
        return '/api/prototype/payments/'.$this->termId.'/work-evidence/'.$fileId;
    }

    private function submit(): void
    {
        $state = $this->getJson('/api/prototype/state')->json('state');
        $t = $this->term($state['terms']);
        $spk = collect($state['spks'])->firstWhere('id', $t['spkId']);
        $deal = collect($state['deals'])->firstWhere('id', $spk['dealId']);
        $r = $deal['rows'][0];
        $t['actuals'] = [$r['rowId'] => ['m' => $r['m'] ? 0.125 : 0, 's' => $r['s'] ? 0.125 : 0]];
        $t['workEvidence'] = [['id' => 'forged', 'storage_key' => '../../secret']];
        $t['scan'] = ['status' => 'Valid'];
        $t['financeApproval'] = ['decision' => 'Setujui'];
        $t['apmStatus'] = 'Valid';
        $t['paid'] = 999999;
        $this->api('saveTerm', [$t, true])->assertOk();
    }

    public function test_pdf_and_photo_are_private_and_paths_metadata_and_real_bytes_are_persisted(): void
    {
        $pdf = $this->upload()->assertOk();
        $file = $this->file($this->term($pdf->json('state.terms')));
        $this->upload(UploadedFile::fake()->image('foto kerja.png', 4, 3))->assertOk();
        $this->upload(UploadedFile::fake()->image('foto kerja.jpeg', 4, 3))->assertOk();
        $photoResponse = $this->upload(UploadedFile::fake()->createWithContent('foto kerja.webp', base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA')))->assertOk();
        $term = $this->term($photoResponse->json('state.terms'));
        $this->assertCount(4, $term['workEvidence']);
        $this->assertDatabaseCount('term_work_evidence', 4);
        foreach ($term['workEvidence'] as $item) {
            $sql = DB::table('term_work_evidence as e')->join('document_files as f', 'f.id_document_file', '=', 'e.file_id')->where('e.source_id', $item['id'])->first();
            $this->assertTrue(Str::isUuid($sql->id_term_evidence));
            $this->assertSame('local', $sql->storage_disk);
            $this->assertSame('work-evidence/'.PrototypeWorkspace::first()->id.'/'.$this->termId.'/'.$item['id'].'.'.$item['extension'], $sql->storage_key);
            $this->assertSame($item['uploadedBy'], 'db-user-'.$sql->uploaded_by);
            Storage::disk('local')->assertExists($sql->storage_key);
            $this->assertSame($sql->sha256, hash('sha256', Storage::disk('local')->get($sql->storage_key)));
            $this->assertStringNotContainsString('forged', $sql->storage_key);
        }
        $this->asRole('PM Konstruksi');
        $preview = $this->get($this->url($file['id']))->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline;', $preview->headers->get('Content-Disposition'));
        $this->get($this->url($file['id']), ['Range' => 'bytes=0-7'])->assertStatus(206);
        $download = $this->get($this->url($file['id']).'?download=1')->assertOk();
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $this->get($this->url($term['workEvidence'][1]['id']))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get($this->url($term['workEvidence'][2]['id']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get($this->url($term['workEvidence'][3]['id']))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get('/inbox?term='.$this->termId)->assertOk()->assertSee('workEvidence', false);
    }

    public function test_upload_and_remove_are_locked_during_approval_and_return_preserves_file_history(): void
    {
        $this->upload()->assertOk();
        $original = $this->file($this->term());
        $this->submit();
        $term = $this->term();
        $this->assertSame($original['id'], $term['workEvidence'][0]['id']);
        $this->assertArrayNotHasKey('scan', $term);
        $this->assertArrayNotHasKey('financeApproval', $term);
        $this->assertSame('Belum Terbit', $term['apmStatus']);
        $this->assertEquals(0, $term['paid']);
        $this->upload()->assertUnprocessable();
        $this->api('removeTermWorkEvidence', [$this->termId, $original['id']])->assertUnprocessable();
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->asRole('PM Konstruksi');
        $this->get($this->url($original['id']))->assertOk();
        $this->api('decide', [$this->termId, 'Kembalikan', 'Tambahkan eviden'])->assertOk();
        $this->asRole('Admin Konstruksi');
        $this->api('removeTermWorkEvidence', [$this->termId, $original['id']])->assertOk();
        $this->upload()->assertOk();
        $t = $this->term();
        $this->assertCount(2, $t['workEvidence']);
        $this->assertNotEmpty($t['workEvidence'][0]['removedAt']);
        $this->assertCount(1, $t['history']);
        $this->assertDatabaseHas('term_work_evidence', ['source_id' => $original['id']]);
        $this->assertNotNull(DB::table('term_work_evidence')->where('source_id', $original['id'])->value('removed_at'));
        $this->get($this->url($original['id']))->assertOk();
    }

    public function test_rejection_allows_admin_to_replace_work_evidence_before_resubmission_and_keeps_removed_file_history(): void
    {
        $this->upload()->assertOk();
        $original = $this->file($this->term());
        $this->submit();
        $this->asRole('PM Konstruksi');
        $this->api('decide', [$this->termId, 'Tolak', 'Ganti bukti pekerjaan'])->assertOk();
        $this->upload()->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $this->assertSame('Ditolak', $this->term()['status']);
        $this->api('removeTermWorkEvidence', [$this->termId, $original['id']])->assertOk();
        $this->upload()->assertOk();
        $rejected = $this->term();
        $this->assertCount(2, $rejected['workEvidence']);
        $this->assertNotEmpty($rejected['workEvidence'][0]['removedAt']);
        $this->assertSame('Ganti bukti pekerjaan', $rejected['history'][0]['note']);
        $this->get($this->url($original['id']))->assertOk();
        $this->api('saveTerm', [$rejected, true])->assertOk();
        $this->assertEmpty($this->term()['approvals']);
        $this->upload()->assertUnprocessable();
        $this->assertDatabaseCount('term_work_evidence', 2);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_wrong_role_branch_and_guest_cannot_upload_or_read_another_branch_file(): void
    {
        $this->upload()->assertOk();
        $file = $this->file($this->term());
        $this->asRole('PM Konstruksi');
        $this->upload()->assertForbidden();
        $state = $this->getJson('/api/prototype/state')->json('state');
        $term = $this->term($state['terms']);
        $spk = collect($state['spks'])->firstWhere('id', $term['spkId']);
        $deal = collect($state['deals'])->firstWhere('id', $spk['dealId']);
        $branch = collect($state['projects'])->firstWhere('id', $deal['projectId'])['branch'];
        $other = DB::table('branches')->where('name', '!=', $branch)->value('id_branch');
        app(AccountAccess::class)->assign($this->accounts['PM Konstruksi'], 'PM_KONSTRUKSI', [$other]);
        $this->getJson($this->url($file['id']))->assertNotFound();
        Auth::logout();
        $this->getJson($this->url($file['id']))->assertUnauthorized();
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_invalid_or_oversized_files_and_forged_metadata_commands_do_not_save_any_file(): void
    {
        $this->upload(UploadedFile::fake()->createWithContent('fake.pdf', '<html><script>alert(1)</script></html>'))->assertUnprocessable();
        $this->upload(UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'))->assertUnprocessable();
        $this->upload(UploadedFile::fake()->createWithContent('file.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'))->assertUnprocessable();
        $this->api('_attachTermWorkEvidence', [$this->termId, ['id' => (string) Str::uuid(), 'path' => '../../secret']])->assertUnprocessable();
        $this->assertDatabaseCount('term_work_evidence', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_stale_revision_and_replayed_upload_leave_no_orphan_files_or_duplicate_rows(): void
    {
        $this->upload(revision: 0)->assertConflict();
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $revision = $this->getJson('/api/prototype/state')->json('revision');
        $request = (string) Str::uuid();
        $this->upload(request: $request, revision: $revision)->assertOk();
        $this->upload(request: $request, revision: $revision)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('term_work_evidence', 1);
        $this->assertDatabaseCount('prototype_audits', 2);
        $this->assertDatabaseCount('command_receipts', 2);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_sql_failure_rolls_back_file_reference_snapshot_audit_and_revision_and_removes_new_file(): void
    {
        $before = $this->getJson('/api/prototype/state')->json();
        $documents = DB::table('document_files')->count();
        $armed = true;
        DB::listen(function (QueryExecuted $q) use (&$armed) {
            if ($armed && str_contains($q->sql, 'insert into "term_work_evidence"')) {
                $armed = false;
                throw new RuntimeException('Isolated attachment SQL failure');
            }
        });
        $this->upload()->assertServerError();
        $this->assertFalse($armed);
        $this->assertDatabaseCount('term_work_evidence', 0);
        $this->assertDatabaseCount('document_files', $documents);
        $this->assertDatabaseCount('prototype_audits', 1);
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $after = $this->getJson('/api/prototype/state')->assertOk()->json();
        $this->assertSame($before['revision'], $after['revision']);
        $this->assertSame($before['state'], $after['state']);
    }

    public function test_ten_active_files_limit_keeps_removed_history_and_supports_replacement(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->upload()->assertOk();
        }
        $this->upload()->assertUnprocessable();
        $file = $this->file($this->term());
        $this->api('removeTermWorkEvidence', [$this->termId, $file['id']])->assertOk();
        $this->upload()->assertOk();
        $this->assertDatabaseCount('term_work_evidence', 11);
        $this->assertCount(10, array_filter($this->term()['workEvidence'], fn ($f) => empty($f['removedAt'])));
        $this->assertCount(11, Storage::disk('local')->allFiles());
    }
}
