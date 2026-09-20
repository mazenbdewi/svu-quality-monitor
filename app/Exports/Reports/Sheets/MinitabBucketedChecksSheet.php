<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Models\MonitoredService;
use App\Services\SpcAnalysisWindow;
use App\Services\SpcResearchData;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class MinitabBucketedChecksSheet implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithStyles, WithTitle
{
    use AppliesReportSheetFormatting;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(protected array $filters = []) {}

    public function collection(): Collection
    {
        [$start, $end] = app(SpcAnalysisWindow::class)->exportBounds($this->filters);
        $end = $end->min(now()->utc());
        if (isset($this->filters['data_cutoff'])) {
            $end = $end->min(Carbon::parse($this->filters['data_cutoff']));
        }
        $aggregation = ($this->filters['bucket_size'] ?? 'hourly') === 'daily' ? 'daily' : 'hourly';
        $timezone = app(SpcAnalysisWindow::class)->timezone();

        return MonitoredService::query()->when($this->filters['service_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->get()
            ->flatMap(fn ($service) => app(SpcResearchData::class)->buckets($service, $start, $end, $aggregation, $timezone)
                ->map(fn ($b) => [$service->name, $b['bucket_start'], $b['bucket_end'], $b['expected_count'], $b['observed_count'], $b['missing_count'], $b['coverage'], $b['problematic_count'], $b['problematic_proportion'], $b['failed_count'], $b['status'], $timezone, $aggregation]))->values();
    }

    public function headings(): array
    {
        return ['service_name', 'bucket_start', 'bucket_end', 'expected_count', 'observed_count', 'missing_count', 'coverage', 'problematic_count', 'problematic_proportion', 'failed_count', 'status', 'analysis_timezone', 'aggregation_interval'];
    }

    public function title(): string
    {
        return 'Bucketed Checks';
    }
}
