<?php

namespace App\Console\Commands;

use App\Models\MonitoredService;
use App\Services\ControlChartCalculator;
use App\Services\SpcAnalysisWindow;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CalculateControlCharts extends Command
{
    protected $signature = 'control-charts:calculate
        {--period=daily : Period type: daily, weekly, or monthly}
        {--date= : Date inside the target period}
        {--service= : Monitored service ID to calculate only one service}
        {--chart= : Chart type to calculate}
        {--bucket=hourly : Bucket size: hourly or daily}';

    protected $description = 'Calculate statistical control charts for monitored services.';

    public function handle(ControlChartCalculator $calculator): int
    {
        try {
            [$periodType, $periodStart, $periodEnd] = $this->resolvePeriod();
            $chartTypes = $this->resolveChartTypes();
            $bucket = $this->resolveBucket();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $services = $this->servicesQuery()->get();
        $errors = 0;
        $calculated = 0;

        foreach ($services as $service) {
            foreach ($chartTypes as $chartType) {
                try {
                    $calculator->calculate($service, $chartType, $periodStart, $periodEnd, $periodType, $bucket);
                    $calculated++;
                } catch (Throwable $exception) {
                    $errors++;

                    Log::error('Control chart calculation failed.', [
                        'monitored_service_id' => $service->id,
                        'service_name' => $service->name,
                        'chart_type' => $chartType,
                        'period_type' => $periodType,
                        'period_start' => $periodStart->toDateTimeString(),
                        'period_end' => $periodEnd->toDateTimeString(),
                        'bucket' => $bucket,
                        'exception' => $exception,
                    ]);

                    $this->error(sprintf(
                        'Error calculating [%s] for [%s]: %s',
                        $chartType,
                        $service->name,
                        $exception->getMessage(),
                    ));
                }
            }
        }

        $this->info('Control charts summary');
        $this->line('Services count: '.$services->count());
        $this->line('Chart types calculated: '.implode(', ', $chartTypes));
        $this->line("Charts created/updated: {$calculated}");
        $this->line("Errors count: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: Carbon, 2: Carbon}
     */
    private function resolvePeriod(): array
    {
        $periodType = (string) $this->option('period');
        $timezone = app(SpcAnalysisWindow::class)->timezone();
        $baseDate = $this->option('date') ? Carbon::parse((string) $this->option('date'), $timezone) : now($timezone);
        $start = match ($periodType) {
            'daily' => $baseDate->copy()->startOfDay(),
            'weekly' => $baseDate->copy()->startOfWeek(),
            'monthly' => $baseDate->copy()->startOfMonth(),
            default => throw new InvalidArgumentException('Unsupported period type. Use daily, weekly, or monthly.'),
        };
        if (! $this->option('date')) {
            $start = match ($periodType) {
                'daily' => $start->subDay(), 'weekly' => $start->subWeek(), 'monthly' => $start->subMonth(),
            };
        }
        $end = match ($periodType) {
            'daily' => $start->copy()->addDay(), 'weekly' => $start->copy()->addWeek(), 'monthly' => $start->copy()->addMonth(),
        };

        return [$periodType, $start->utc(), $end->utc()];
    }

    /**
     * @return list<string>
     */
    private function resolveChartTypes(): array
    {
        $chartType = $this->option('chart');

        if (! $chartType) {
            return ControlChartCalculator::CHART_TYPES;
        }

        if (! in_array($chartType, ControlChartCalculator::CHART_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported chart type. Use i_chart, mr_chart, p_chart, c_chart, or u_chart.');
        }

        return [(string) $chartType];
    }

    private function resolveBucket(): string
    {
        $bucket = (string) $this->option('bucket');

        if (! in_array($bucket, ['hourly', 'daily'], true)) {
            throw new InvalidArgumentException('Unsupported bucket. Use hourly or daily.');
        }

        return $bucket;
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
