<?php

namespace App\Models;

use App\Models\Builders\ImmutableResearchBuilder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ControlChartBaselineSample extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['included' => 'boolean', 'measurement' => 'array', 'exclusion_reasons' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $sample) {
            $baseline = ControlChartBaseline::findOrFail($sample->control_chart_baseline_id);
            if ($baseline->sealed_at !== null) {
                throw new LogicException('Sealed membership cannot be extended.');
            }
        });
        static::updating(fn () => throw new LogicException('Research membership is immutable.'));
        static::deleting(fn () => throw new LogicException('Research membership cannot be deleted.'));
    }

    public function newEloquentBuilder($query): ImmutableResearchBuilder
    {
        return new ImmutableResearchBuilder($query);
    }
}
