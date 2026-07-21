<?php

namespace App\Console\Commands;

use App\Models\MonitoredService;
use App\Services\ServiceCheckRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckDueMonitoredServices extends Command
{
    protected $signature = 'services:check-due';

    protected $description = 'Check all due monitored services and store their service check results.';

    public function handle(ServiceCheckRunner $runner): int
    {
        $activeServices = MonitoredService::query()
            ->where('is_active', true)
            ->with('latestServiceCheck')
            ->get();

        $counts = [
            'active' => $activeServices->count(),
            'due' => 0,
            'checked' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($activeServices as $service) {
            if (! $this->isDue($service)) {
                $counts['skipped']++;

                continue;
            }

            $counts['due']++;

            try {
                $check = $runner->run($service);

                $counts['checked']++;

                if ($check->is_success) {
                    $counts['successful']++;
                } else {
                    $counts['failed']++;
                }

                $this->line(sprintf(
                    'Checked [%s]: %s (%d ms)',
                    $service->name,
                    $check->is_success ? 'success' : 'failed',
                    $check->response_time_ms ?? 0,
                ));
            } catch (Throwable $exception) {
                $counts['checked']++;
                $counts['failed']++;

                Log::error('Scheduled service check failed.', [
                    'monitored_service_id' => $service->id,
                    'service_name' => $service->name,
                    'exception' => $exception,
                ]);

                $this->error(sprintf(
                    'Error checking [%s]: %s',
                    $service->name,
                    $exception->getMessage(),
                ));
            }
        }

        $this->newLine();
        $this->info('Service check summary');
        $this->line("Active services count: {$counts['active']}");
        $this->line("Due services count: {$counts['due']}");
        $this->line("Checked services count: {$counts['checked']}");
        $this->line("Successful checks count: {$counts['successful']}");
        $this->line("Failed checks count: {$counts['failed']}");
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
