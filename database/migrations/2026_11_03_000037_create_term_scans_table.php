<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::create('term_scans', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_term_scan', 36)->primary();
            $table->char('term_id', 36);
            $table->char('file_id', 36)->unique();
            $table->integer('version_no')->unsigned();
            $table->string('status', 20)->default('WAITING');
            $table->bigInteger('verified_by')->unsigned()->nullable();
            $table->dateTime('verified_at', 6)->nullable();
            $table->text('note');
            $table->unique(['term_id', 'version_no'], 'a3_uq_028');
            $table->unique(['term_id', 'id_term_scan'], 'a3_uq_029');
            $table->foreign(['term_id'], 'a3_fk_065')->references(['id_payment_term'])->on('payment_terms');
            $table->foreign(['file_id'], 'a3_fk_066')->references(['id_document_file'])->on('document_files');
            $table->foreign(['verified_by'], 'a3_fk_067')->references(['id_user'])->on('users');
            $table->index(['status', 'term_id'], 'a3_ix_037');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `term_scans` ADD CONSTRAINT `scan_status` CHECK (status IN (\'WAITING\',\'VALID\',\'CORRECTION\'))');
            DB::statement('ALTER TABLE `term_scans` ADD CONSTRAINT `scan_verifier` CHECK (status = \'WAITING\' OR (verified_by IS NOT NULL AND verified_at IS NOT NULL))');
            DB::statement('ALTER TABLE `term_scans` ADD CONSTRAINT `scan_correction_reason` CHECK (status <> \'CORRECTION\' OR CHAR_LENGTH(TRIM(note)) > 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('term_scans');
    }
};
