<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\AuthenticatedWorkspace;
use App\Services\MasterDesignatorService;
use App\Services\MasterImportWorkbook;
use App\Services\RelationalWorkflowStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;
use ZipArchive;

class MasterBulkImportTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->asRole();
        $this->getJson('/api/masters')->assertOk();
    }

    private function workbook(string $entity, array $rows, ?callable $alter = null): string
    {
        $bytes = app(MasterImportWorkbook::class)->template($entity, app(MasterDesignatorService::class)->catalog());
        $path = tempnam(sys_get_temp_dir(), 'area3-master-test-');
        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive;
            $zip->open($path);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $added = '';
            foreach ($rows as $i => $values) {
                $n = $i + 2;
                $added .= '<row r="'.$n.'">';
                foreach ($values as $c => $value) {
                    $added .= '<c r="'.chr(65 + $c).$n.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES).'</t></is></c>';
                }
                $added .= '</row>';
            }
            $xml = str_replace('</sheetData>', $added.'</sheetData>', $xml);
            $zip->addFromString('xl/worksheets/sheet1.xml', $alter ? $alter($xml) : $xml);
            $zip->close();

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    private function requestImport(string $entity, string $bytes, string $stage = 'preview', string $mode = 'create', ?int $revision = null, ?string $uuid = null)
    {
        return $this->post('/api/masters/'.$entity.'/import', ['file' => UploadedFile::fake()->createWithContent('master.xlsx', $bytes),
            // Browser multipart fields are strings, even though the revision is an integer in JSON.
            'stage' => $stage, 'mode' => $mode, 'revision' => (string) ($revision ?? PrototypeWorkspace::firstOrFail()->revision),
            'request_id' => $uuid ?? (string) Str::uuid(), 'sha256' => hash('sha256', $bytes)], ['Accept' => 'application/json']);
    }

    private function typeCode(): string
    {
        return DB::table('designator_types')->where('id_designator_type', 1)->value('code');
    }

    public function test_template_download_uses_only_two_or_three_reference_queries_without_loading_price_or_workflow_tables(): void
    {
        foreach (['designator' => 2, 'khs' => 3] as $entity => $expected) {
            DB::enableQueryLog();
            try {
                $this->get('/api/masters/'.$entity.'/template')->assertOk()->assertDownload('template-'.$entity.'.xlsx');
                $queries = array_column(DB::getQueryLog(), 'query');
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $references = array_filter($queries, fn ($sql) => preg_match('/from ["`]?((designator_types)|(designators)|(packages))["`]?\b/i', $sql));
            $this->assertCount($expected, $references);
            foreach ($queries as $sql) {
                $this->assertDoesNotMatchRegularExpression('/designator_package_prices|prototype_workspaces|project_plans|workflow_metadata|for update/i', $sql);
            }
        }
    }

    public function test_template_archive_generation_does_not_need_a_writable_system_temp_directory(): void
    {
        $unavailable = sys_get_temp_dir().'/area3-unavailable-'.Str::uuid().'/nested';
        $code = 'require '.var_export(base_path('vendor/autoload.php'), true).';'
            .'set_error_handler(function($n,$m){throw new \\ErrorException($m,0,$n);});'
            .'if(sys_get_temp_dir()!=='.var_export($unavailable, true).'){exit(91);}'
            .'$catalog=["types"=>[(object)["code"=>"M","name"=>"Material"]],"packages"=>[(object)["code"=>"P5","name"=>"Paket 5"]],"designators"=>[(object)["code"=>"M-FO","item_name"=>"Kabel & aksesoris","unit"=>"meter","designator_type_id"=>1]]];'
            .'echo (new \\App\\Services\\MasterImportWorkbook)->template("khs",$catalog);';
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.$unavailable, '-r', $code], base_path(), ['TMPDIR' => $unavailable]);
        $process->mustRun();
        $path = tempnam(sys_get_temp_dir(), 'area3-zip-check-');
        try {
            file_put_contents($path, $process->getOutput());
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::CHECKCONS));
            $this->assertSame(10, $zip->numFiles);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $bytes = $zip->getFromIndex($i);
                $this->assertNotFalse($bytes);
                $this->assertSame(sprintf('%u', crc32($bytes)), sprintf('%u', $zip->statIndex($i)['crc']));
                $xml = new \DOMDocument;
                $this->assertTrue($xml->loadXML($bytes, LIBXML_NONET));
            }
            $this->assertStringContainsString('Kabel &amp; aksesoris', $zip->getFromName('xl/worksheets/sheet5.xml'));
            $zip->close();
            $this->assertDirectoryDoesNotExist($unavailable);
        } finally {
            unlink($path);
        }
    }

    public function test_templates_are_real_empty_xlsx_with_active_reference_codes_and_both_routes_require_superadmin(): void
    {
        foreach (['designator', 'khs'] as $entity) {
            $response = $this->get('/api/masters/'.$entity.'/template')->assertOk()->assertDownload('template-'.$entity.'.xlsx');
            $path = tempnam(sys_get_temp_dir(), 'area3-template-test-');
            try {
                file_put_contents($path, $response->getContent());
                $zip = new ZipArchive;
                $this->assertTrue($zip->open($path));
                $this->assertStringContainsString('9FA1FF', $zip->getFromName('xl/styles.xml'));
                $this->assertStringContainsString('Maksimal 1000 baris / 5 MB', $zip->getFromName('xl/worksheets/sheet2.xml'));
                $this->assertStringContainsString('kode_designator', $zip->getFromName('xl/worksheets/sheet1.xml'));
                $this->assertStringNotContainsString('r="A2"', $zip->getFromName('xl/worksheets/sheet1.xml'));
                $this->assertStringContainsString('Paket-5', $zip->getFromName('xl/worksheets/sheet4.xml'));
                $this->assertStringContainsString($this->typeCode(), $zip->getFromName('xl/worksheets/sheet3.xml'));
                $zip->close();
                $this->requestImport($entity, $response->getContent())->assertUnprocessable()->assertJsonValidationErrors('file');
            } finally {
                unlink($path);
            }
        }
        foreach (['Admin Konstruksi', 'PM Konstruksi', 'Mgr Project Support'] as $role) {
            $this->asRole($role);
            $this->get('/api/masters/khs/template')->assertForbidden();
            $this->postJson('/api/masters/designator/import', [])->assertForbidden();
        }
        $this->post('/logout');
        $this->getJson('/api/masters/designator/template')->assertUnauthorized();
        $this->postJson('/api/masters/khs/import', [])->assertUnauthorized();
    }

    public function test_designator_preview_is_read_only_commit_is_atomic_audited_and_request_replay_checks_file_identity(): void
    {
        $bytes = $this->workbook('designator', [['M-BULK-A', 'Kabel A', 'meter', $this->typeCode()], ['M-BULK-B', 'Kabel B', 'Bh', $this->typeCode()]]);
        $archive = PrototypeWorkspace::first()->getRawOriginal('state');
        $this->requestImport('designator', $bytes)->assertOk()->assertJsonPath('summary.created', 2)->assertJsonPath('rows.0.row', 2);
        $this->assertDatabaseCount('designators', 724);
        $this->assertDatabaseCount('master_data_changes', 0);
        $uuid = (string) Str::uuid();
        $this->requestImport('designator', $bytes, 'commit', 'create', 0, $uuid)->assertOk()->assertJsonPath('summary.created', 2)->assertJsonPath('revision', 1);
        $this->assertDatabaseCount('designators', 726);
        $this->assertDatabaseCount('master_data_changes', 2);
        $this->assertDatabaseCount('prototype_audits', 1);
        $this->assertDatabaseCount('command_receipts', 1);
        $this->assertCount(2, DB::table('master_data_changes')->pluck('request_uuid')->unique());
        $this->assertLessThanOrEqual(60, strlen(DB::table('prototype_audits')->value('command')));
        $this->assertSame($archive, PrototypeWorkspace::first()->getRawOriginal('state'));
        $this->requestImport('designator', $bytes, 'commit', 'create', 0, $uuid)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('summary.created', 2);
        $changed = $this->workbook('designator', [['M-OTHER', 'Other', 'M', $this->typeCode()]]);
        $this->requestImport('designator', $changed, 'commit', 'create', 1, $uuid)->assertConflict();
        $this->assertDatabaseCount('master_data_changes', 2);
        $this->requestImport('designator', $bytes, 'commit', 'create', 1)->assertUnprocessable()->assertJsonValidationErrors('rows.2.duplikat');
    }

    public function test_khs_import_resolves_codes_accepts_zero_and_cached_price_and_preserves_issued_rows(): void
    {
        $this->seedWorkflow('B');
        $before = DB::table('project_plans')->get()->toJson();
        $lines = DB::table('boq_lines')->get()->toJson();
        $priceCount = DB::table('designator_package_prices')->count();
        $bytes = $this->workbook('khs', [['J-AC-OF-SM-24D', 'Paket-5', '4321'], ['J-ODP Solid-PB-16 AS', 'Paket-5', '0']],
            fn ($xml) => preg_replace('/<c r="C2".*?<\/c>/', '<c r="C2"><f>1+1</f><v>4321</v></c>', $xml));
        $this->requestImport('khs', $bytes)->assertOk()->assertJsonPath('summary.created', 2);
        $this->requestImport('khs', $bytes, 'commit')->assertOk()->assertJsonPath('summary.created', 2);
        $this->assertDatabaseCount('designator_package_prices', $priceCount + 2);
        $this->assertDatabaseHas('designator_package_prices', ['designator_id' => 1127, 'package_id' => 2, 'price' => 4321]);
        $this->assertDatabaseHas('designator_package_prices', ['designator_id' => 1399, 'package_id' => 2, 'price' => 0]);
        $updated = $this->workbook('khs', [['J-AC-OF-SM-24D', 'Paket-5', '4322'], ['J-ODP Solid-PB-16 AS', 'Paket-5', '0.00']]);
        $this->requestImport('khs', $updated, 'commit', 'upsert')->assertOk()->assertJsonPath('summary.updated', 1)->assertJsonPath('summary.unchanged', 1);
        $this->assertSame($before, DB::table('project_plans')->get()->toJson());
        $this->assertSame($lines, DB::table('boq_lines')->get()->toJson());
        $this->assertDatabaseCount('master_data_changes', 3);
    }

    public function test_full_1000_row_designator_and_khs_batches_get_unique_ids_and_one_revision_each(): void
    {
        $type = $this->typeCode();
        $rows = array_map(fn ($n) => ['M-BULK-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'Pekerjaan '.$n, 'M', $type], range(1, 1000));
        $bytes = $this->workbook('designator', $rows);
        $this->requestImport('designator', $bytes, 'commit')->assertOk()->assertJsonPath('summary.created', 1000)->assertJsonPath('revision', 1);
        $this->assertSame(1000, DB::table('designators')->where('code', 'like', 'M-BULK-%')->distinct()->count('id_designator'));
        $this->assertDatabaseCount('master_data_changes', 1000);
        $this->assertDatabaseCount('command_receipts', 1);
        $this->assertDatabaseCount('prototype_audits', 1);
        $prices = array_map(fn ($r) => [$r[0], 'Paket-5', '0'], $rows);
        $khs = $this->workbook('khs', $prices);
        $this->requestImport('khs', $khs)->assertOk()->assertJsonPath('summary.created', 1000)->assertJsonCount(1000, 'rows');
        $uuid = (string) Str::uuid();
        $workflowReads = 0;
        $track = true;
        DB::listen(function ($query) use (&$workflowReads, &$track) {
            if ($track && preg_match('/^select .*from ["`]?project_plans\b/i', $query->sql)) {
                $workflowReads++;
            }
        });
        $this->requestImport('khs', $khs, 'commit', 'create', 1, $uuid)->assertOk()->assertJsonPath('summary.created', 1000)->assertJsonPath('revision', 2);
        $track = false;
        $this->assertSame(1, $workflowReads, 'A 1000-row batch must read the workflow once, not for each imported row.');
        $this->assertDatabaseCount('designator_package_prices', 2030);
        $this->assertDatabaseCount('master_data_changes', 2000);
        $this->assertDatabaseCount('command_receipts', 2);
        $this->assertDatabaseCount('prototype_audits', 2);
        $this->requestImport('khs', $khs, 'commit', 'create', 1, $uuid)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('summary.created', 1000);
        $this->assertDatabaseCount('designator_package_prices', 2030);
    }

    public function test_preview_uses_current_revision_but_stale_multipart_commit_cannot_overwrite_intervening_changes(): void
    {
        $bytes = $this->workbook('designator', [['M-REVISION-1', 'Pekerjaan baru', 'meter', $this->typeCode()]]);
        $checked = $this->requestImport('designator', $bytes)->assertOk()->assertJsonPath('revision', 0)->json();
        // Simulate another valid transaction after this inspection.
        $other = $this->workbook('designator', [['M-REVISION-2', 'Pekerjaan lain', 'meter', $this->typeCode()]]);
        $this->requestImport('designator', $other, 'commit', 'create', 0)->assertOk()->assertJsonPath('revision', 1);
        $this->requestImport('designator', $bytes, 'commit', 'create', $checked['revision'])->assertConflict();
        $this->assertDatabaseMissing('designators', ['code' => 'M-REVISION-1']);
        $this->assertDatabaseCount('master_data_changes', 1);
        // The existing screen need not close the dialog or fetch the full catalog.
        $fresh = $this->requestImport('designator', $bytes, 'preview', 'create', 0)->assertOk()->assertJsonPath('revision', 1)->json();
        $this->assertSame(1, PrototypeWorkspace::first()->revision);
        $this->requestImport('designator', $bytes, 'commit', 'create', $fresh['revision'])->assertOk()->assertJsonPath('revision', 2);
        $this->assertDatabaseCount('master_data_changes', 2);
        $this->assertDatabaseCount('command_receipts', 2);
    }

    public function test_supplied_435_row_workbook_is_parsed_and_previewed_without_mutating_masters(): void
    {
        $bytes = file_get_contents(base_path('tests/Fixtures/boq/master-designator-435.xlsx'));
        $parsed = app(MasterImportWorkbook::class)->read(base_path('tests/Fixtures/boq/master-designator-435.xlsx'), 'designator');
        $this->assertCount(435, $parsed);
        $this->assertSame('J-AB-OF-SM-12D', $parsed[0]['data']['kode_designator']);
        $preview = $this->requestImport('designator', $bytes, 'preview', 'upsert')->assertOk()->assertJsonCount(435, 'rows')->json();
        $this->assertSame(435, array_sum($preview['summary']));
        $this->assertDatabaseCount('designators', 724);
        $this->assertDatabaseCount('master_data_changes', 0);
        $this->assertDatabaseCount('command_receipts', 0);
        $this->assertSame(0, PrototypeWorkspace::first()->revision);
    }

    public function test_all_row_errors_are_reported_and_one_bad_row_prevents_any_batch_writes(): void
    {
        $bytes = $this->workbook('designator', [['M-VALID-FIRST', 'Valid', 'M', $this->typeCode()], ['M-BAD', '', 'M', 'UNKNOWN'], ['M-VALID-FIRST', 'Duplicate', 'M', $this->typeCode()]]);
        foreach (['preview', 'commit'] as $stage) {
            $this->requestImport('designator', $bytes, $stage)->assertUnprocessable()->assertJsonValidationErrors(['rows.3.kode_jenis', 'rows.4.duplikat']);
        }
        $this->assertDatabaseMissing('designators', ['code' => 'M-VALID-FIRST']);
        $this->assertSame(0, PrototypeWorkspace::first()->revision);
        $this->assertDatabaseCount('master_data_changes', 0);
        $this->assertDatabaseCount('command_receipts', 0);
    }

    public function test_price_reference_duplicate_and_precision_guards_and_archive_preservation_apply_to_bulk(): void
    {
        foreach (['', '-1', '1.234', '10000000000000', 'NaN'] as $price) {
            $this->requestImport('khs', $this->workbook('khs', [['J-AC-OF-SM-24D', 'Paket-5', $price]]), 'commit')->assertUnprocessable()->assertJsonValidationErrors('rows.2.price');
        }
        $bytes = $this->workbook('khs', [['UNKNOWN', 'Paket-5', 1], ['J-AC-OF-SM-24D', 'UNKNOWN', 2]]);
        $this->requestImport('khs', $bytes, 'commit')->assertUnprocessable()->assertJsonValidationErrors(['rows.2.kode_designator', 'rows.3.kode_paket']);
        $bytes = $this->workbook('khs', [['J-AC-OF-SM-24D', 'Paket-5', 1], ['j-ac-of-sm-24d', 'paket-5', 2]]);
        $this->requestImport('khs', $bytes, 'commit')->assertUnprocessable()->assertJsonValidationErrors('rows.3.duplikat');
        DB::table('designators')->where('id_designator', 591)->update(['deleted_at' => now()]);
        $bytes = $this->workbook('designator', [['M-AC-OF-SM-24D', 'Archive', 'meter', $this->typeCode()]]);
        $this->requestImport('designator', $bytes, 'commit', 'upsert')->assertUnprocessable()->assertJsonValidationErrors('rows.2.arsip');
        $this->assertDatabaseCount('master_data_changes', 0);
    }

    public function test_used_designator_identity_stale_revision_forged_hash_and_database_failure_cannot_partially_import(): void
    {
        $this->seedWorkflow('B');
        $workspace = PrototypeWorkspace::firstOrFail();
        $state = app(AuthenticatedWorkspace::class)->bind(app(RelationalWorkflowStore::class)->read($workspace));
        $khs = collect($state['khs'])->firstWhere('active', true);
        $state['projects'][0]['boq'][0] = array_merge($state['projects'][0]['boq'][0], ['khsId' => $khs['id'], 'm' => $khs['m'], 's' => $khs['s'], 'version' => $khs['version']]);
        DB::transaction(fn () => app(RelationalWorkflowStore::class)->write($workspace, $state));
        $d = DB::table('boq_lines as b')->join('designator_package_prices as p', 'p.id_designator_package_price', '=', 'b.source_price_id')->join('designators as d', 'd.id_designator', '=', 'p.designator_id')->first(['d.*']);
        $type = DB::table('designator_types')->where('id_designator_type', $d->designator_type_id)->value('code');
        $bytes = $this->workbook('designator', [[$d->code, 'Changed used identity', $d->unit, $type], ['M-NEW-ROLLBACK', 'New', 'M', $this->typeCode()]]);
        $this->requestImport('designator', $bytes, 'commit', 'upsert')->assertUnprocessable()->assertJsonValidationErrors('rows.2.master');
        $valid = $this->workbook('designator', [['M-NEW-ROLLBACK', 'New', 'M', $this->typeCode()]]);
        $this->requestImport('designator', $valid, 'commit', 'create', 999)->assertConflict();
        $this->post('/api/masters/designator/import', ['file' => UploadedFile::fake()->createWithContent('bulk.xlsx', $valid), 'stage' => 'commit', 'mode' => 'create', 'revision' => PrototypeWorkspace::first()->revision,
            'request_id' => (string) Str::uuid(), 'sha256' => str_repeat('0', 64)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->partialMock(RelationalWorkflowStore::class, fn (MockInterface $mock) => $mock->shouldReceive('writeReferenceMetadata')->once()->andThrow(new \RuntimeException('Injected write failure')));
        $this->requestImport('designator', $valid, 'commit')->assertStatus(500);
        $this->assertDatabaseMissing('designators', ['code' => 'M-NEW-ROLLBACK']);
        $this->assertDatabaseCount('master_data_changes', 0);
        $this->assertDatabaseCount('command_receipts', 0);
    }

    public function test_invalid_xlsx_headers_formula_errors_archives_and_batch_size_fail_closed_and_csrf_is_required(): void
    {
        $data = [['J-AC-OF-SM-24D', 'Paket-5', '4321']];
        foreach ([
            fn ($xml) => str_replace('kode_designator', 'wrong_header', $xml),
            fn ($xml) => preg_replace('/<c r="C2".*?<\/c>/', '<c r="C2"><f>1+1</f><v/></c>', $xml),
            fn ($xml) => preg_replace('/<c r="C2".*?<\/c>/', '<c r="C2" t="e"><f>1/0</f><v>#DIV/0!</v></c>', $xml),
            fn ($xml) => preg_replace('/<c r="A2".*?<\/c>/', '<c r="A2" t="str"><f>WEBSERVICE(&quot;https://invalid.example&quot;)</f><v>J-AC-OF-SM-24D</v></c>', $xml),
            fn ($xml) => str_replace('<worksheet ', '<!DOCTYPE worksheet [<!ENTITY x SYSTEM "file:///etc/passwd">]><worksheet ', $xml),
        ] as $alter) {
            $this->requestImport('khs', $this->workbook('khs', $data, $alter), 'commit')->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        foreach (['designator' => ['M-OVER-LIMIT', 'Material', 'meter', $this->typeCode()], 'khs' => $data[0]] as $entity => $row) {
            $this->requestImport($entity, $this->workbook($entity, array_fill(0, 1001, $row)), 'commit')
                ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Maksimal 1000 baris data per impor.');
        }
        $this->requestImport('khs', 'not a zip', 'commit')->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->app->instance('env', 'local');
        $this->requestImport('khs', $this->workbook('khs', $data), 'commit')->assertStatus(419);
        $this->assertDatabaseCount('master_data_changes', 0);
    }
}
