<?php

namespace App\Console\Commands;

use App\Models\MonitoredService;
use App\Services\InstitutionTimezone;
use App\Services\NotificationDispatcher;
use App\Services\SlaCalculator;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalculateSlaMetrics extends Command
{
    protected $signature = 'sla:calculate {--date= : Institution-local date within the monthly SLA period; defaults to yesterday} {--service= : Service ID}';

    protected $description = 'Calculate monthly SLA and error-budget metrics.';

    public function handle(SlaCalculator $calculator, NotificationDispatcher $notifications): int
    {
        $timezone = app(InstitutionTimezone::class)->name();
        // Daily execution updates yesterday's local month, including the month just closed.
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'), $timezone)->setTimezone($timezone)
            : now($timezone)->subDay();
        $from = $date->copy()->startOfMonth()->utc();
        $to = $date->copy()->endOfMonth()->utc();
        $services = MonitoredService::query()->where('is_active', true)->where('sla_enabled', true)
            ->when($this->option('service'), fn ($query) => $query->whereKey((int) $this->option('service')))->get();

        foreach ($services as $service) {
            $metric = $calculator->calculate($service, $from, $to, $timezone);
            $metric->loadMissing('monitoredService');
            $notifications->sla($metric);
        }

        $this->info("Calculated {$services->count()} SLA metric(s).");

        return self::SUCCESS;
    }
}
