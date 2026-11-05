<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountAccess;
use App\Services\BoqImportStorage;
use App\Services\PrototypeDomain;
use App\Services\PrototypeWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;
use ZipArchive;

class BoqWorkbookImportTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->asRole('Admin Konstruksi');
    }

    private function fixture(): string
    {
        return base_path('tests/Fixtures/boq/osp-fo-paket10.xlsx');
    }

    private function file(?callable $change = null): UploadedFile
    {
        if (! $change) {
            return UploadedFile::fake()->createWithContent('BOQ-contoh.xlsx', file_get_contents($this->fixture()));
        }
        $path = tempnam(sys_get_temp_dir(), 'area3-boq-');
        try {
            copy($this->fixture(), $path);
            $zip = new ZipArchive;
            $zip->open($path);
            $zip->addFromString('xl/worksheets/sheet1.xml', $change($zip->getFromName('xl/worksheets/sheet1.xml')));
            $zip->close();

            return UploadedFile::fake()->createWithContent('BOQ-varian.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    private function upload(?callable $change = null)
    {
        return $this->post('/api/prototype/boq/import', ['file' => $this->file($change)], ['Accept' => 'application/json']);
    }

    private function api(array $p, bool $submit = false)
    {
        $revision = $this->getJson('/api/prototype/state')->assertOk()->json('revision');

        return $this->postJson('/api/prototype/commands', ['command' => 'saveProject', 'args' => [$p, $submit], 'revision' => $revision, 'request_id' => (string) Str::uuid()]);
    }

    private function project(array $import): array
    {
        $p = app(PrototypeWorkspaceService::class)->scenario('B')['projects'][0];
        $p['id'] = $p['rootId'] = 'pp-file-import';
        $p['branch'] = 'DENPASAR';
        $p['unit'] = 'Konstruksi';
        $p['riskLevel'] = 'SEDANG';
        $p['costs']['permit'] = 0;
        $p['budget'] = $import['source']['sourceTotal'];
        $p['boq'] = $import['rows'];
        $p['boqUpload'] = $import['source'];
        unset($p['pmUserId'], $p['managerUserId'], $p['approverUserIds']);

        return $p;
    }

    private function approvers(array $p): array
    {
        foreach (PrototypeDomain::APPROVERS_V3 as $role) {
            $p['approverUserIds'][$role] = 'db-user-'.$this->asRole($role)->getKey();
        }
        $this->asRole('Admin Konstruksi');

        return $p;
    }

    public function test_supplied_workbook_is_actually_uploaded_split_and_saved_as_draft_without_approvers(): void
    {
        $import = $this->upload()->assertOk()->assertJsonCount(26, 'rows')->assertJsonPath('source.sourceRows', 14)->assertJsonPath('source.package', 'Paket 10')->assertJsonPath('source.sourceTotal', 140056789)->json();
        $total = 0;
        $material = 0;
        $service = 0;
        foreach ($import['rows'] as $row) {
            $total += $row['qty'] * (($row['m'] ?? 0) + ($row['s'] ?? 0));
            $material += $row['qty'] * ($row['m'] ?? 0);
            $service += $row['qty'] * ($row['s'] ?? 0);
            $this->assertTrue($row['reviewed']);
            $this->assertTrue(($row['m'] === null) !== ($row['s'] === null));
        }
        $this->assertEquals(140056789, $total);
        $this->assertEquals(64523245, $material);
        $this->assertEquals(75533544, $service);
        $this->assertCount(1, array_filter($import['rows'], fn ($r) => $r['sourceCode'] === 'OS-SM-1'));
        $this->assertCount(1, array_filter($import['rows'], fn ($r) => $r['sourceCode'] === 'BC-TR-0.4'));
        $this->assertSame(hash_file('sha256', $this->fixture()), $import['source']['sha256']);
        $workspace = app(PrototypeWorkspaceService::class)->workspace(request());
        $directory = app(BoqImportStorage::class)->directory($workspace, $import['source']['id']);
        Storage::disk('local')->assertExists([$directory.'/source.xlsx', $directory.'/manifest.json']);
        $this->assertFalse(str_starts_with(Storage::disk('local')->path($directory), public_path()));
        $this->assertSame(0, $workspace->revision);
        $this->assertDatabaseCount('prototype_audits', 0);
        $p = $this->project($import);
        $p['boqUpload']['name'] = 'CLIENT-FORGED-NAME';
        $response = $this->api($p)->assertOk()->assertJsonPath('state.projects.0.status', 'Draft')->assertJsonPath('state.projects.0.boqUpload.name', 'BOQ-contoh.xlsx');
        $saved = $response->json('state.projects.0');
        $this->assertCount(0, $saved['approverUserIds']);
        $this->assertCount(26, $saved['boq']);
        $this->get('/api/prototype/boq/'.$p['id'].'/source')->assertOk()->assertDownload('BOQ-contoh.xlsx');
        $this->api($p, true)->assertUnprocessable();
        $this->assertDatabaseCount('prototype_audits', 1);
        $p = $this->approvers($p);
        $number = $saved['no'];
        $this->api($p, true)->assertOk()->assertJsonPath('state.projects.0.no', $number)->assertJsonPath('state.projects.0.status', 'Diajukan');
    }

    public function test_price_differences_require_explicit_review_and_client_cannot_replace_source_prices(): void
    {
        $import = $this->upload(fn ($xml) => preg_replace('/(<c\b[^>]*\br="E75"[^>]*>\s*<v>)[^<]+/', '${1}704701', $xml))->assertOk()->json();
        $this->assertSame(704701, $import['rows'][0]['excelM']);
        $this->assertFalse($import['rows'][0]['reviewed']);
        $p = $this->project($import);
        $p['boq'][0]['excelM'] = 704700;
        $p['boq'][0]['reviewed'] = true;
        $this->api($p)->assertOk()->assertJsonPath('state.projects.0.boq.0.excelM', 704701)->assertJsonPath('state.projects.0.boq.0.reviewed', false)->assertJsonPath('state.projects.0.boq.0.m', 704700);
        $p = $this->approvers($p);
        $this->api($p, true)->assertUnprocessable();
        $p['boq'][0]['note'] = 'Gunakan harga KHS Paket 10 setelah diperiksa.';
        $this->api($p, true)->assertOk()->assertJsonPath('state.projects.0.boq.0.reviewed', true)->assertJsonPath('state.projects.0.boq.0.excelM', 704701);
    }

    public function test_bad_structure_input_formula_precision_unknown_designator_and_unit_are_rejected_atomically(): void
    {
        $variants = [
            fn ($x) => preg_replace('/(<c\b[^>]*\br="G75"[^>]*>\s*<v>)[^<]+/', '${1}-1', $x),
            fn ($x) => preg_replace('/(<c\b[^>]*\br="G75"[^>]*>\s*<v>)[^<]+/', '${1}0.000000001', $x),
            fn ($x) => preg_replace('/(<c\b[^>]*\br="G75"[^>]*>)\s*<v>[^<]+<\/v>/', '${1}<f>5+6</f><v>11</v>', $x),
            fn ($x) => preg_replace('/<c\b[^>]*\br="B75"[^>]*>.*?<\/c>/s', '<c r="B75" t="inlineStr"><is><t>UNKNOWN-BOQ</t></is></c>', $x),
            fn ($x) => preg_replace('/<c\b[^>]*\br="D75"[^>]*>.*?<\/c>/s', '<c r="D75" t="inlineStr"><is><t>meter</t></is></c>', $x),
            fn ($x) => preg_replace('/<c\b[^>]*\br="B6"[^>]*>.*?<\/c>/s', '<c r="B6" t="inlineStr"><is><t>WRONG HEADER</t></is></c>', $x),
            fn ($x) => str_replace('<worksheet ', '<!DOCTYPE worksheet [<!ENTITY x SYSTEM "file:///etc/passwd">]><worksheet ', $x),
            fn ($x) => preg_replace('/(<c\b[^>]*\br="G76"[^>]*>\s*<v>)[^<]+/', '${1}1', $x),
        ];
        foreach ($variants as $variant) {
            $this->upload($variant)->assertUnprocessable()->assertJsonValidationErrors('file');
            $this->assertCount(0, Storage::disk('local')->allFiles());
            $this->assertDatabaseCount('prototype_audits', 0);
        }
        $this->post('/api/prototype/boq/import', ['file' => UploadedFile::fake()->createWithContent('fake.xlsx', 'not an Excel workbook')], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_khs_change_invalidates_a_previous_file_price_review(): void
    {
        $p = $this->project($this->upload()->assertOk()->json());
        $p = $this->approvers($p);
        $p['boq'][0]['note'] = 'Harga KHS diperiksa sebelumnya';
        $this->api($p)->assertOk();
        $priceId = (int) str_replace('db-price-', '', $p['boq'][0]['khsId']);
        $this->asRole('Superadmin');
        $source = DB::table('designator_package_prices')->where('id_designator_package_price', $priceId)->first();
        $revision = $this->getJson('/api/masters')->assertOk()->json('revision');
        $this->postJson('/api/masters/khs', ['action' => 'save', 'id' => $priceId, 'data' => ['designator_id' => $source->designator_id, 'package_id' => $source->package_id, 'price' => '704702.00'],
            'revision' => $revision, 'request_id' => (string) Str::uuid()])->assertOk();
        $this->asRole('Admin Konstruksi');
        $this->api($p, true)->assertUnprocessable();
        $saved = $this->api($p)->assertOk()->assertJsonPath('state.projects.0.boq.0.reviewed', false)->json('state.projects.0');
        $this->assertEquals(704702, $saved['boq'][0]['m']);
        $saved['boq'][0]['reviewed'] = true;
        $saved['boq'][0]['note'] = 'Harga KHS terbaru diperiksa kembali';
        $this->api($saved, true)->assertOk();
    }

    public function test_cached_excel_totals_are_ignored_and_amounts_are_recomputed_from_source_inputs(): void
    {
        $this->upload(fn ($xml) => preg_replace('/(<c\b[^>]*\br="J75"[^>]*>.*?<v>)[^<]+/', '${1}999999999', $xml))
            ->assertOk()->assertJsonPath('source.sourceTotal', 140056789)->assertJsonCount(26, 'rows');
    }

    public function test_macro_or_excessive_archive_parts_fail_before_any_private_upload_is_saved(): void
    {
        foreach (['xl/vbaProject.bin' => 'not a supported macro', 'xl/oversized.xml' => str_repeat('x', 16 * 1024 * 1024 + 1)] as $entry => $content) {
            $path = tempnam(sys_get_temp_dir(), 'area3-boq-');
            try {
                $zip = new ZipArchive;
                $zip->open($path, ZipArchive::OVERWRITE);
                $zip->addFromString($entry, $content);
                $zip->close();
                $file = UploadedFile::fake()->createWithContent('unsupported.xlsx', file_get_contents($path));
                $this->post('/api/prototype/boq/import', ['file' => $file], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
                $this->assertCount(0, Storage::disk('local')->allFiles());
            } finally {
                unlink($path);
            }
        }
    }

    public function test_file_references_are_private_scoped_and_never_accepted_from_client_as_trusted_manifest(): void
    {
        $import = $this->upload()->assertOk()->json();
        $p = $this->project($import);
        $p['boqUpload']['id'] = '../escape';
        $this->api($p)->assertUnprocessable();
        $p = $this->project($import);
        $this->asRole('PM Konstruksi');
        $this->upload()->assertForbidden();
        $this->asRole('Admin Konstruksi');
        $this->api($p)->assertOk();
        $this->get('/api/prototype/boq/no-such-project/source')->assertNotFound();
        $outsider = User::factory()->create(['active' => true]);
        app(AccountAccess::class)->assign($outsider, 'ADMIN_KONSTRUKSI', [DB::table('branches')->where('name', 'PEKALONGAN')->value('id_branch')]);
        $this->actingAs($outsider)->withSession(['password_hash_web' => $outsider->password]);
        $this->get('/api/prototype/boq/'.$p['id'].'/source')->assertNotFound();
        $p['id'] = $p['rootId'] = 'pp-other-user';
        $p['branch'] = 'PEKALONGAN';
        $this->api($p)->assertForbidden();
        $p['boqUpload'] = null;
        $p['_boqImport'] = ['source' => $import['source'], 'rows' => $import['rows']];
        $this->api($p)->assertOk()->assertJsonMissingPath('state.projects.0.boqUpload');
    }

    public function test_explicit_partial_draft_assignments_still_require_the_correct_active_role_and_branch(): void
    {
        $p = $this->project($this->upload()->assertOk()->json());
        $pm = $this->asRole('PM Konstruksi');
        $this->asRole('Admin Konstruksi');
        $p['approverUserIds'] = ['PM Konstruksi' => 'db-user-'.$pm->getKey(), 'Admin Area' => ''];
        $this->api($p)->assertOk();
        $p['approverUserIds']['Head of PMO'] = 'db-user-'.$pm->getKey();
        $this->api($p)->assertUnprocessable();
        $this->assertDatabaseCount('prototype_audits', 1);
    }
}
