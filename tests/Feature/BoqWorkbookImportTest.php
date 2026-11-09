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

    public function test_v3_price_differences_automatically_follow_khs_and_client_cannot_replace_source_or_canonical_prices(): void
    {
        $import = $this->upload(fn ($xml) => preg_replace('/(<c\b[^>]*\br="E75"[^>]*>\s*<v>)[^<]+/', '${1}704701', $xml))->assertOk()->json();
        $this->assertSame(704701, $import['rows'][0]['excelM']);
        $this->assertFalse($import['rows'][0]['reviewed']);
        $p = $this->project($import);
        $p['boq'][0]['excelM'] = 704700;
        $p['boq'][0]['reviewed'] = true;
        $p['boq'][0]['m'] = 1;
        $p['budget'] = 999;
        $this->api($p)->assertOk()->assertJsonPath('state.projects.0.boq.0.excelM', 704701)->assertJsonPath('state.projects.0.boq.0.reviewed', true)->assertJsonPath('state.projects.0.boq.0.m', 704700)->assertJsonPath('state.projects.0.budget', 140056789)->assertJsonPath('state.projects.0.boqPricingPolicy', 'khs-auto-v1');
        $p = $this->approvers($p);
        $p['boq'][0]['note'] = '';
        $p['boq'][0]['reviewed'] = false;
        $this->api($p, true)->assertOk()->assertJsonPath('state.projects.0.boq.0.reviewed', true)->assertJsonPath('state.projects.0.boq.0.excelM', 704701);
    }

    public function test_quantity_and_removal_recalculate_sql_header_total_without_changing_private_source(): void
    {
        $import = $this->upload()->assertOk()->json();
        $p = $this->project($import);
        $workspace = app(PrototypeWorkspaceService::class)->workspace(request());
        $directory = app(BoqImportStorage::class)->directory($workspace, $import['source']['id']);
        $manifest = Storage::disk('local')->get($directory.'/manifest.json');
        $archive = $workspace->getRawOriginal('state');
        $p['boq'][0]['qty'] = 12;
        $p['boq'][0]['m'] = 1;
        $p['budget'] = 9;
        DB::enableQueryLog();
        $p = $this->api($p)->assertOk()->assertJsonPath('state.projects.0.budget', 140761489)->json('state.projects.0');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $catalogReads = array_filter($queries, fn ($q) => str_contains($q['query'], 'from "designator_package_prices"'));
        $this->assertCount(2, $catalogReads, 'One batch KHS read per revision read/command, never one per BOQ row.');
        $this->assertLessThanOrEqual(220, count($queries), '26-component HTTP fixture including revision read and normalized SQL writes.');
        $sql = DB::table('project_plans')->where('source_id', $p['id'])->first();
        $this->assertEquals(140761489, $sql->budget);
        $this->assertEquals(140761489, json_decode($sql->document_data, true)['budget']);
        $p['boq'] = array_slice($p['boq'], 1);
        $p['planBudget']['mandor'] = array_slice($p['planBudget']['mandor'], 1);
        $this->api($p)->assertOk()->assertJsonPath('state.projects.0.budget', 132305089);
        $this->assertEquals(132305089, DB::table('project_plans')->where('source_id', $p['id'])->value('budget'));
        $this->assertSame($manifest, Storage::disk('local')->get($directory.'/manifest.json'));
        $this->assertSame(hash_file('sha256', $this->fixture()), hash('sha256', Storage::disk('local')->get($directory.'/source.xlsx')));
        $this->assertSame($archive, $workspace->fresh()->getRawOriginal('state'));
        $this->assertDatabaseCount('prototype_audits', 2);
    }

    public function test_maximum_500_component_domain_save_uses_indexed_khs_and_no_extra_sql(): void
    {
        $state = $this->getJson('/api/prototype/state')->assertOk()->json('state');
        $reference = collect($state['khs'])->firstWhere('active', true);
        $p = $this->project(['source' => ['sourceTotal' => 1], 'rows' => []]);
        unset($p['boqUpload']);
        $state['khs'] = $p['boq'] = [];
        for ($i = 0; $i < 500; $i++) {
            $state['khs'][] = array_replace($reference, ['id' => 'khs-index-'.$i, 'm' => 100, 's' => null]);
            $p['boq'][] = ['id' => 'row-index-'.$i, 'khsId' => 'khs-index-'.$i, 'qty' => 1, 'm' => 999, 's' => 999];
        }
        $domain = new class extends PrototypeDomain
        {
            public function record(string $collection, string $id): array
            {
                if ($collection === 'khs') {
                    throw new \LogicException('Repeated linear KHS lookup.');
                }

                return parent::record($collection, $id);
            }
        };
        DB::enableQueryLog();
        $result = $domain->execute($state, 'saveProject', [$p, false]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(0, $queries);
        $this->assertCount(500, $result['state']['projects'][0]['boq']);
        $this->assertEquals(50000, $result['state']['projects'][0]['budget']);
    }

    public function test_supplied_paket5_workbook_accepts_empty_opposite_component_prices(): void
    {
        $path = base_path('tests/Fixtures/boq/osp-fo-paket5-blank-prices.xlsx');
        $file = UploadedFile::fake()->createWithContent('3DMO_QERECOVERY_JL NIAS.xlsx', file_get_contents($path));
        // Reference data currently has Material Paket 5 but Jasa only Paket 10.
        // A blank price fix must not invent a missing authoritative KHS price.
        $this->post('/api/prototype/boq/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Baris 44: designator J-AC-OF-SM-24D belum memiliki harga KHS aktif pada Paket 5. Lengkapi melalui Master Designator > KHS.');
        $this->assertCount(0, Storage::disk('local')->allFiles());
        // These workbook prices are test fixtures only, never live master edits.
        foreach (['J-AC-OF-SM-24D' => 4321, 'J-ODP Solid-PB-16 AS' => 141810] as $code => $price) {
            DB::table('designator_package_prices')->insert(['designator_id' => DB::table('designators')->where('code', $code)->value('id_designator'),
                'package_id' => DB::table('packages')->where('name', 'Paket 5')->value('id_package'), 'price' => $price]);
        }
        $import = $this->post('/api/prototype/boq/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(4, 'rows')->assertJsonPath('source.sourceRows', 4)
            ->assertJsonPath('source.package', 'Paket 5')->assertJsonPath('source.sourceTotal', 15667200)->json();
        $this->assertSame([43, 44, 504, 505], array_column($import['rows'], 'sourceRow'));
        $this->assertSame([500, 500, 6, 6], array_column($import['rows'], 'sourceQty'));
        $this->assertSame(15506, $import['rows'][0]['excelM']);
        $this->assertNull($import['rows'][0]['excelS']);
        $this->assertSame(4321, $import['rows'][1]['excelS']);
        $this->assertSame(hash_file('sha256', $path), $import['source']['sha256']);
        $this->assertSame([true, true, true, true], array_column($import['rows'], 'reviewed'));
        $this->api($this->project($import))->assertOk()->assertJsonCount(4, 'state.projects.0.boq');
    }

    public function test_cached_formula_inputs_remain_comparisons_and_v3_uses_khs_without_review(): void
    {
        $import = $this->upload(function ($xml) {
            $xml = preg_replace('/<c\b[^>]*\br="G75"[^>]*>.*?<\/c>/s', '<c r="G75"><f>5+6</f><v>11</v></c>', $xml);

            // Deliberately different from the formula expression: the importer
            // reads the cache only and must never calculate this expression.
            return preg_replace('/<c\b[^>]*\br="E75"[^>]*>.*?<\/c>/s', '<c r="E75"><f>1+1</f><v>704701</v></c>', $xml);
        })->assertOk()->json();
        $this->assertSame(11, $import['rows'][0]['qty']);
        $this->assertSame(704701, $import['rows'][0]['excelM']);
        $this->assertSame(704700, $import['rows'][0]['m']);
        $this->assertFalse($import['rows'][0]['reviewed']);
        $p = $this->approvers($this->project($import));
        $this->api($p, true)->assertOk()->assertJsonPath('state.projects.0.boq.0.m', 704700)->assertJsonPath('state.projects.0.boq.0.excelM', 704701)->assertJsonPath('state.projects.0.boq.0.reviewed', true);
    }

    public function test_blank_prices_remain_zero_comparisons_without_inventing_components(): void
    {
        foreach (['', '<c r="F75"/>', '<c r="F75" t="str"><f>IF(1=1,&quot;&quot;,0)</f><v/></c>'] as $replacement) {
            $import = $this->upload(fn ($xml) => preg_replace('/<c\b[^>]*\br="F75"[^>]*>.*?<\/c>/s', $replacement, $xml))
                ->assertOk()->assertJsonCount(26, 'rows')->json();
            $service = collect($import['rows'])->first(fn ($r) => $r['sourceRow'] === 75 && $r['s'] !== null);
            $this->assertSame(0, $service['excelS']);
            $this->assertGreaterThan(0, $service['s']);
            $this->assertFalse($service['reviewed']);
        }
        $this->upload(fn ($xml) => preg_replace('/<c\b[^>]*\br="G75"[^>]*>.*?<\/c>/s', '<c r="G75" t="str"><f>IF(1=1,&quot;&quot;,0)</f><v/></c>', $xml))
            ->assertOk()->assertJsonCount(24, 'rows')->assertJsonPath('source.sourceRows', 13);
    }

    public function test_bad_structure_missing_or_invalid_formula_results_precision_unknown_designator_and_unit_are_rejected_atomically(): void
    {
        $variants = [
            fn ($x) => preg_replace('/(<c\b[^>]*\br="G75"[^>]*>\s*<v>)[^<]+/', '${1}-1', $x),
            fn ($x) => preg_replace('/(<c\b[^>]*\br="G75"[^>]*>\s*<v>)[^<]+/', '${1}0.000000001', $x),
            fn ($x) => preg_replace('/<c\b[^>]*\br="G75"[^>]*>.*?<\/c>/s', '<c r="G75"><f>5+6</f></c>', $x),
            fn ($x) => preg_replace('/<c\b[^>]*\br="G75"[^>]*>.*?<\/c>/s', '<c r="G75"><f>5+6</f><v/></c>', $x),
            fn ($x) => preg_replace('/<c\b[^>]*\br="G75"[^>]*>.*?<\/c>/s', '<c r="G75" t="str"><f>5+6</f><v>11</v></c>', $x),
            fn ($x) => preg_replace('/<c\b[^>]*\br="F75"[^>]*>.*?<\/c>/s', '<c r="F75" t="e"><f>1/0</f><v>#DIV/0!</v></c>', $x),
            fn ($x) => preg_replace('/<c\b[^>]*\br="F75"[^>]*>.*?<\/c>/s', '<c r="F75"><f>1+1</f><v/></c>', $x),
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

    public function test_khs_change_is_applied_automatically_on_explicit_v3_save_and_submission(): void
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
        $saved = $this->api($p)->assertOk()->assertJsonPath('state.projects.0.boq.0.reviewed', true)->json('state.projects.0');
        $this->assertEquals(704702, $saved['boq'][0]['m']);
        $this->assertEquals(app(PrototypeDomain::class)->boqTotal($saved), $saved['budget']);
        $saved['boq'][0]['reviewed'] = false;
        $saved['boq'][0]['note'] = '';
        $submitted = $this->api($saved, true)->assertOk()->assertJsonPath('state.projects.0.boq.0.reviewed', true)->assertJsonPath('state.projects.0.boq.0.excelM', 704700)->assertJsonPath('state.projects.0.boq.0.m', 704702)->json('state.projects.0');
        $this->asRole('Superadmin');
        $revision = $this->getJson('/api/masters')->assertOk()->json('revision');
        $this->postJson('/api/masters/khs', ['action' => 'save', 'id' => $priceId, 'data' => ['designator_id' => $source->designator_id, 'package_id' => $source->package_id, 'price' => '704703.00'],
            'revision' => $revision, 'request_id' => (string) Str::uuid()])->assertOk();
        $this->asRole('Admin Konstruksi');
        $this->getJson('/api/prototype/state')->assertOk()->assertJsonPath('state.projects.0.budget', $submitted['budget'])->assertJsonPath('state.projects.0.boq.0.m', 704702);
        $this->api($submitted)->assertUnprocessable();
        $this->assertEquals($submitted['boq'], json_decode(DB::table('project_plans')->where('source_id', $submitted['id'])->value('document_data'), true)['boq']);
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
