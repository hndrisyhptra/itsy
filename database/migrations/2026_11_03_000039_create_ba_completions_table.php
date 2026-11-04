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
        Schema::create('ba_completions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_ba_pp', 36)->primary();
            $table->char('final_term_id', 36)->unique();
            $table->string('document_no', 150)->unique();
            $table->string('status', 15)->default('DRAFT');
            $table->string('evidence_reference', 500)->nullable();
            $table->char('file_id', 36)->nullable();
            $table->bigInteger('attested_by')->unsigned()->nullable();
            $table->dateTime('attested_at', 6)->nullable();
            $table->foreign(['final_term_id'], 'a3_fk_070')->references(['id_payment_term'])->on('payment_terms');
            $table->foreign(['file_id'], 'a3_fk_071')->references(['id_document_file'])->on('document_files');
            $table->foreign(['attested_by'], 'a3_fk_072')->references(['id_user'])->on('users');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `ba_completions` ADD CONSTRAINT `completion_status` CHECK (status IN (\'DRAFT\',\'ATTESTED\'))');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('ba_completions');
    }
};
