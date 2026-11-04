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
        Schema::create('boq_import_batches', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_boq_import', 36)->primary();
            $table->char('draft_id', 36);
            $table->char('pp_id', 36)->nullable();
            $table->bigInteger('template_id')->unsigned();
            $table->char('file_id', 36)->unique();
            $table->bigInteger('khs_price_list_id')->unsigned();
            $table->string('status', 20)->default('UPLOADED');
            $table->bigInteger('uploaded_by')->unsigned();
            $table->dateTime('uploaded_at', 6);
            $table->bigInteger('validated_by')->unsigned()->nullable();
            $table->dateTime('validated_at', 6)->nullable();
            $table->dateTime('applied_at', 6)->nullable();
            $table->foreign(['pp_id'], 'a3_fk_053')->references(['id_pp'])->on('project_plans');
            $table->foreign(['template_id'], 'a3_fk_054')->references(['id_boq_import_template'])->on('boq_import_templates');
            $table->foreign(['file_id'], 'a3_fk_055')->references(['id_document_file'])->on('document_files');
            $table->foreign(['khs_price_list_id'], 'a3_fk_056')->references(['id_khs_price_list'])->on('khs_price_lists');
            $table->foreign(['uploaded_by'], 'a3_fk_057')->references(['id_user'])->on('users');
            $table->foreign(['validated_by'], 'a3_fk_058')->references(['id_user'])->on('users');
            $table->index(['uploaded_by', 'draft_id', 'status'], 'a3_ix_035');
            $table->index(['pp_id', 'uploaded_at'], 'a3_ix_036');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `boq_import_batches` ADD CONSTRAINT `boq_import_status` CHECK (status IN (\'UPLOADED\',\'PARSED\',\'REVIEW\',\'VALIDATED\',\'APPLIED\',\'REJECTED\',\'SUPERSEDED\'))');
            DB::statement('ALTER TABLE `boq_import_batches` ADD CONSTRAINT `boq_import_validation` CHECK (status NOT IN (\'VALIDATED\',\'APPLIED\') OR (validated_by IS NOT NULL AND validated_at IS NOT NULL))');
            DB::statement('ALTER TABLE `boq_import_batches` ADD CONSTRAINT `boq_import_applied` CHECK (status <> \'APPLIED\' OR (pp_id IS NOT NULL AND applied_at IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('boq_import_batches');
    }
};
