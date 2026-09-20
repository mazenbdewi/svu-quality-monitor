<?php

namespace App\Models;

use App\Models\Builders\ImmutableResearchBuilder;
use Illuminate\Database\Eloquent\Model;

class SpcResearchRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_start' => 'datetime', 'period_end' => 'datetime', 'data_cutoff' => 'datetime', 'metrics' => 'array', 'dataset' => 'array', 'baseline_versions' => 'array'];
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
