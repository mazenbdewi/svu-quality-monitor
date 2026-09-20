<?php

namespace App\Console\Commands;

use App\Models\MonitoredService;
use App\Services\ReliabilityMetricCalculator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CalculateReliabilityMetrics extends Command
{
    protected $signature = 'reliability:calculate
        {--period=daily : Period type: daily, weekly, or monthly}
        {--date= : Date inside the target period}
        {--service= : Monitored service ID to calculate only one service}';

    protected $description = 'Calculate reliability metrics for monitored services.';

    public function handle(ReliabilityMetricCalculator $calculator): int
    {
        try {
            [$periodType, $periodStart, $periodEnd] = $this->resolvePeriod();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $services = $this->servicesQuery()->get();
        $errors = 0;
        $calculated = 0;

        foreach ($services as $service) {
            try {
                $calculator->calculateForService($service, $periodStart, $periodEnd, $periodType);
                $calculated++;
            } catch (Throwable $exception) {
                $errors++;

                Log::error('Reliability metric calculation failed.', [
                    'monitored_service_id' => $service->id,
                    'service_name' => $service->name,
                    'period_type' => $periodType,
                    'period_start' => $periodStart->toDateTimeString(),
                    'period_end' => $periodEnd->toDateTimeString(),
                    'exception' => $exception,
                ]);

                $this->error(sprintf(
                    'Error calculating metrics for [%s]: %s',
                    $service->name,
                    $exception->getMessage(),
                ));
            }
        }

        $this->info('Reliability metrics summary');
        $this->line("Period type: {$periodType}");
        $this->line('Period start: '.$periodStart->toDateTimeString());
        $this->line('Period end: '.$periodEnd->toDateTimeString());
        $this->line('Services count: '.$services->count());
        $this->line("Calculated count: {$calculated}");
        $this->line("Errors count: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: Carbon, 2: Carbon}
     */
    private function resolvePeriod(): array
    {
        $periodType = (string) $this->option('period');
        $baseDate = $this->option('date')
            ? Carbon::parse((string) $this->option('date'))
            : now()->subDay();

        return match ($periodType) {
            'daily' => [$periodType, $baseDate->copy()->startOfDay(), $baseDate->copy()->startOfDay()->addDay()],
            'weekly' => [$periodType, $baseDate->copy()->startOfWeek(), $baseDate->copy()->startOfWeek()->addWeek()],
            'monthly' => [$periodType, $baseDate->copy()->startOfMonth(), $baseDate->copy()->startOfMonth()->addMonth()],
            default => throw new InvalidArgumentException('Unsupported period type. Use daily, weekly, or monthly.'),
        };
    }

    private function servicesQuery()
    {
        $query = MonitoredService::query();

        if ($this->option('service')) {
            return $query->whereKey((int) $this->option('service'));
        }

        return $query->where('is_active', true);
    }
}
