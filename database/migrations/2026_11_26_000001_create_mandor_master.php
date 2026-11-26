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
        Schema::create('mandors', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->uuid('id_mandor')->primary();
            $t->foreignUuid('workspace_id')->constrained('prototype_workspaces');
            $t->unsignedBigInteger('branch_id');
            $t->foreign('branch_id')->references('id_branch')->on('branches');
            $t->string('unit', 20);
            $t->string('name', 150);
            $t->char('nik_hash', 64);
            $t->longText('private_data');
            $t->uuid('ktp_file_id')->nullable();
            $t->uuid('bank_file_id')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('updated_by');
            $t->foreign('created_by')->references('id_user')->on('users');
            $t->foreign('updated_by')->references('id_user')->on('users');
            $t->timestamp('created_at')->useCurrent();
            $t->timestamp('updated_at')->useCurrent();
            $t->unique(['workspace_id', 'unit', 'branch_id', 'nik_hash'], 'mandor_scope_nik');
            $t->index(['workspace_id', 'unit', 'branch_id', 'name'], 'mandor_scope_search');
        });
        Schema::create('mandor_files', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->uuid('id_mandor_file')->primary();
            $t->foreignUuid('mandor_id')->references('id_mandor')->on('mandors');
            $t->string('kind', 20);
            $t->string('storage_key');
            $t->string('mime', 80);
            $t->string('extension', 5);
            $t->unsignedInteger('size');
            $t->char('sha256', 64);
            $t->unsignedBigInteger('uploaded_by');
            $t->foreign('uploaded_by')->references('id_user')->on('users');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['mandor_id', 'kind', 'created_at'], 'mandor_file_history');
        });
        Schema::create('mandor_changes', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->uuid('id_mandor_change')->primary();
            $t->foreignUuid('mandor_id')->references('id_mandor')->on('mandors');
            $t->string('action', 20);
            $t->unsignedBigInteger('actor_user_id');
            $t->foreign('actor_user_id')->references('id_user')->on('users');
            $t->char('payload_hash', 64);
            $t->longText('before_data')->nullable();
            $t->longText('after_data');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['mandor_id', 'created_at'], 'mandor_change_history');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        foreach (['mandors', 'mandor_files', 'mandor_changes'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Master/berkas/riwayat Mandor tersedia; rollback ditolak agar data tidak hilang.');
            }
        }
        Schema::dropIfExists('mandor_changes');
        Schema::dropIfExists('mandor_files');
        Schema::dropIfExists('mandors');
    }
};
