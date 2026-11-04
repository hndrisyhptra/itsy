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
        Schema::create('roles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->smallInteger('id_role')->unsigned()->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::dropIfExists('roles');
    }
};
