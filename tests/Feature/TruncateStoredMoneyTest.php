<?php

namespace Tests\Feature;

use App\Models\PrototypeWorkspace;
use App\Services\RelationalWorkflowStore;
use App\Services\TruncateStoredMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesArea3;
use Tests\TestCase;

class TruncateStoredMoneyTest extends TestCase
{
    use AuthenticatesArea3, RefreshDatabase;

    private function source(): array
    {
        $s = $this->seedWorkflow('D');
        $s['projects'][0]['budget'] = 100000.99;
        $s['projects'][0]['costs']['permit'] = 15286.99;
        $s['projects'][0]['boq'][0]['m'] = 10000.99;
        $s['deals'][0]['rows'][0]['pm'] = 8500.99;
        $s['deals'][0]['rows'][0]['sphM'] = 8000.99;
        $s['deals'][0]['rows'][0]['capM'] = 10000.99;
        DB::transaction(fn () => app(RelationalWorkflowStore::class)->write(PrototypeWorkspace::firstOrFail(), $s));
        DB::table('currency_conversion_runs')->delete(); // Only the empty fresh-migration marker, on isolated SQLite.

        return $s;
    }

    public function test_conversion_updates_sql_and_snapshots_preserves_archive_identity_quantity_and_audits_original(): void
    {
        $s = $this->source();
        $w = PrototypeWorkspace::firstOrFail();
        $archive = $w->getRawOriginal('state');
        $revision = $w->revision;
        $id = DB::table('designator_package_prices')->value('id_designator_package_price');
        DB::table('designator_package_prices')->where('id_designator_package_price', $id)->update(['price' => 140960.99]);
        $decisions = DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->toJson();
        $service = app(TruncateStoredMoney::class);
        DB::enableQueryLog();
        $preview = $service->run();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertGreaterThan(0, $preview['changedRows']);
        $this->assertEmpty(array_filter($queries, fn ($q) => preg_match('/^\s*(insert|update|delete|alter)\b/i', $q['query'])
            || (str_contains($q['query'], 'prototype_workspaces') && preg_match('/select\s+\*|["`]state["`]/i', $q['query']))));
        $this->assertEquals(140960.99, DB::table('designator_package_prices')->where('id_designator_package_price', $id)->value('price'));
        $service->run(true);
        $saved = app(RelationalWorkflowStore::class)->read($w->fresh());
        $this->assertEquals(100000, $saved['projects'][0]['budget']);
        $this->assertEquals(15286, $saved['projects'][0]['costs']['permit']);
        $this->assertEquals(10000, $saved['projects'][0]['boq'][0]['m']);
        $this->assertSame($s['projects'][0]['boq'][0]['qty'], $saved['projects'][0]['boq'][0]['qty']);
        $this->assertSame($s['projects'][0]['boq'][0]['excelM'], $saved['projects'][0]['boq'][0]['excelM']);
        $this->assertSame($s['deals'][0]['no'], $saved['deals'][0]['no']);
        $this->assertEquals(8500, $saved['deals'][0]['rows'][0]['pm']);
        $this->assertEquals(140960, DB::table('designator_package_prices')->where('id_designator_package_price', $id)->value('price'));
        $this->assertSame($archive, $w->fresh()->getRawOriginal('state'));
        $this->assertSame($revision + 1, $w->fresh()->revision);
        $this->assertSame($decisions, DB::table('approval_decisions')->orderBy('id_approval_decision')->get()->toJson());
        $audit = DB::table('currency_conversion_changes')->where('table_name', 'designator_package_prices')->where('record_id', (string) $id)->first();
        $this->assertEquals(140960.99, json_decode(Crypt::decryptString($audit->before_data), true)['price']);
        $this->assertEquals(140960, json_decode(Crypt::decryptString($audit->after_data), true)['price']);
        $this->assertStringNotContainsString('140960.99', $audit->before_data);
        $count = DB::table('currency_conversion_changes')->count();
        $service->run(true);
        $this->assertSame($count, DB::table('currency_conversion_changes')->count());
        $this->assertSame($revision + 1, $w->fresh()->revision);
    }

