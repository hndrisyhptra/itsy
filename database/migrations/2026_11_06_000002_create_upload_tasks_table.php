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
        Schema::create('upload_tasks', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->uuid('id_upload_task')->primary();
            $t->foreignUuid('workspace_id')->constrained('prototype_workspaces');
            $t->unsignedBigInteger('user_id');
            $t->foreign('user_id')->references('id_user')->on('users');
            $t->uuid('request_uuid')->unique();
            $t->string('kind', 20);
            $t->string('document_id', 100)->nullable();
            $t->string('status', 20)->default('queued');
            $t->string('original_name', 200);
            $t->string('storage_key');
            $t->string('mime', 80);
            $t->unsignedInteger('size');
            $t->char('sha256', 64);
            $t->text('error')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'document_id', 'status'], 'upload_document_status');
            $t->index(['user_id', 'kind', 'document_id', 'created_at'], 'upload_owner_created');
            $t->index(['user_id', 'status'], 'upload_owner_pending');
        });
        Schema::table('jobs', fn (Blueprint $t) => $t->index(['queue', 'reserved_at', 'available_at'], 'upload_queue_ready'));
        Schema::table('project_plans', function (Blueprint $t) {
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->foreign('branch_id', 'pp_branch_scope_fk')->references('id_branch')->on('branches');
            $t->index(['workspace_id', 'branch_id', 'position'], 'pp_workspace_branch');
        });
        // One business Project ID can contain several PP, including different Branches.
        // Scope by each PP's own immutable snapshot, never the shared projects row.
        $branches = DB::table('branches')->get()->keyBy(fn ($b) => mb_strtoupper(trim($b->name)));
        DB::table('project_plans')->whereNotNull('document_data')->orderBy('id_pp')->chunkById(100, function ($rows) use ($branches) {
            foreach ($rows as $row) {
                $document = json_decode($row->document_data, true, 512, JSON_THROW_ON_ERROR);
                $branch = $branches->get(mb_strtoupper(trim($document['branch'] ?? '')));
                if (! $branch) {
                    throw new RuntimeException('Branch pada snapshot PP tidak dikenal; backfill dihentikan.');
                }
                DB::table('project_plans')->where('id_pp', $row->id_pp)->update(['branch_id' => $branch->id_branch]);
            }
        }, 'id_pp');
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('upload_tasks')->exists()) {
            throw new RuntimeException('Riwayat upload tersedia; rollback ditolak.');
        }
        Schema::table('jobs', fn (Blueprint $t) => $t->dropIndex('upload_queue_ready'));
        Schema::table('project_plans', function (Blueprint $t) {
            $t->dropForeign(DB::getDriverName() === 'sqlite' ? ['branch_id'] : 'pp_branch_scope_fk');
            $t->dropIndex('pp_workspace_branch');
            $t->dropColumn('branch_id');
        });
        Schema::dropIfExists('upload_tasks');
    }
};
