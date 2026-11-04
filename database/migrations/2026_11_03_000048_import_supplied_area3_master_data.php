<?php

use App\Support\Area3MasterData;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Area3MasterData::install();
    }

    public function down(): void
    {
        Area3MasterData::rollback();
    }
};
