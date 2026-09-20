<?php

namespace App\Models;

use App\Models\Builders\ImmutableResearchBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ControlChartBaseline extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['monitoredService'];

    protected function casts(): array
    {
        return [
            'baseline_start' => 'datetime', 'baseline_end' => 'datetime', 'data_cutoff' => 'datetime',
            'sealed_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime',
            'retired_at' => 'datetime', 'rejected_at' => 'datetime', 'effective_from' => 'datetime', 'effective_to' => 'datetime',
            'sample_counts' => 'array', 'coverage_context' => 'array', 'parameters' => 'array',
            'configuration_snapshot' => 'array', 'review_context' => 'array', 'limitations_acknowledged' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $baseline) {
            if ($baseline->status !== 'draft' || $baseline->approved_at || $baseline->active_key || $baseline->sealed_at) {
                throw new LogicException('A baseline must originate as an unapproved draft.');
            }
        });
        static::updating(fn () => throw new LogicException('Research snapshots are immutable; use a new version or lifecycle action.'));
        static::deleting(fn () => throw new LogicException('Research history cannot be deleted.'));
    }

    public function newEloquentBuilder($query): ImmutableResearchBuilder
    {
        return new ImmutableResearchBuilder($query);
    }

    public function monitoredService(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class)->select(['id', 'name']);
    }

    public function samples(): HasMany
    {
        return $this->hasMany(ControlChartBaselineSample::class);
    }
}
