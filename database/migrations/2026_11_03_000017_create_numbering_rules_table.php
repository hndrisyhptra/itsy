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
        Schema::create('numbering_rules', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_numbering_rule');
            $table->string('document_type', 30)->unique();
            $table->string('pattern', 100)->default('{SEQ}/LG.000/TA-03{BRANCH}/{MM}-{YYYY}');
            $table->tinyInteger('sequence_padding')->unsigned()->default(4);
            $table->string('timezone_name', 60)->default('Asia/Jakarta');
            $table->string('sequence_scope', 20)->default('GLOBAL');
            $table->bigInteger('updated_by')->unsigned();
            $table->dateTime('updated_at', 6);
            $table->foreign(['updated_by'], 'a3_fk_019')->references(['id_user'])->on('users');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `numbering_rules` ADD CONSTRAINT `numbering_padding` CHECK (sequence_padding BETWEEN 1 AND 12)');
            DB::statement('ALTER TABLE `numbering_rules` ADD CONSTRAINT `numbering_scope` CHECK (sequence_scope IN (\'GLOBAL\',\'YEAR\',\'MONTH\',\'BRANCH_YEAR\',\'BRANCH_MONTH\'))');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('numbering_rules');
    }
};