    public function test_whitelisted_financial_fields_cut_toward_zero_but_identifiers_volumes_dates_and_percentages_do_not(): void
    {
        $service = app(TruncateStoredMoney::class);
        $private = ['amount' => 15286.99, 'rows' => [['amount' => 15286.99, 'accountCode' => '000123']], 'payee' => ['nik' => '0011223344556677', 'accountNumber' => '0001234567'], 'date' => '2026-11-12'];
        $result = $service->document($private, 'kasbon');
        $this->assertEquals(15286, $result['amount']);
        $this->assertEquals(15286, $result['rows'][0]['amount']);
        $this->assertSame($private['payee'], $result['payee']);
        $this->assertSame('000123', $result['rows'][0]['accountCode']);
        $this->assertSame($private['date'], $result['date']);
        $row = $service->document(['qty' => .999, 'price' => 99.999, 'total' => -99.99, 'amount' => 0, 'id' => '00099'], 'budget-row');
        $this->assertSame(.999, $row['qty']);
        $this->assertEquals(99, $row['price']);
        $this->assertEquals(-99, $row['total']);
        $this->assertSame('00099', $row['id']);
    }

    public function test_sub_rupiah_settlement_conflict_stops_before_any_write(): void
    {
        $s = $this->seedWorkflow('E');
        DB::table('currency_conversion_runs')->delete();
        DB::table('finance_kasbons')->insert(['id_kasbon' => (string) Str::uuid(), 'term_id' => DB::table('payment_terms')->value('id_payment_term'), 'version' => 1, 'amount' => .99, 'private_data' => Crypt::encryptString(json_encode(['amount' => .99, 'rows' => [['amount' => .99]]])), 'request_uuid' => (string) Str::uuid(), 'input_sha256' => str_repeat('a', 64), 'issued_by' => auth()->id(), 'issued_at' => now()]);
        $before = DB::table('project_plans')->pluck('document_data', 'id_pp')->all();
        try {
            app(TruncateStoredMoney::class)->run(true);
            $this->fail('Positive settlement cannot be erased by truncating to zero.');
        } catch (\RuntimeException$e) {
            $this->assertStringContainsString('kurang dari Rp1', $e->getMessage());
        }
        $this->assertSame($before, DB::table('project_plans')->pluck('document_data', 'id_pp')->all());
        $this->assertSame(0, DB::table('currency_conversion_changes')->count());
        $this->assertSame(0, DB::table('currency_conversion_runs')->count());
    }

    public function test_data_migration_works_after_type_migration_and_cannot_discard_audit_on_rollback(): void
    {
        $this->source();
        $migration = require database_path('migrations/2026_11_11_000001a_truncate_stored_currency.php');
        $migration->up();
        $this->assertGreaterThan(0, DB::table('currency_conversion_changes')->count());
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_late_sql_failure_rolls_back_snapshots_audits_and_revision_then_can_retry(): void
    {
        $this->source();
        $w = PrototypeWorkspace::firstOrFail();
        $revision = $w->revision;
        $before = DB::table('project_plans')->pluck('document_data', 'id_pp')->all();
        $id = DB::table('designator_package_prices')->value('id_designator_package_price');
        DB::table('designator_package_prices')->where('id_designator_package_price', $id)->update(['price' => 15286.99]);
        $fail = true;
        DB::listen(function ($event) use (&$fail) {
            if ($fail && str_contains(strtolower($event->sql), 'update "designator_package_prices"')) {
                $fail = false;
                throw new \RuntimeException('Fixture late SQL failure');
            }
        });
        try {
            app(TruncateStoredMoney::class)->run(true);
            $this->fail('Expected fixture failure.');
        } catch (\RuntimeException$e) {
            $this->assertSame('Fixture late SQL failure', $e->getMessage());
        }
        $this->assertSame($before, DB::table('project_plans')->pluck('document_data', 'id_pp')->all());
        $this->assertSame($revision, $w->fresh()->revision);
        $this->assertSame(0, DB::table('currency_conversion_changes')->count());
        $this->assertSame(0, DB::table('currency_conversion_runs')->count());
        app(TruncateStoredMoney::class)->run(true);
        $this->assertEquals(15286, DB::table('designator_package_prices')->where('id_designator_package_price', $id)->value('price'));
    }
}
