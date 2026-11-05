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
        Schema::create('term_work_evidence', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->uuid('id_term_evidence')->primary();
            $t->uuid('source_id')->unique();
            $t->char('term_id', 36);
            $t->char('file_id', 36)->unique();
            $t->unsignedBigInteger('uploaded_by');
            $t->dateTime('uploaded_at', 6);
            $t->unsignedBigInteger('removed_by')->nullable();
            $t->dateTime('removed_at', 6)->nullable();
            $t->foreign('term_id')->references('id_payment_term')->on('payment_terms');
            $t->foreign('file_id')->references('id_document_file')->on('document_files');
            $t->foreign('uploaded_by')->references('id_user')->on('users');
            $t->foreign('removed_by')->references('id_user')->on('users');
            $t->index(['term_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        if (DB::table('term_work_evidence')->exists()) {
            throw new RuntimeException('Lampiran eviden pekerjaan tersedia; rollback ditolak agar riwayat file tetap utuh.');
        }
        Schema::dropIfExists('term_work_evidence');
    }
};
