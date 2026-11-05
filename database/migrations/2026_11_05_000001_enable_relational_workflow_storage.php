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
        Schema::table('prototype_workspaces', function (Blueprint $t) {
            $t->timestamp('relational_migrated_at')->nullable();
        });
        // Missing historical identities stay NULL; never substitute the deploying user.
        Schema::table('project_plans', function (Blueprint $t) {
            $t->unsignedBigInteger('pm_user_id')->nullable()->change();
            $t->unsignedBigInteger('manager_user_id')->nullable()->change();
            $t->unsignedBigInteger('created_by')->nullable()->change();
            $t->unsignedBigInteger('numbering_rule_id')->nullable()->change();
            $t->string('number_scope_key', 100)->nullable()->change();
            $t->unsignedBigInteger('number_sequence')->nullable()->change();
            $t->string('number_pattern_snapshot', 100)->nullable()->change();
            $t->string('kode_branch_snapshot', 30)->nullable()->change();
            $t->char('number_period', 7)->nullable()->change();
            $t->dateTime('numbered_at', 6)->nullable()->change();
        });
        Schema::table('payment_terms', function (Blueprint $t) {
            $t->unsignedBigInteger('pm_user_id')->nullable()->change();
            $t->unsignedBigInteger('manager_user_id')->nullable()->change();
        });
        Schema::table('boq_lines', function (Blueprint $t) {
            // Source package prices are not invented, dated KHS contracts.
            $t->unsignedBigInteger('khs_price_id')->nullable()->change();
            $t->unsignedBigInteger('source_price_id')->nullable();
            $t->foreign('source_price_id')->references('id_designator_package_price')->on('designator_package_prices');
            $t->string('source_row_id', 100)->nullable();
            $t->json('source_data')->nullable();
        });
        Schema::table('numbering_rules', fn (Blueprint $t) => $t->unsignedBigInteger('updated_by')->nullable()->change());
        Schema::table('approval_runs', function (Blueprint $t) {
            $t->unsignedBigInteger('submitted_by')->nullable()->change();
            $t->dateTime('submitted_at', 6)->nullable()->change();
        });
        Schema::table('approval_steps', fn (Blueprint $t) => $t->unsignedBigInteger('assigned_user_id')->nullable()->change());
        Schema::table('approval_decisions', function (Blueprint $t) {
            $t->unsignedBigInteger('actor_user_id')->nullable()->change();
            $t->json('decision_data')->nullable();
        });
        Schema::table('document_files', fn (Blueprint $t) => $t->unsignedBigInteger('uploaded_by')->nullable()->change());
        foreach (['project_plans', 'contracts', 'payment_terms', 'ba_completions'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->foreignUuid('workspace_id')->nullable()->constrained('prototype_workspaces');
                $t->string('source_id', 100)->nullable();
                $t->unsignedInteger('position')->default(0);
                $t->json('document_data')->nullable();
                $t->unique(['workspace_id', 'source_id'], $table.'_workspace_source');
            });
        }
        Schema::create('work_orders', function (Blueprint $t) {
            $t->uuid('id_spk')->primary();
            $t->foreignUuid('workspace_id')->constrained('prototype_workspaces');
            $t->string('source_id', 100);
            $t->char('contract_id', 36);
            $t->string('spk_no', 150);
            $t->string('status', 20);
            $t->unsignedInteger('position');
            $t->json('document_data');
            $t->foreign('contract_id')->references('id_contract')->on('contracts');
            $t->unique(['workspace_id', 'source_id']);
        });
        Schema::table('payment_terms', function (Blueprint $t) {
            $t->foreignUuid('work_order_id')->nullable()->constrained('work_orders', 'id_spk');
        });
        Schema::create('workflow_metadata', function (Blueprint $t) {
            $t->foreignUuid('id_workspace')->primary()->constrained('prototype_workspaces');
            $t->json('metadata');
        });
        Schema::create('workflow_events', function (Blueprint $t) {
            $t->uuid('id_event')->primary();
            $t->foreignUuid('workspace_id')->constrained('prototype_workspaces');
            $t->string('source_id', 100);
            $t->unsignedInteger('position');
            $t->json('event_data');
            $t->unique(['workspace_id', 'source_id']);
        });
        Schema::create('project_plan_budget_rows', function (Blueprint $t) {
            $t->uuid('id_budget_row')->primary();
            $t->char('pp_id', 36);
            $t->string('category', 20);
            $t->unsignedInteger('position');
            $t->json('row_data');
            $t->foreign('pp_id')->references('id_pp')->on('project_plans');
            $t->unique(['pp_id', 'category', 'position']);
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('workflow_metadata')->exists()) {
            throw new RuntimeException('Data transaksi aktif tersedia. Rollback ditolak; pulihkan dari cadangan melalui prosedur terencana.');
        }
        foreach (['project_plans', 'contracts', 'payment_terms', 'ba_completions'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Tabel transaksi berisi data; rollback ditolak.');
            }
        }
        Schema::dropIfExists('project_plan_budget_rows');
        Schema::dropIfExists('workflow_events');
        Schema::dropIfExists('workflow_metadata');
        Schema::table('payment_terms', function (Blueprint $t) {
            $t->dropForeign(['work_order_id']);
            $t->dropColumn('work_order_id');
        });
        Schema::dropIfExists('work_orders');
        foreach (['project_plans', 'contracts', 'payment_terms', 'ba_completions'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign(['workspace_id']);
                $t->dropUnique($table.'_workspace_source');
                $t->dropColumn(['workspace_id', 'source_id', 'position', 'document_data']);
            });
        }
        Schema::table('boq_lines', function (Blueprint $t) {
            $t->dropForeign(['source_price_id']);
            $t->dropColumn(['source_price_id', 'source_row_id', 'source_data']);
        });
        Schema::table('approval_decisions', fn (Blueprint $t) => $t->dropColumn('decision_data'));
        Schema::table('prototype_workspaces', fn (Blueprint $t) => $t->dropColumn('relational_migrated_at'));
    }
};
