<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\MandorBulkImport;
use App\Services\MasterImportWorkbook;
use App\Services\UploadQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;
use ZipArchive;

class MandorBulkImportTest extends TestCase
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

    private function workbook(array $rows, ?callable $alter = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'area3-mandor-test-');
        try {
            file_put_contents($path, app(MasterImportWorkbook::class)->template('mandor', []));
            $zip = new ZipArchive;
            $zip->open($path);
            $added = '';
            foreach ($rows as $i => $values) {
                $n = $i + 2;
                $added .= '<row r="'.$n.'">';
                foreach ($values as $c => $value) {
                    $added .= '<c r="'.chr(65 + $c).$n.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES).'</t></is></c>';
                }
                $added .= '</row>';
            }
            $xml = str_replace('</sheetData>', $added.'</sheetData>', $zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->addFromString('xl/worksheets/sheet1.xml', $alter ? $alter($xml) : $xml);
            $zip->close();

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    private function send(string $bytes, ?array $preview = null, ?string $uuid = null, array $override = [])
    {
        $input = ['stage' => $preview ? 'commit' : 'preview', 'file' => UploadedFile::fake()->createWithContent('mandor.xlsx', $bytes)];
        if ($preview) {
            $input += ['request_id' => $uuid ?? (string) Str::uuid(), 'token' => $preview['token'], 'sha256' => $preview['sha256']];
        }

        return $this->post('/api/mandors/import', array_replace($input, $override), ['Accept' => 'application/json']);
    }

    public function test_template_has_text_identity_columns_and_only_five_manager_roles_can_download_and_import(): void
    {
        foreach (['Admin Konstruksi', 'Admin Operation', 'Admin Area', 'Officer 1 Operation', 'Superadmin'] as $role) {
            $this->asRole($role);
            $r = $this->get('/api/mandors/template')->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertDownload('template-mandor.xlsx');
            $path = tempnam(sys_get_temp_dir(), 'area3-template-');
            try {
                file_put_contents($path, $r->getContent());
                $zip = new ZipArchive;
                $this->assertTrue($zip->open($path, ZipArchive::CHECKCONS));
                $this->assertStringContainsString('numFmtId="49"', $zip->getFromName('xl/styles.xml'));
                $this->assertStringContainsString('min="3" max="3"', $zip->getFromName('xl/worksheets/sheet1.xml'));
                $this->assertStringContainsString('nik_ktp', $zip->getFromName('xl/worksheets/sheet1.xml'));
                $this->assertStringNotContainsString('r="A2"', $zip->getFromName('xl/worksheets/sheet1.xml'));
                $zip->close();
            } finally {
                unlink($path);
            }
            $this->send($r->getContent())->assertUnprocessable();
        }
        foreach (['Finance', 'Mgr Finance & Settlement', 'PM Konstruksi', 'Manager SS'] as $role) {
            $this->asRole($role);
            $this->getJson('/api/mandors/template')->assertForbidden();
            $this->send($this->workbook([['QA', 'Alamat', '0000000000000001']]))->assertForbidden();
        }
    }

    public function test_preview_is_read_only_commit_encrypted_atomic_replay_bound_and_cross_unit_master_shared(): void
    {
        $bytes = $this->workbook([['Mandor QA', 'Alamat Uji', '0000000000000001'], ['Mandor Dua', 'Alamat Dua', '0000000000000002', '0000123', 'Bank QA', 'Pemilik QA']]);
        $revision = PrototypeWorkspace::first()->revision;
        $preview = $this->send($bytes)->assertOk()->assertJsonPath('summary.created', 2)->assertJsonPath('rows.0.row', 2)->json();
        $this->assertSame(['row', 'name', 'action'], array_keys($preview['rows'][0]));
        $this->assertDatabaseCount('mandors', 0);
        $this->assertDatabaseCount('mandor_imports', 0);
        $uuid = (string) Str::uuid();
        $this->send($bytes, $preview, $uuid)->assertOk()->assertJsonPath('summary.created', 2);
        $this->send($bytes, $preview, $uuid)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('mandors', 2);
        $this->assertDatabaseCount('mandor_changes', 2);
        $this->assertDatabaseCount('mandor_imports', 1);
        $raw = DB::table('mandors')->where('name', 'Mandor Dua')->first();
        $private = json_decode(Crypt::decryptString($raw->private_data), true);
        $this->assertSame('0000000000000002', $private['nik']);
        $this->assertSame('0000123', $private['accountNumber']);
        $this->assertStringNotContainsString($private['nik'], $raw->private_data);
        $audit = DB::table('mandor_changes')->where('mandor_id', $raw->id_mandor)->first();
        $this->assertSame($uuid, $audit->import_id);
        $this->assertStringNotContainsString($private['nik'], $audit->after_data);
        $this->assertNull($raw->unit);
        $this->assertNull($raw->branch_id);
        $this->assertSame($revision, PrototypeWorkspace::first()->revision);
        $this->send($bytes, $preview, $uuid, ['token' => str_repeat('0', 64)])->assertConflict();
        $this->asRole('Admin Operation');
        $this->send($bytes, $preview, $uuid)->assertConflict();
        $this->getJson('/api/mandors')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/mandors/'.$raw->id_mandor)->assertOk()->assertJsonPath('profile.accountNumber', '0000123');
    }

    public function test_upsert_by_nik_preserves_empty_optional_values_photos_id_and_unchanged_version(): void
    {
        $first = $this->workbook([['Mandor QA', 'Alamat Uji', '0000000000000001', '0000123', 'Bank QA', 'Pemilik QA']]);
        $p = $this->send($first)->assertOk()->json();
        $this->send($first, $p)->assertOk();
        $old = DB::table('mandors')->first();
        foreach (['master-ktp', 'master-bank'] as $kind) {
            $task = $this->post('/api/mandors/'.$old->id_mandor.'/uploads/'.$kind, ['request_id' => (string) Str::uuid(), 'file' => UploadedFile::fake()->image('qa.png', 3, 3)], ['Accept' => 'application/json'])->assertAccepted()->json('task.id');
            app(UploadQueue::class)->process($task);
        }
        $old = DB::table('mandors')->first();
        $photos = DB::table('mandor_files')->get()->toJson();
        $bytes = $this->workbook([['Nama Perbaruan', 'Alamat Baru', '0000000000000001']]);
        $p = $this->send($bytes)->assertOk()->assertJsonPath('summary.updated', 1)->json();
        $this->send($bytes, $p)->assertOk();
        $new = DB::table('mandors')->first();
        $this->assertSame($old->id_mandor, $new->id_mandor);
        $this->assertSame($old->created_by, $new->created_by);
        $this->assertEquals($old->version + 1, $new->version);
        $this->assertSame($old->ktp_file_id, $new->ktp_file_id);
        $this->assertSame($old->bank_file_id, $new->bank_file_id);
        $this->assertSame($photos, DB::table('mandor_files')->get()->toJson());
        $this->getJson('/api/mandors/'.$new->id_mandor)->assertOk()->assertJsonPath('profile.accountNumber', '0000123')->assertJsonPath('profile.bank', 'Bank QA')->assertJsonPath('profile.accountName', 'Pemilik QA');
        $p = $this->send($bytes)->assertOk()->assertJsonPath('summary.unchanged', 1)->json();
        $this->send($bytes, $p)->assertOk();
        $this->assertEquals($new->version, DB::table('mandors')->value('version'));
        $this->assertDatabaseCount('mandor_changes', 4);
    }

    public function test_stale_review_changed_file_and_changed_actor_never_overwrite(): void
    {
        $bytes = $this->workbook([['QA', 'Alamat', '0000000000000001']]);
        $p = $this->send($bytes)->assertOk()->json();
        $this->asRole('Admin Operation');
        $this->send($bytes, $p)->assertConflict();
        $this->asRole('Admin Konstruksi');
        $other = $this->workbook([['Isi Lain', 'Alamat', '0000000000000001']]);
        $this->send($other, $p)->assertUnprocessable();
        $this->send($bytes, $p)->assertOk();
        $this->send($bytes, $p)->assertConflict();
        $this->assertDatabaseCount('mandors', 1);
        $this->assertDatabaseCount('mandor_imports', 1);
    }

    public function test_invalid_rows_numeric_identity_formulas_and_duplicates_reject_entire_file(): void
    {
        $base = [['QA', 'Alamat', '0000000000000001']];
        $cases = [
            $this->workbook([['', 'Alamat', '0000000000000001']]),
            $this->workbook([['QA', '', '0000000000000001']]),
            $this->workbook([['QA', 'Alamat', '123']]),
            $this->workbook([$base[0], $base[0]]),
            $this->workbook($base, fn ($x) => str_replace('<c r="C2" t="inlineStr"><is><t>0000000000000001</t></is></c>', '<c r="C2"><v>1000000000000001</v></c>', $x)),
            $this->workbook($base, fn ($x) => str_replace('<c r="C2" t="inlineStr"><is><t>0000000000000001</t></is></c>', '<c r="C2" t="str"><f>TEXT(1,"0000000000000000")</f><v>0000000000000001</v></c>', $x)),
            $this->workbook($base, fn ($x) => str_replace('<worksheet ', '<!DOCTYPE worksheet [<!ENTITY test "bad">]><worksheet ', $x)),
            $this->workbook([['QA', 'Alamat', '0000000000000001', '12,34']]),
        ];
        foreach ($cases as $bytes) {
            $this->send($bytes)->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->assertDatabaseCount('mandors', 0);
        $this->assertDatabaseCount('mandor_changes', 0);
        $this->assertDatabaseCount('mandor_imports', 0);
    }

    public function test_thousand_rows_have_bounded_queries_and_1001_rejects_without_writes(): void
    {
        $rows = [];
        for ($i = 1; $i <= 1000; $i++) {
            $rows[] = ['Mandor '.str_pad($i, 4, '0', STR_PAD_LEFT), 'Alamat Uji', str_pad($i, 16, '0', STR_PAD_LEFT)];
        }
        $bytes = $this->workbook($rows);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $preview = $this->send($bytes)->assertOk()->assertJsonPath('total', 1000)->json();
        $previewCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->send($bytes, $preview)->assertOk()->assertJsonPath('summary.created', 1000);
        $commitCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        fwrite(STDERR, "\nMandor 1000 row preview {$previewCount}, commit {$commitCount} HTTP queries.\n");
        $this->assertLessThanOrEqual(15, $previewCount);
        $this->assertLessThanOrEqual(65, $commitCount);
        $this->assertDatabaseCount('mandors', 1000);
        $rows[] = ['Extra', 'Alamat', '0000000000001001'];
        $this->send($this->workbook($rows))->assertUnprocessable();
        $this->assertDatabaseCount('mandors', 1000);
        $this->assertDatabaseCount('mandor_imports', 1);
    }

    public function test_missing_import_migration_is_actionable_without_writes_then_same_uuid_can_commit_after_migration(): void
    {
        $bytes = $this->workbook([['QA', 'Alamat', '0000000000000001']]);
        $preview = $this->send($bytes)->assertOk()->json();
        $uuid = (string) Str::uuid();
        $migration = require database_path('migrations/2026_11_26_000003_create_mandor_import_receipts.php');
        $migration->down();
        $result = $this->send($bytes, $preview, $uuid)->assertStatus(503)->assertJsonPath('code', 'MANDOR_IMPORT_MIGRATION_REQUIRED');
        $this->assertStringNotContainsString('SQLSTATE', $result->getContent());
        $this->assertStringContainsString('migration terbaru', $result->json('message'));
        $this->assertDatabaseCount('mandors', 0);
        $this->assertDatabaseCount('mandor_changes', 0);
        $migration->up();
        $this->send($bytes, $preview, $uuid)->assertOk()->assertJsonPath('summary.created', 1);
        $this->send($bytes, $preview, $uuid)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('mandors', 1);
        $this->assertDatabaseCount('mandor_imports', 1);
    }

    public function test_mysql_missing_receipt_table_is_reported_safely_without_schema_probe(): void
    {
        $pdo = new \PDOException('Table missing');
        $pdo->errorInfo = ['42S02', 1146, "Table 'its.mandor_imports' doesn't exist"];
        $exception = new QueryException('mysql', 'select * from `mandor_imports` where `id_mandor_import` = ?', ['synthetic-uuid'], $pdo);
        $this->mock(MandorBulkImport::class, function ($mock) use ($exception) {
            $mock->shouldReceive('rows')->once()->andReturn([]);
            $mock->shouldReceive('run')->once()->andThrow($exception);
        });
        $this->send($this->workbook([['QA', 'Alamat', '0000000000000001']]))->assertStatus(503)->assertJsonPath('code', 'MANDOR_IMPORT_MIGRATION_REQUIRED');
        $this->assertDatabaseCount('mandors', 0);
    }

    public function test_late_audit_failure_rolls_back_receipt_and_master_and_populated_rollback_is_refused(): void
    {
        $bytes = $this->workbook([['QA', 'Alamat', '0000000000000001']]);
        $p = $this->send($bytes)->assertOk()->json();
        DB::unprepared("CREATE TRIGGER mandor_fail_import BEFORE INSERT ON mandor_changes BEGIN SELECT RAISE(ABORT,'synthetic audit failure'); END");
        $this->send($bytes, $p)->assertServerError();
        $this->assertDatabaseCount('mandors', 0);
        $this->assertDatabaseCount('mandor_imports', 0);
        DB::unprepared('DROP TRIGGER mandor_fail_import');
        $this->send($bytes, $p)->assertOk();
        $migration = require database_path('migrations/2026_11_26_000003_create_mandor_import_receipts.php');
        try {
            $migration->down();
            $this->fail('Populated rollback allowed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rollback ditolak', $e->getMessage());
        }
        $this->assertDatabaseCount('mandors', 1);
        $this->assertDatabaseCount('mandor_imports', 1);
    }
}
