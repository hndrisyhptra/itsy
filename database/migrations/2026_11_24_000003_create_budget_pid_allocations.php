<?php

use App\Support\Area3MigrationTarget;
use App\Support\BudgetPidMigrationSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        BudgetPidMigrationSchema::ensure([
            'budget_pid_allocations' => function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->charset = 'utf8mb4';
                $t->collation = 'utf8mb4_unicode_ci';
                $t->uuid('id_budget_allocation')->primary();
                $t->uuid('pp_id')->unique();
                $t->foreign('pp_id')->references('id_pp')->on('project_plans')->restrictOnDelete();
                $t->string('pid_code', 100);
                $t->string('unit', 20);
                $t->unsignedBigInteger('branch_id');
                $t->foreign('branch_id')->references('id_branch')->on('branches')->restrictOnDelete();
                $t->uuid('budget_pid_id')->nullable();
                $t->foreign('budget_pid_id')->references('id_budget_pid')->on('budget_pids')->restrictOnDelete();
                $t->string('status', 20);
                foreach (['material', 'service', 'bop', 'planned_material', 'planned_service', 'planned_bop'] as $column) {
                    $t->bigInteger($column);
                }
                $t->char('source_hash', 64);
                $t->unsignedBigInteger('source_revision');
                $t->timestamp('created_at')->useCurrent();
                $t->timestamp('updated_at')->useCurrent();
                $t->index(['pid_code', 'unit', 'branch_id', 'status'], 'budget_pid_usage_scope');
            },
            'budget_pid_allocation_changes' => function (Blueprint $t) {
                $t->engine = 'InnoDB';
                $t->charset = 'utf8mb4';
                $t->collation = 'utf8mb4_unicode_ci';
                $t->uuid('id_budget_allocation_change')->primary();
                $t->uuid('allocation_id');
                $t->foreign('allocation_id')->references('id_budget_allocation')->on('budget_pid_allocations')->restrictOnDelete();
                $t->uuid('request_uuid');
                $t->unsignedBigInteger('actor_user_id');
                $t->foreign('actor_user_id')->references('id_user')->on('users')->restrictOnDelete();
                $t->string('action', 30);
                $t->char('payload_hash', 64);
                $t->json('before_data')->nullable();
                $t->json('after_data');
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['request_uuid', 'allocation_id'], 'budget_pid_allocation_request_unique');
            },
        ]);
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('budget_pid_allocations')->exists() || DB::table('budget_pid_allocation_changes')->exists()) {
            throw new RuntimeException('Alokasi Budgeting PID sudah berisi data/riwayat; rollback ditolak.');
        }
        Schema::drop('budget_pid_allocation_changes');
        Schema::drop('budget_pid_allocations');
    }
};
