<?php

namespace App\Models;

use Database\Factories\ServiceCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCheck extends Model
{
    /** @use HasFactory<ServiceCheckFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'monitored_service_id',
        'checked_at',
        'status_code',
        'response_time_ms',
        'is_success',
        'is_slow',
        'error_type',
        'error_message',
        'expected_keyword_found',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'is_success' => 'boolean',
            'is_slow' => 'boolean',
            'expected_keyword_found' => 'boolean',
        ];
    }

    public function monitoredService(): BelongsTo
    {
        return $this->belongsTo(MonitoredService::class);
    }
}
