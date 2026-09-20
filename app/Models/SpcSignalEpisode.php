<?php

namespace App\Models;

use App\Models\Builders\ImmutableResearchBuilder;
use Illuminate\Database\Eloquent\Model;

class SpcSignalEpisode extends Model
{
    protected $guarded = ['id'];

    public function newEloquentBuilder($query): ImmutableResearchBuilder
    {
        return new ImmutableResearchBuilder($query);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Episode history can only be extended by the evaluator.'));
        static::deleting(fn () => throw new \LogicException('Historical SPC episodes cannot be deleted.'));
    }

    protected function casts(): array
    {
        return ['episode_started_at' => 'datetime', 'first_detected_at' => 'datetime', 'last_detected_at' => 'datetime', 'closed_at' => 'datetime'];
    }
}
