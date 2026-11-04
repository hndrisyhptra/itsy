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
        Schema::create('contracts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_contract', 36)->primary();
            $table->char('pp_id', 36);
            $table->string('mandor_name', 150);
            $table->text('mandor_address');
            $table->string('bakh_no', 150)->nullable()->unique();
            $table->string('spk_no', 150)->nullable()->unique();
            $table->string('stage', 30)->default('NEGOTIATION');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('supervisor', 150);
            $table->string('supervisor_phone', 40);
            $table->string('signer_name', 150);
            $table->string('signer_position', 150);
            $table->dateTime('issued_at', 6)->nullable();
            $table->foreign(['pp_id'], 'a3_fk_035')->references(['id_pp'])->on('project_plans');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `contracts` ADD CONSTRAINT `contract_period` CHECK (end_date >= start_date)');
            DB::statement('ALTER TABLE `contracts` ADD CONSTRAINT `contract_stage` CHECK (stage IN (\'NEGOTIATION\',\'BAKH_ISSUED\',\'BAKH_ATTESTED\',\'SPK_DRAFT\',\'SPK_ISSUED\',\'SPK_ATTESTED\'))');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('contracts');
    }
};
