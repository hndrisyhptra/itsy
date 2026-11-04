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
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_number_sequence');
            $table->bigInteger('rule_id')->unsigned();
            $table->string('scope_key', 100);
            $table->bigInteger('next_value')->unsigned()->default(1);
            $table->unique(['rule_id', 'scope_key'], 'a3_uq_013');
            $table->foreign(['rule_id'], 'a3_fk_020')->references(['id_numbering_rule'])->on('numbering_rules');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `number_sequences` ADD CONSTRAINT `sequence_positive` CHECK (next_value > 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('number_sequences');
    }
};
