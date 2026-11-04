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
        Schema::create('designators', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->bigIncrements('id_designator');
            $table->string('code', 255);
            $table->text('item_name');
            $table->string('unit', 255);
            $table->bigInteger('designator_type_id')->unsigned()->nullable()->default(null);
            $table->bigInteger('designator_category_id')->unsigned()->nullable()->default(null);
            $table->bigInteger('created_by')->unsigned()->nullable()->default(null);
            $table->bigInteger('updated_by')->unsigned()->nullable()->default(null);
            $table->timestamp('created_at')->nullable()->default(null);
            $table->timestamp('updated_at')->nullable()->default(null);
            $table->timestamp('deleted_at')->nullable()->default(null);
            $table->unique(['code'], 'a3_uq_009');
            $table->index(['created_by'], 'a3_ix_023');
            $table->index(['updated_by'], 'a3_ix_024');
            $table->index(['designator_type_id'], 'a3_ix_025');
            $table->index(['designator_category_id'], 'a3_ix_026');
            $table->foreign(['designator_type_id'], 'a3_fk_008')->references(['id_designator_type'])->on('designator_types')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('designators');
    }
};
