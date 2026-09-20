<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Services\SpcAnalysisWindow;
use App\Services\SpcResearchData;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class MinitabRawChecksSheet implements FromQuery, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles, WithTitle
{
    use AppliesReportSheetFormatting;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(protected array $filters = []) {}

    public function query(): Builder
    {
        [$start, $end] = app(SpcAnalysisWindow::class)->exportBounds($this->filters);
        $end = $end->min(now()->utc());
        if (isset($this->filters['data_cutoff'])) {
            $end = $end->min(Carbon::parse($this->filters['data_cutoff']));
        }
        $ids = MonitoredService::query()->when($this->filters['service_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->get()
            ->flatMap(fn ($service) => app(SpcResearchData::class)->checks($service, $start, $end)->pluck('id'));

        return ServiceCheck::query()->with('monitoredService:id,name,category')->whereIn('id', $ids)
            ->orderBy('monitored_service_id')->orderBy('checked_at')->orderBy('id');
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'service_name',
            'service_category',
            'checked_at',
            'date',
            'hour',
            'status_code',
            'response_time_ms',
            'success_flag',
            'failure_flag',
            'slow_flag',
            'problematic_flag',
            'keyword_found_flag',
            'error_type',
            'check_id', 'source', 'check_type', 'performance_status', 'is_during_maintenance', 'research_eligible', 'latency_eligible', 'latency_exclusion_reason',
        ];
    }

    /**
     * @param  ServiceCheck  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        $checkedAt = $row->checked_at instanceof Carbon ? $row->checked_at : Carbon::parse($row->checked_at);
        $isSuccess = $row->is_success === true;
        $isFailure = $row->is_success === false;
        $isSlow = $row->is_slow === true;

        return [
            $row->monitoredService?->name,
            $row->monitoredService?->category,
            $checkedAt->format('Y-m-d H:i:s'),
            $checkedAt->format('Y-m-d'),
            (int) $checkedAt->format('G'),
            $row->status_code,
            $row->response_time_ms,
            $isSuccess ? 1 : 0,
            $isFailure ? 1 : 0,
            $isSlow ? 1 : 0,
            ($isFailure || $isSlow) ? 1 : 0,
            $row->expected_keyword_found === null ? null : ($row->expected_keyword_found ? 1 : 0),
            $row->error_type,
            $row->id, $row->source, $row->check_type, $row->performance_status, (int) $row->is_during_maintenance, 1,
            (int) ($isSuccess && $row->response_time_ms !== null && $row->response_time_ms >= 0),
            ! $isSuccess ? 'functional_failure' : ($row->response_time_ms === null || $row->response_time_ms < 0 ? 'invalid_latency' : null),
        ];
    }

    public function title(): string
    {
        return 'Raw Checks';
    }
}
