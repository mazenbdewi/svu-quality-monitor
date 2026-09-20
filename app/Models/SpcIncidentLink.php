<?php

namespace App\Models;

use App\Models\Builders\ImmutableResearchBuilder;
use Illuminate\Database\Eloquent\Model;

class SpcIncidentLink extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'is_nearest' => 'boolean', 'context' => 'array'];
    }

    public function newEloquentBuilder($query): ImmutableResearchBuilder
    {
        return new ImmutableResearchBuilder($query);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Historical SPC evidence cannot be rewritten.'));
        static::deleting(fn () => throw new \LogicException('Historical SPC evidence cannot be deleted.'));
    }
}
