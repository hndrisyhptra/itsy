<?php

use App\Support\Area3MigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::create('command_receipts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->char('id_command_receipt', 36)->primary();
            $table->char('request_uuid', 36)->unique();
            $table->bigInteger('actor_user_id')->unsigned();
            $table->string('command_name', 80);
            $table->string('aggregate_type', 40);
            $table->char('aggregate_id', 36);
            $table->bigInteger('expected_revision')->unsigned();
            $table->bigInteger('resulting_revision')->unsigned();
            $table->char('before_sha256', 64);
            $table->char('after_sha256', 64);
            $table->dateTime('created_at', 6);
            $table->foreign(['actor_user_id'], 'a3_fk_079')->references(['id_user'])->on('users');
            $table->index(['aggregate_type', 'aggregate_id', 'created_at'], 'a3_ix_038');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('command_receipts');
    }
};
