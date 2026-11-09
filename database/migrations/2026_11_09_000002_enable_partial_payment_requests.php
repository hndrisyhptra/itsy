<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function settlementCondition(): string
    {
        return '(verified_scan_id IS NOT NULL AND payment_request_id IS NULL) OR (verified_scan_id IS NULL AND payment_request_id IS NOT NULL)';
    }

    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::create('partial_payment_requests', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->char('id_payment_request', 36)->primary();
            $t->char('term_id', 36);
            $t->unsignedInteger('cycle_no');
            $t->string('status', 20);
            $t->decimal('amount', 20, 2);
            $t->unsignedBigInteger('requested_by');
            $t->dateTime('requested_at', 6);
            $t->string('documents_at_request', 3);
            $t->string('documents_at_payment', 3)->nullable();
            $t->unsignedBigInteger('finance_user_id')->nullable();
            $t->dateTime('finance_decided_at', 6)->nullable();
            $t->text('finance_note')->nullable();
            $t->json('request_data');
            $t->foreign('term_id')->references('id_payment_term')->on('payment_terms');
            $t->foreign('requested_by')->references('id_user')->on('users');
            $t->foreign('finance_user_id')->references('id_user')->on('users');
            $t->unique(['term_id', 'cycle_no'], 'partial_payment_cycle');
            $t->unique(['term_id', 'id_payment_request'], 'partial_payment_term_identity');
            $t->index(['status', 'requested_at'], 'partial_payment_status');
        });
        Schema::table('apm_settlements', function (Blueprint $t) {
            $t->char('verified_scan_id', 36)->nullable()->change();
            $t->char('payment_request_id', 36)->nullable();
            $t->foreign(['term_id', 'payment_request_id'], 'apm_partial_request_fk')->references(['term_id', 'id_payment_request'])->on('partial_payment_requests');
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE apm_settlements ADD CONSTRAINT settlement_authority CHECK ('.$this->settlementCondition().')');
            DB::statement("ALTER TABLE partial_payment_requests ADD CONSTRAINT partial_payment_values CHECK (amount > 0 AND cycle_no > 0 AND status IN ('WAITING','VERIFIED','RETURNED','PAID') AND documents_at_request IN ('OK','NOK') AND (documents_at_payment IS NULL OR documents_at_payment IN ('OK','NOK')))");
        } else {
            // Equivalent guard for executable memory tests; base SQLite omits MySQL CHECK additions.
            $condition = str_replace(['verified_scan_id', 'payment_request_id'], ['NEW.verified_scan_id', 'NEW.payment_request_id'], $this->settlementCondition());
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement('CREATE TRIGGER partial_settlement_'.strtolower($event).' BEFORE '.$event.' ON apm_settlements WHEN NOT ('.$condition.") BEGIN SELECT RAISE(ABORT, 'settlement_authority'); END");
            }
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('partial_payment_requests')->exists() || DB::table('apm_settlements')->whereNull('verified_scan_id')->exists()) {
            throw new RuntimeException('Riwayat Payment tanpa scan tersedia; rollback ditolak agar pembayaran tetap utuh.');
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            DB::statement('ALTER TABLE apm_settlements '.(str_contains(strtolower($version), 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK').' settlement_authority');
        } else {
            DB::statement('DROP TRIGGER IF EXISTS partial_settlement_insert');
            DB::statement('DROP TRIGGER IF EXISTS partial_settlement_update');
        }
        Schema::table('apm_settlements', function (Blueprint $t) {
            $t->dropForeign(DB::getDriverName() === 'sqlite' ? ['term_id', 'payment_request_id'] : 'apm_partial_request_fk');
            $t->dropColumn('payment_request_id');
            $t->char('verified_scan_id', 36)->nullable(false)->change();
        });
        Schema::dropIfExists('partial_payment_requests');
    }
};
