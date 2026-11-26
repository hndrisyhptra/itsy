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
        Schema::create('mandor_imports', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->uuid('id_mandor_import')->primary();
            $t->foreignUuid('workspace_id')->constrained('prototype_workspaces');
            $t->unsignedBigInteger('actor_user_id');
            $t->foreign('actor_user_id')->references('id_user')->on('users');
            $t->char('file_sha256', 64);
            $t->char('payload_hash', 64);
            $t->json('summary');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['workspace_id', 'created_at'], 'mandor_import_history');
        });
        Schema::table('mandor_changes', function (Blueprint $t) {
            $t->foreignUuid('import_id')->nullable()->references('id_mandor_import')->on('mandor_imports');
            $t->unique(['import_id', 'mandor_id'], 'mandor_import_change_identity');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('mandor_imports')->exists() || DB::table('mandor_changes')->whereNotNull('import_id')->exists()) {
            throw new RuntimeException('Riwayat impor Mandor tersedia; rollback ditolak agar data dan audit tidak hilang.');
        }
        Schema::table('mandor_changes', function (Blueprint $t) {
            $t->dropUnique('mandor_import_change_identity');
            $t->dropForeign(['import_id']);
            $t->dropColumn('import_id');
        });
        Schema::dropIfExists('mandor_imports');
    }
};
