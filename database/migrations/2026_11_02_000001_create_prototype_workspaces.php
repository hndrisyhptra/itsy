<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prototype_workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_key', 64)->unique();
            $table->unsignedBigInteger('revision')->default(0);
            $table->json('state');
            $table->timestamps();
        });
        Schema::create('prototype_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained('prototype_workspaces');
            $table->uuid('request_id');
            $table->string('command', 60);
            $table->string('actor_role', 40);
            $table->unsignedBigInteger('revision');
            $table->string('before_hash', 64);
            $table->string('after_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['workspace_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prototype_audits');
        Schema::dropIfExists('prototype_workspaces');
    }
};
