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
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_payment_term', 36)->primary();
            $table->char('contract_id', 36);
            $table->tinyInteger('term_no')->unsigned();
            $table->string('term_type', 10);
            $table->string('status', 20)->default('DRAFT');
            $table->bigInteger('pm_user_id')->unsigned();
            $table->bigInteger('manager_user_id')->unsigned();
            $table->decimal('approved_amount', 20, 2)->nullable();
            $table->dateTime('submitted_at', 6)->nullable();
            $table->dateTime('approved_at', 6)->nullable();
            $table->bigInteger('revision')->unsigned()->default(0);
            $table->smallInteger('workflow_version')->unsigned()->default(2);
            $table->unique(['contract_id', 'term_no'], 'a3_uq_020');
            $table->foreign(['contract_id'], 'a3_fk_038')->references(['id_contract'])->on('contracts');
            $table->foreign(['pm_user_id'], 'a3_fk_039')->references(['id_user'])->on('users');
            $table->foreign(['manager_user_id'], 'a3_fk_040')->references(['id_user'])->on('users');
            $table->index(['pm_user_id', 'status'], 'a3_ix_032');
            $table->index(['manager_user_id', 'status'], 'a3_ix_033');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `payment_terms` ADD CONSTRAINT `term_sequence` CHECK (term_no BETWEEN 1 AND 3)');
            DB::statement('ALTER TABLE `payment_terms` ADD CONSTRAINT `term_kind` CHECK (term_type IN (\'PARTIAL\',\'FINAL\') AND (term_no < 3 OR term_type = \'FINAL\'))');
            DB::statement('ALTER TABLE `payment_terms` ADD CONSTRAINT `term_status` CHECK (status IN (\'DRAFT\',\'SUBMITTED\',\'RETURNED\',\'REJECTED\',\'APPROVED\'))');
            DB::statement('ALTER TABLE `payment_terms` ADD CONSTRAINT `term_amount` CHECK (approved_amount IS NULL OR approved_amount > 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('payment_terms');
    }
};
