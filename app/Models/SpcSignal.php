<?php

namespace App\Models;

use App\Models\Builders\ImmutableResearchBuilder;
use Illuminate\Database\Eloquent\Model;

class SpcSignal extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['observed_at' => 'datetime', 'detected_at' => 'datetime', 'data_cutoff' => 'datetime', 'context' => 'array'];
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
