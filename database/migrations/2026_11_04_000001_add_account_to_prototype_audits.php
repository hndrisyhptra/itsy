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
        Schema::table('prototype_audits', function (Blueprint $table) {
            $table->foreignId('actor_user_id')->nullable()->constrained('users', 'id_user')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Area3MigrationTarget::assertAllowed();
        Schema::table('prototype_audits', fn (Blueprint $table) => $table->dropConstrainedForeignId('actor_user_id'));
    }
};
