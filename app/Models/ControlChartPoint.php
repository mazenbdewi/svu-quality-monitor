<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ControlChartPoint extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'research_context',

        'control_chart_id',
        'point_time',
        'value',
        'center_line',
        'ucl',
        'lcl',
        'sample_size',
        'failed_count',
        'is_out_of_control',
        'signal_type',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'research_context' => 'array',
            'point_time' => 'datetime',
            'value' => 'decimal:4',
            'center_line' => 'decimal:4',
            'ucl' => 'decimal:4',
            'lcl' => 'decimal:4',
            'is_out_of_control' => 'boolean',
        ];
    }

    public function controlChart(): BelongsTo
    {
        return $this->belongsTo(ControlChart::class);
    }
}
