<?php

namespace App\Console\Commands;

use App\Jobs\CheckMonitoredServiceJob;
use App\Models\MonitoredService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckDueMonitoredServices extends Command
{
    protected $signature = 'services:check-due';

    protected $description = 'Check all due monitored services and store their service check results.';

    public function handle(Dispatcher $dispatcher): int
    {
        $activeServices = MonitoredService::query()
            ->where('is_active', true)
            ->with('latestServiceCheck')
            ->get();

        $counts = [
            'active' => $activeServices->count(),
            'due' => 0,
            'dispatched' => 0,
            'dispatch_failed' => 0,
            'skipped' => 0,
        ];

        foreach ($activeServices as $service) {
            if (! $this->isDue($service)) {
                $counts['skipped']++;

                continue;
            }

            $counts['due']++;

            try {
                $dispatcher->dispatch(new CheckMonitoredServiceJob($service->id));
                $counts['dispatched']++;

                $this->line("Dispatched check for [{$service->name}].");
            } catch (Throwable $exception) {
                $counts['dispatch_failed']++;

                Log::error('Scheduled service-check dispatch failed.', [
                    'monitored_service_id' => $service->id,
                    'service_name' => $service->name,
                    'exception' => $exception,
                ]);

                $this->error(sprintf(
                    'Error dispatching [%s]: %s',
                    $service->name,
                    $exception->getMessage(),
                ));
            }
        }

        $this->newLine();
        $this->info('Service check summary');
        $this->line("Active services count: {$counts['active']}");
        $this->line("Due services count: {$counts['due']}");
        $this->line("Dispatched services count: {$counts['dispatched']}");
        $this->line("Failed dispatches count: {$counts['dispatch_failed']}");
        $this->line("Skipped services count: {$counts['skipped']}");

        return self::SUCCESS;
    }

    private function isDue(MonitoredService $service): bool
    {
        $latestCheck = $service->latestServiceCheck;

        if ($latestCheck === null) {
            return true;
        }

        return $latestCheck->checked_at->lte(
            now()->subMinutes((int) $service->check_interval_minutes),
        );
    }
}
