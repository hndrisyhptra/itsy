<?php

namespace Tests\Feature;

use App\Services\BudgetPidWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;
use ZipArchive;

class BudgetPidMasterTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->asRole();
    }

    private function workbook(int $count = 1, ?callable $alter = null, int $release = 1000000): string
    {
        $headers = ['.', 'Level', 'Description', 'Title', 'ProCstPlan', 'ProCstPlan_2_', 'Budget', 'Budget_2_', 'Release', 'Release_2_', 'Tot.cst.cm', 'Tot.cst.cm_2_', 'Act.costs', 'Act.costs_2_', 'Act.revs', 'Act.revs_2_', 'Status', 'PID_Proact', 'Start date', 'Basic fin.'];
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        $n = 0;
        $append = function (array $values) use (&$xml, &$n) {
            $n++;
            $xml .= '<row r="'.$n.'">';
            foreach ($values as $c => $v) {
                $xml .= '<c r="'.chr(65 + $c).$n.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        };
        $append($headers);
        for ($i = 1; $i <= $count; $i++) {
            $pid = '26KT03R501-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $append(['', '02', 'Kegiatan '.$i.' & uji ⁠format', $pid, 0, 'IDR', 0, 'IDR', $release, 'IDR', 100000, 'IDR', 200000, 'IDR']);
            foreach (BudgetPidWorkbook::CATEGORIES as $c => $category) {
                $append(['', '04', $category, $pid.'-01-0'.($c + 1), 0, 'IDR', 0, 'IDR', 200000, 'IDR', 20000, 'IDR', 40000, 'IDR']);
            }
        }
        $xml .= '</sheetData></worksheet>';
        if ($alter) {
            $xml = $alter($xml);
        }
        $path = tempnam(sys_get_temp_dir(), 'area3-budget-');
        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::OVERWRITE);
            $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
            $zip->close();

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    private function send(string $bytes, string $unit = 'Konstruksi', ?array $preview = null, ?string $uuid = null)
    {
        $fields = ['file' => UploadedFile::fake()->createWithContent('master-PID.xlsx', $bytes), 'unit' => $unit, 'stage' => $preview ? 'commit' : 'preview'];
        if ($preview) {
            $fields += ['version' => (string) $preview['version'], 'preview_hash' => $preview['preview_hash'], 'request_id' => $uuid ?? (string) Str::uuid()];
        }

        return $this->post('/api/budgeting-pid/import', $fields, ['Accept' => 'application/json']);
    }

    public function test_preview_is_read_only_commit_preserves_ids_history_and_no_op_import_is_visible(): void
    {
        $bytes = $this->workbook();
        $preview = $this->send($bytes)->assertOk()->assertJsonPath('summary.created', 1)->assertJsonPath('rows.0.available.TOTAL', 700000)->json();
        $this->assertDatabaseCount('budget_pids', 0);
        $this->assertDatabaseCount('budget_pid_imports', 0);
        $uuid = (string) Str::uuid();
        $this->send($bytes, 'Konstruksi', $preview, $uuid)->assertOk()->assertJsonPath('version', 1);
        $before = DB::table('budget_pids')->first();
        $ids = DB::table('budget_pid_items')->orderBy('category')->pluck('id_budget_item')->all();
        $this->assertDatabaseCount('budget_pid_items', 6);
        $this->send($bytes, 'Konstruksi', $preview, $uuid)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('budget_pid_imports', 1);
        $branch = (int) DB::table('branches')->value('id_branch');
        $this->postJson('/api/budgeting-pid/branch', ['unit' => 'Konstruksi', 'ids' => [$before->id_budget_pid], 'branch_id' => $branch, 'version' => 1, 'request_id' => (string) Str::uuid()])->assertOk();
        $changed = $this->workbook(release: 1200000);
        $p = $this->send($changed)->assertOk()->assertJsonPath('summary.updated', 1)->json();
        $this->send($changed, 'Konstruksi', $p)->assertOk();
        $this->assertSame($before->id_budget_pid, DB::table('budget_pids')->value('id_budget_pid'));
        $this->assertEquals($branch, DB::table('budget_pids')->value('branch_id'), 'Reimport must retain the explicit mapping.');
        $this->assertSame($ids, DB::table('budget_pid_items')->orderBy('category')->pluck('id_budget_item')->all());
        $this->assertDatabaseCount('budget_pid_changes', 2);
        $change = DB::table('budget_pid_changes')->where('action', 'updated')->first();
        $this->assertSame(700000, json_decode($change->before_data, true)['items']['TOTAL']['available']);
        $this->assertSame(900000, json_decode($change->after_data, true)['items']['TOTAL']['available']);
        $same = $this->send($changed)->assertOk()->assertJsonPath('summary.unchanged', 1)->json();
        $this->send($changed, 'Konstruksi', $same)->assertOk();
        $this->assertDatabaseCount('budget_pid_changes', 2);
        $this->getJson('/api/budgeting-pid?unit=Konstruksi&history=1')->assertOk()->assertJsonCount(3, 'rows')->assertJsonPath('rows.0.summary.unchanged', 1);
        $this->getJson('/api/budgeting-pid?unit=Konstruksi&history=1&import_id='.$change->import_id)->assertOk()->assertJsonPath('rows.0.before_data.items.TOTAL.available', 700000);
    }

    public function test_finance_and_superadmin_only_and_unit_restrictions_are_rechecked_on_commit(): void
    {
        $bytes = $this->workbook();
        foreach (['Admin Konstruksi', 'PM Konstruksi', 'Mgr Finance & Settlement', 'Admin Operation'] as $role) {
            $this->asRole($role);
            $this->getJson('/api/budgeting-pid?unit=Konstruksi')->assertForbidden();
            $this->send($bytes)->assertForbidden();
        }
        $finance = $this->asRole('Finance');
        $preview = $this->send($bytes, 'Operation')->assertOk()->json();
        $unitId = DB::table('work_units')->where('code', 'KONSTRUKSI')->value('id_work_unit');
        DB::table('users')->where('id_user', $finance->getKey())->update(['work_unit_id' => $unitId]);
        $finance->refresh();
        $this->send($bytes, 'Operation', $preview)->assertForbidden();
        $this->asRole();
        $preview = $this->send($bytes, 'Operation')->assertOk()->json();
        $this->send($bytes, 'Operation', $preview)->assertOk();
        $this->getJson('/api/budgeting-pid?unit=Konstruksi')->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson('/api/budgeting-pid?unit=Operation')->assertOk()->assertJsonCount(1, 'rows');
        $this->send($bytes)->assertUnprocessable();
        $this->assertDatabaseCount('budget_pids', 1);
        $this->asRole('Finance');
        $newBytes = $this->workbook(alter: fn ($xml) => str_replace('26KT03R501-0001', '26KT03R501-9999', $xml));
        $p = $this->send($newBytes)->assertOk()->json();
        $this->send($newBytes, 'Konstruksi', $p)->assertOk();
        $this->assertDatabaseCount('budget_pids', 2);
        $this->post('/logout');
        $this->getJson('/api/budgeting-pid?unit=Operation')->assertUnauthorized();
    }

    public function test_stale_preview_file_unit_actor_and_uuid_cannot_overwrite_or_replay_different_import(): void
    {
        $bytes = $this->workbook();
        $p = $this->send($bytes)->assertOk()->json();
        $uuid = (string) Str::uuid();
        $this->send($bytes, 'Operation', $p, $uuid)->assertConflict();
        $this->send($this->workbook(release: 2), 'Konstruksi', $p, $uuid)->assertConflict();
        $this->asRole('Finance');
        $this->send($bytes, 'Konstruksi', $p, $uuid)->assertConflict();
        $this->asRole();
        $this->send($bytes, 'Konstruksi', $p, $uuid)->assertOk();
        $this->send($bytes, 'Konstruksi', $p)->assertConflict();
        $this->send($this->workbook(release: 3), 'Konstruksi', $p, $uuid)->assertConflict();
        $this->assertDatabaseCount('budget_pid_imports', 1);
    }

    public function test_full_file_5192_pids_batched_writes_and_lists_do_not_read_workflow_or_issue_per_row_queries(): void
    {
        $bytes = $this->workbook(5192);
        DB::enableQueryLog();
        $p = $this->send($bytes)->assertOk()->assertJsonCount(5192, 'rows')->json();
        $previewQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->send($bytes, 'Konstruksi', $p)->assertOk()->assertJsonPath('summary.created', 5192);
        $queries = DB::getQueryLog();
        $this->assertLessThan(800, count($queries));
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/prototype_workspaces|project_plans|payment_terms|approval_decisions|volume_balances/i', $query['query']);
        }
        $this->assertDatabaseCount('budget_pids', 5192);
        $this->assertDatabaseCount('budget_pid_items', 31152);
        $this->assertDatabaseCount('budget_pid_changes', 5192);
        DB::flushQueryLog();
        $this->getJson('/api/budgeting-pid?unit=Konstruksi')->assertOk()->assertJsonCount(20, 'rows')->assertJsonPath('has_more', true);
        $listQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();
        fwrite(STDOUT, "\nBudget PID 5192: preview {$previewQueries} HTTP queries; commit ".count($queries)."; list {$listQueries}.\n");
        $this->assertLessThanOrEqual(8, $listQueries);
    }

    public function test_migration_uses_named_unique_ids_and_rollback_refuses_imported_history(): void
    {
        foreach (['budget_pid_controls' => 'id_budget_control', 'budget_pid_imports' => 'id_budget_import', 'budget_pids' => 'id_budget_pid', 'budget_pid_items' => 'id_budget_item', 'budget_pid_changes' => 'id_budget_change'] as $table => $key) {
            $this->assertTrue(Schema::hasColumn($table, $key));
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true);
            $this->assertSame([$key], $primary['columns']);
        }
        $bytes = $this->workbook();
        $p = $this->send($bytes)->assertOk()->json();
        $this->send($bytes, 'Konstruksi', $p)->assertOk();
        $migration = require database_path('migrations/2026_11_24_000001_create_budgeting_pid_master.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Rollback ditolak');
        $migration->down();
    }

    public function test_partial_updates_retain_absent_pids_literal_search_and_history_cannot_cross_unit(): void
    {
        $bytes = $this->workbook(2);
        $p = $this->send($bytes)->assertOk()->json();
        $this->send($bytes, 'Konstruksi', $p)->assertOk();
        $other = DB::table('budget_pids')->where('pid', '26KT03R501-0002')->first();
        $bytes = $this->workbook(release: 2000000);
        $p = $this->send($bytes)->assertOk()->json();
        $this->send($bytes, 'Konstruksi', $p)->assertOk();
        $this->assertDatabaseCount('budget_pids', 2);
        $this->assertEquals($other, DB::table('budget_pids')->where('pid', '26KT03R501-0002')->first());
        $this->getJson('/api/budgeting-pid?unit=Konstruksi&q=0002')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.pid', '26KT03R501-0002');
        $this->getJson('/api/budgeting-pid?unit=Konstruksi&q=%25')->assertOk()->assertJsonCount(0, 'rows');
        $bytes = $this->workbook(alter: fn ($xml) => str_replace('26KT03R501-0001', '26KT03R501-9999', $xml));
        $p = $this->send($bytes, 'Operation')->assertOk()->json();
        $id = $this->send($bytes, 'Operation', $p)->assertOk()->json('import_id');
        $this->getJson('/api/budgeting-pid?unit=Konstruksi&history=1&import_id='.$id)->assertNotFound();
        $this->getJson('/api/budgeting-pid?unit=Operation&history=1&import_id='.$id)->assertOk()->assertJsonCount(1, 'rows');
    }

    public function test_negative_values_and_parent_discrepancy_are_preserved_and_source_formulas_require_numeric_cache(): void
    {
        $bytes = $this->workbook(release: 0);
        $p = $this->send($bytes)->assertOk()->assertJsonPath('summary.warnings', 1)->assertJsonPath('rows.0.available.TOTAL', -300000)->json();
        $this->send($bytes, 'Konstruksi', $p)->assertOk();
        $this->assertDatabaseHas('budget_pid_items', ['category' => 'TOTAL', 'available' => -300000]);
        $cached = $this->workbook(alter: fn ($xml) => preg_replace('/<c r="I2".*?<\/c>/', '<c r="I2"><f>1+1</f><v>1000000</v></c>', $xml));
        $this->send($cached)->assertOk();
        // Children may precede parents: join by Title, never nearest row.
        $reordered = $this->workbook(alter: function ($xml) {
            preg_match('/<row r="2">.*?<\/row>/', $xml, $m);

            return str_replace('</sheetData>', $m[0].'</sheetData>', str_replace($m[0], '', $xml));
        });
        $this->send($reordered)->assertOk();
    }

    public function test_bad_headers_duplicates_orphans_missing_categories_fraction_formula_xml_and_corrupt_archives_fail_closed(): void
    {
        $edits = [
            fn ($s) => str_replace('Tot.cst.cm', 'Wrong', $s),
            fn ($s) => str_replace('26KT03R501-0001-01-01', 'OTHER-01-01', $s),
            fn ($s) => str_replace('<t>Non Material</t>', '<t>Material</t>', $s),
            fn ($s) => preg_replace('/<row r="7">.*?<\/row>/', '', $s),
            fn ($s) => preg_replace('/<c r="I2".*?<\/c>/', '<c r="I2"><v>1.5</v></c>', $s),
            fn ($s) => preg_replace('/<c r="I2".*?<\/c>/', '<c r="I2"><f>1+1</f><v/></c>', $s),
            fn ($s) => preg_replace('/<c r="D2".*?<\/c>/', '<c r="D2" t="str"><f>1+1</f><v>PID</v></c>', $s),
            fn ($s) => str_replace('<worksheet ', '<!DOCTYPE worksheet [<!ENTITY x SYSTEM "file:///etc/passwd">]><worksheet ', $s),
            fn ($s) => str_replace('</sheetData></worksheet>', '', $s),
        ];
        foreach ($edits as $edit) {
            $this->send($this->workbook(alter: $edit))->assertUnprocessable()->assertJsonValidationErrors('file');
        }
        $this->send('not a zip')->assertUnprocessable();
        $this->assertDatabaseCount('budget_pids', 0);
        $this->assertDatabaseCount('budget_pid_imports', 0);
    }

    public function test_commit_sql_failure_rolls_back_whole_import_and_existing_workflow_is_untouched(): void
    {
        $this->seedWorkflow('E');
        $tables = ['prototype_workspaces', 'project_plans', 'contracts', 'payment_terms', 'approval_decisions', 'volume_balances'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $bytes = $this->workbook();
        $p = $this->send($bytes)->assertOk()->json();
        DB::unprepared("CREATE TRIGGER fail_budget BEFORE INSERT ON budget_pid_changes BEGIN SELECT RAISE(ABORT, 'injected failure'); END");
        $this->send($bytes, 'Konstruksi', $p)->assertStatus(500);
        $this->assertDatabaseCount('budget_pids', 0);
        $this->assertDatabaseCount('budget_pid_items', 0);
        $this->assertDatabaseCount('budget_pid_imports', 0);
        $this->assertSame(0, DB::table('budget_pid_controls')->value('version'));
        DB::unprepared('DROP TRIGGER fail_budget');
        $this->send($bytes, 'Konstruksi', $p)->assertOk();
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson());
        }
        $this->app->instance('env', 'local');
        $this->send($bytes)->assertStatus(419);
    }
}
