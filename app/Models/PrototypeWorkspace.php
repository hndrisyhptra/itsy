<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PrototypeWorkspace extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /** Runtime needs the lock/revision only; the original state remains a preserved archive. */
    public static function runtimeQuery(): Builder
    {
        return static::query()->select(['id', 'owner_key', 'revision', 'relational_migrated_at', 'created_at', 'updated_at']);
    }

    protected function casts(): array
    {
        return ['state' => 'array', 'revision' => 'integer', 'relational_migrated_at' => 'datetime'];
    }
}
