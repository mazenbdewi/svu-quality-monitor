<?php

namespace App\Models;

use App\Models\Builders\ImmutableResearchBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SloProposal extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'calibration_start' => 'datetime', 'calibration_end' => 'datetime', 'calibration_cutoff' => 'datetime',
            'calibration_payload' => 'array', 'proposed_compliance_percent' => 'decimal:2',
            'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'retired_at' => 'datetime',
            'limitations_acknowledged' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $proposal) {
            if ($proposal->status !== 'draft' || $proposal->reviewed_at || $proposal->approved_at || $proposal->retired_at) {
                throw new LogicException('An SLO proposal must originate as an unapproved draft.');
            }
        });
        static::updating(fn () => throw new LogicException('SLO calibration snapshots are immutable; use a lifecycle action.'));
        static::deleting(fn () => throw new LogicException('SLO research records cannot be deleted.'));
    }

    public function newEloquentBuilder($query): ImmutableResearchBuilder
    {
        return new ImmutableResearchBuilder($query);
    }

    public function monitoredService(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class);
    }
}
