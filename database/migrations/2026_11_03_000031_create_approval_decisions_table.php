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
        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_approval_decision', 36)->primary();
            $table->char('step_id', 36)->unique();
            $table->bigInteger('actor_user_id')->unsigned();
            $table->string('actor_name_snapshot', 150);
            $table->string('decision', 15);
            $table->text('note');
            $table->dateTime('decided_at', 6);
            $table->foreign(['step_id'], 'a3_fk_048')->references(['id_approval_step'])->on('approval_steps');
            $table->foreign(['actor_user_id'], 'a3_fk_049')->references(['id_user'])->on('users');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `approval_decisions` ADD CONSTRAINT `decision_type` CHECK (decision IN (\'APPROVE\',\'RETURN\',\'REJECT\'))');
            DB::statement('ALTER TABLE `approval_decisions` ADD CONSTRAINT `decision_reason` CHECK (decision = \'APPROVE\' OR CHAR_LENGTH(TRIM(note)) > 0)');
        }
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('approval_decisions');
    }
};
