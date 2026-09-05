<?php

namespace App\Console\Commands;

use App\Models\MonitoredService;
use App\Services\NotificationDispatcher;
use App\Services\SlaCalculator;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalculateSlaMetrics extends Command
{
    protected $signature = 'sla:calculate {--date= : A date within the monthly SLA period} {--service= : Service ID}';

    protected $description = 'Calculate monthly SLA and error-budget metrics.';

    public function handle(SlaCalculator $calculator, NotificationDispatcher $notifications): int
    {
        $date = $this->option('date') ? Carbon::parse((string) $this->option('date'), config('app.timezone')) : now();
        $from = $date->copy()->startOfMonth();
        $to = $date->copy()->endOfMonth();
        $services = MonitoredService::query()->where('is_active', true)->where('sla_enabled', true)
            ->when($this->option('service'), fn ($query) => $query->whereKey((int) $this->option('service')))->get();

        foreach ($services as $service) {
            $metric = $calculator->calculate($service, $from, $to);
            $metric->loadMissing('monitoredService');
            $notifications->sla($metric);
        }

        $this->info("Calculated {$services->count()} SLA metric(s).");

        return self::SUCCESS;
    }
}
