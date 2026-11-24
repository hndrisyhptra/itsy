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
        Schema::create('budget_pid_controls', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->unsignedTinyInteger('id_budget_control')->primary();
            $t->unsignedBigInteger('version')->default(0);
        });
        DB::table('budget_pid_controls')->insert(['id_budget_control' => 1, 'version' => 0]);
        Schema::create('budget_pid_imports', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->uuid('id_budget_import')->primary();
            $t->unsignedBigInteger('actor_user_id');
            $t->foreign('actor_user_id')->references('id_user')->on('users')->restrictOnDelete();
            $t->string('unit', 20);
            $t->string('filename', 200);
            $t->char('sha256', 64);
            $t->char('preview_hash', 64);
            $t->unsignedBigInteger('version');
            $t->json('summary');
            $t->timestamp('created_at');
            $t->index(['unit', 'version']);
        });
        Schema::create('budget_pids', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->uuid('id_budget_pid')->primary();
            $t->string('pid', 100)->unique();
            $t->string('name', 500);
            $t->string('unit', 20);
            $t->index(['unit', 'pid']);
            $t->uuid('last_import_id');
            $t->foreign('last_import_id')->references('id_budget_import')->on('budget_pid_imports')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->timestamp('updated_at');
        });
        Schema::create('budget_pid_items', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->uuid('id_budget_item')->primary();
            $t->uuid('budget_pid_id');
            $t->foreign('budget_pid_id')->references('id_budget_pid')->on('budget_pids')->restrictOnDelete();
            $t->string('category', 30);
            $t->string('source_title', 120);
            $t->unsignedInteger('source_row');
            $t->bigInteger('release');
            $t->bigInteger('commitment');
            $t->bigInteger('actual');
            $t->bigInteger('available');
            $t->unique(['budget_pid_id', 'category']);
        });
        Schema::create('budget_pid_changes', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->uuid('id_budget_change')->primary();
            $t->uuid('import_id');
            $t->foreign('import_id')->references('id_budget_import')->on('budget_pid_imports')->restrictOnDelete();
            $t->uuid('budget_pid_id');
            $t->foreign('budget_pid_id')->references('id_budget_pid')->on('budget_pids')->restrictOnDelete();
            $t->string('action', 12);
            $t->json('before_data')->nullable();
            $t->json('after_data');
            $t->unique(['import_id', 'budget_pid_id']);
            $t->index('budget_pid_id');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('budget_pid_imports')->exists() || DB::table('budget_pids')->exists()) {
            throw new RuntimeException('Master Budgeting PID sudah berisi data/riwayat. Rollback ditolak agar data tidak hilang.');
        }
        foreach (['budget_pid_changes', 'budget_pid_items', 'budget_pids', 'budget_pid_imports', 'budget_pid_controls'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
