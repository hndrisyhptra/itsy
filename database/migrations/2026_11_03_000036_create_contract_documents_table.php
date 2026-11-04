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
        Schema::create('contract_documents', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_contract_document', 36)->primary();
            $table->char('contract_id', 36);
            $table->string('document_type', 10);
            $table->integer('version_no')->unsigned();
            $table->char('file_id', 36)->nullable();
            $table->string('evidence_reference', 500)->nullable();
            $table->bigInteger('attested_by')->unsigned()->nullable();
            $table->dateTime('attested_at', 6)->nullable();
            $table->unique(['contract_id', 'document_type', 'version_no'], 'a3_uq_027');
            $table->foreign(['contract_id'], 'a3_fk_062')->references(['id_contract'])->on('contracts');
            $table->foreign(['file_id'], 'a3_fk_063')->references(['id_document_file'])->on('document_files');
            $table->foreign(['attested_by'], 'a3_fk_064')->references(['id_user'])->on('users');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `contract_documents` ADD CONSTRAINT `contract_document_kind` CHECK (document_type IN (\'BAKH\',\'SPK\'))');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('contract_documents');
    }
};
