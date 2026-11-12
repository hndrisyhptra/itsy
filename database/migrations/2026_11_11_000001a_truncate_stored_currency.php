<?php

use App\Services\TruncateStoredMoney;
use App\Support\Area3MigrationTarget;
use App\Support\WholeRupiah;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (! Schema::hasTable('currency_conversion_runs')) {
            Schema::create('currency_conversion_runs', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->uuid('id_currency_run')->primary();
                $t->string('conversion_key', 40)->unique();
                $t->json('summary');
                $t->timestamp('created_at');
            });
        }
        if (! Schema::hasTable('currency_conversion_changes')) {
            Schema::create('currency_conversion_changes', function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->uuid('id_currency_change')->primary();
                $t->uuid('run_id');
                $t->string('table_name', 80);
                $t->string('record_id', 140);
                $t->longText('before_data');
                $t->longText('after_data');
                $t->char('after_sha256', 64);
                $t->timestamp('created_at');
                $t->index(['run_id', 'table_name']);
                $t->foreign('run_id')->references('id_currency_run')->on('currency_conversion_runs');
            });
        }
        app(TruncateStoredMoney::class)->run(true);
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('currency_conversion_changes')->exists()) {
            throw new RuntimeException('Audit konversi nominal tersedia. Rollback tidak dapat mengembalikan pecahan tanpa rekonsiliasi dan cadangan.');
        }
        foreach (['project_plans', 'contracts', 'payment_terms'] as $table) {
            if (DB::table($table)->where('document_data->moneyPolicy', WholeRupiah::POLICY)->exists()) {
                throw new RuntimeException('Dokumen kebijakan pemotongan telah tersedia; rollback audit ditolak.');
            }
        }
        Schema::dropIfExists('currency_conversion_changes');
        Schema::dropIfExists('currency_conversion_runs');
    }
};
