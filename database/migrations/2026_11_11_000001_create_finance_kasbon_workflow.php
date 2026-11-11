<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function authority(bool $new): string
    {
        return $new
            ? '(verified_scan_id IS NOT NULL AND payment_request_id IS NULL AND kasbon_id IS NULL AND transfer_file_id IS NULL) OR (verified_scan_id IS NULL AND payment_request_id IS NOT NULL AND kasbon_id IS NULL AND transfer_file_id IS NULL) OR (verified_scan_id IS NULL AND payment_request_id IS NULL AND kasbon_id IS NOT NULL AND transfer_file_id IS NOT NULL)'
            : '(verified_scan_id IS NOT NULL AND payment_request_id IS NULL) OR (verified_scan_id IS NULL AND payment_request_id IS NOT NULL)';
    }

    private function guard(bool $new): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert', 'update'] as $event) {
                DB::statement('DROP TRIGGER IF EXISTS partial_settlement_'.$event);
                $condition = str_replace(['verified_scan_id', 'payment_request_id', 'kasbon_id', 'transfer_file_id'], ['NEW.verified_scan_id', 'NEW.payment_request_id', 'NEW.kasbon_id', 'NEW.transfer_file_id'], $this->authority($new));
                DB::statement('CREATE TRIGGER partial_settlement_'.$event.' BEFORE '.strtoupper($event).' ON apm_settlements WHEN NOT ('.$condition.") BEGIN SELECT RAISE(ABORT, 'settlement_authority'); END");
            }
        } else {
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            DB::statement('ALTER TABLE apm_settlements '.(str_contains(strtolower($version), 'mariadb') ? 'DROP CONSTRAINT' : 'DROP CHECK').' settlement_authority');
            DB::statement('ALTER TABLE apm_settlements ADD CONSTRAINT settlement_authority CHECK ('.$this->authority($new).')');
        }
    }

    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::create('finance_private_files', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->uuid('id_finance_file')->primary();
            $t->foreignUuid('workspace_id')->constrained('prototype_workspaces');
            $t->char('spk_id', 36)->nullable();
            $t->char('term_id', 36)->nullable();
            $t->string('kind', 20);
            $t->string('storage_key');
            $t->string('mime', 80);
            $t->string('extension', 5);
            $t->unsignedInteger('size');
            $t->char('sha256', 64);
            $t->unsignedBigInteger('uploaded_by');
            $t->timestamp('created_at');
            $t->foreign('spk_id')->references('id_spk')->on('work_orders');
            $t->foreign('term_id')->references('id_payment_term')->on('payment_terms');
            $t->foreign('uploaded_by')->references('id_user')->on('users');
            $t->unique(['term_id', 'id_finance_file'], 'finance_file_term_identity');
            $t->index(['workspace_id', 'spk_id', 'kind', 'created_at'], 'finance_file_spk');
            $t->index(['workspace_id', 'term_id', 'kind', 'created_at'], 'finance_file_term');
        });
        Schema::create('mandor_payee_profiles', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->uuid('id_payee')->primary();
            $t->char('spk_id', 36);
            $t->unsignedInteger('version');
            $t->longText('private_data');
            $t->uuid('request_uuid')->unique();
            $t->char('input_sha256', 64);
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->foreign('spk_id')->references('id_spk')->on('work_orders');
            $t->foreign('created_by')->references('id_user')->on('users');
            $t->unique(['spk_id', 'version']);
        });
        Schema::create('finance_kasbons', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->uuid('id_kasbon')->primary();
            $t->char('term_id', 36);
            $t->unsignedInteger('version');
            $t->decimal('amount', 20, 2);
            $t->longText('private_data');
            $t->uuid('request_uuid')->unique();
            $t->char('input_sha256', 64);
            $t->unsignedBigInteger('issued_by');
            $t->timestamp('issued_at');
            $t->foreign('term_id')->references('id_payment_term')->on('payment_terms');
            $t->foreign('issued_by')->references('id_user')->on('users');
            $t->unique(['term_id', 'version']);
            $t->unique(['term_id', 'id_kasbon'], 'kasbon_term_identity');
        });
        Schema::table('upload_tasks', fn (Blueprint $t) => $t->char('context_id', 36)->nullable());
        Schema::table('apm_settlements', function (Blueprint $t) {
            $t->char('kasbon_id', 36)->nullable();
            $t->char('transfer_file_id', 36)->nullable();
            $t->foreign(['term_id', 'kasbon_id'], 'apm_kasbon_fk')->references(['term_id', 'id_kasbon'])->on('finance_kasbons');
            $t->foreign(['term_id', 'transfer_file_id'], 'apm_transfer_fk')->references(['term_id', 'id_finance_file'])->on('finance_private_files');
        });
        $this->guard(true);
        $conditions = [
            'finance_private_files' => "size > 0 AND size <= 10485760 AND ((kind IN ('mandor-ktp','mandor-bank') AND spk_id IS NOT NULL AND term_id IS NULL AND mime IN ('image/jpeg','image/png','image/webp')) OR (kind = 'transfer-proof' AND term_id IS NOT NULL AND spk_id IS NULL))",
            'finance_kasbons' => 'version > 0 AND amount > 0',
            'mandor_payee_profiles' => 'version > 0',
        ];
        foreach ($conditions as $table => $condition) {
            if (DB::getDriverName() === 'sqlite') {
                foreach (['insert', 'update'] as $event) {
                    $expression = preg_replace('/\\b(size|kind|spk_id|term_id|mime|version|amount)\\b/', 'NEW.$1', $condition);
                    DB::statement('CREATE TRIGGER '.$table.'_'.$event.' BEFORE '.strtoupper($event).' ON '.$table.' WHEN NOT ('.$expression.") BEGIN SELECT RAISE(ABORT, 'finance_values'); END");
                }
            } else {
                DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT '.$table.'_values CHECK ('.$condition.')');
            }
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        foreach (['finance_kasbons', 'finance_private_files', 'mandor_payee_profiles'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Riwayat kasbon/data pribadi tersedia; rollback ditolak.');
            }
        }
        if (DB::table('payment_terms')->where('document_data->paymentFlow', 'kasbon-v1')->exists() || DB::table('work_orders')->where('document_data->financialFlow', 'kasbon-v1')->exists()) {
            throw new RuntimeException('Dokumen alur kasbon tersedia; rollback ditolak.');
        }
        $this->guard(false);
        Schema::table('apm_settlements', function (Blueprint $t) {
            $t->dropForeign(DB::getDriverName() === 'sqlite' ? ['term_id', 'kasbon_id'] : 'apm_kasbon_fk');
            $t->dropForeign(DB::getDriverName() === 'sqlite' ? ['term_id', 'transfer_file_id'] : 'apm_transfer_fk');
            $t->dropColumn(['kasbon_id', 'transfer_file_id']);
        });
        Schema::table('upload_tasks', fn (Blueprint $t) => $t->dropColumn('context_id'));
        Schema::drop('finance_kasbons');
        Schema::drop('mandor_payee_profiles');
        Schema::drop('finance_private_files');
    }
};
