<?php

namespace App\Console\Commands;

use App\Jobs\CheckMonitoredServiceJob;
use App\Models\MonitoredService;
use App\Models\MonitoringScheduleEpoch;
use App\Services\FixedMonitoringCadence;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckDueMonitoredServices extends Command
{
    protected $signature = 'services:check-due';

    protected $description = 'Check all due monitored services and store their service check results.';

    public function handle(Dispatcher $dispatcher, FixedMonitoringCadence $fixedCadence): int
    {
        if ($fixedCadence->active()) {
            return $this->handleFixedCadence($dispatcher, $fixedCadence);
        }
        if (! config('monitoring.fixed_cadence.enabled') && MonitoringScheduleEpoch::query()->exists()) {
            $this->error('Fixed cadence has already started; refusing an unrecorded return to legacy scheduling. Review the rollback plan.');

            return self::FAILURE;
        }

        $activeServices = MonitoredService::query()
            ->where('is_active', true)
            ->with('latestServiceCheck')
            ->get();

        $counts = [
            'active' => $activeServices->count(),
            'due' => 0,
            'dispatched' => 0,
            'dispatch_failed' => 0,
            'duplicate_pending' => 0,
            'skipped' => 0,
        ];

        foreach ($activeServices as $service) {
            if (! $this->isDue($service)) {
                $counts['skipped']++;

                continue;
            }

            $counts['due']++;

            $job = new CheckMonitoredServiceJob($service->id);
            // Calling the bus dispatcher directly does not acquire Laravel's
            // ShouldBeUnique lock (PendingDispatch normally does this).
            $lock = new UniqueLock($job->uniqueVia());
            if (! $lock->acquire($job)) {
                $counts['duplicate_pending']++;

                continue;
            }

            try {
                $dispatcher->dispatch($job);
                $counts['dispatched']++;

                $this->line("Dispatched check for [{$service->name}].");
            } catch (Throwable $exception) {
                $lock->release($job);
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
        $this->line("Duplicate pending jobs count: {$counts['duplicate_pending']}");
        $this->line("Skipped services count: {$counts['skipped']}");

        return self::SUCCESS;
    }

    private function handleFixedCadence(Dispatcher $dispatcher, FixedMonitoringCadence $fixedCadence): int
    {
        $fixedCadence->assertAtomicDatabaseQueue();
        $counts = ['due' => 0, 'dispatched' => 0, 'missed' => 0, 'pending' => 0, 'disabled' => 0, 'failed' => 0];
        foreach (MonitoredService::query()->orderBy('id')->lazyById() as $service) {
            try {
                $result = $fixedCadence->process($service, $dispatcher);
                foreach ($result as $key => $value) {
                    $counts[$key] += $value;
                }
            } catch (Throwable $exception) {
                $counts['failed']++;
                Log::error('Fixed monitoring opportunity dispatch failed.', [
                    'monitored_service_id' => $service->id,
                    'exception_class' => $exception::class,
                ]);
            }
        }
        $this->line('Fixed monitoring cadence policy: '.FixedMonitoringCadence::VERSION);
        foreach ($counts as $key => $value) {
            $this->line("{$key}: {$value}");
        }

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
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
