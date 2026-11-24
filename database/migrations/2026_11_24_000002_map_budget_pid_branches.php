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
        Schema::table('budget_pids', function (Blueprint $t) {
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->foreign('branch_id')->references('id_branch')->on('branches')->restrictOnDelete();
            $t->index(['unit', 'branch_id', 'pid']);
        });
        Schema::create('budget_pid_branch_changes', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->uuid('id_budget_branch_change')->primary();
            $t->unsignedBigInteger('actor_user_id');
            $t->foreign('actor_user_id')->references('id_user')->on('users')->restrictOnDelete();
            $t->string('unit', 20);
            $t->unsignedBigInteger('branch_id');
            $t->foreign('branch_id')->references('id_branch')->on('branches')->restrictOnDelete();
            $t->unsignedBigInteger('version');
            $t->char('payload_hash', 64);
            $t->json('before_data');
            $t->json('after_data');
            $t->timestamp('created_at');
            $t->index(['unit', 'branch_id', 'version']);
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('budget_pid_branch_changes')->exists() || DB::table('budget_pids')->whereNotNull('branch_id')->exists()) {
            throw new RuntimeException('Pemetaan Branch PID sudah berisi data/riwayat; rollback ditolak.');
        }
        Schema::drop('budget_pid_branch_changes');
        Schema::table('budget_pids', function (Blueprint $t) {
            $t->dropForeign(['branch_id']);
            $t->dropIndex(['unit', 'branch_id', 'pid']);
            $t->dropColumn('branch_id');
        });
    }
};
