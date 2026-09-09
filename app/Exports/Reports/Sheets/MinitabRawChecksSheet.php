<?php

namespace App\Exports\Reports\Sheets;

use App\Exports\Reports\Sheets\Concerns\AppliesReportSheetFormatting;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
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
        return ServiceCheck::query()
            ->with('monitoredService:id,name,category')
            ->when($this->filters['service_id'] ?? null, fn (Builder $query, $serviceId): Builder => $query->where('monitored_service_id', $serviceId))
            ->when($this->filters['date_from'] ?? null, fn (Builder $query, $date): Builder => $query->where('checked_at', '>=', Carbon::parse($date)->startOfDay()))
            ->when($this->filters['date_to'] ?? null, fn (Builder $query, $date): Builder => $query->where('checked_at', '<=', Carbon::parse($date)->endOfDay()))
            ->orderBy(
                MonitoredService::query()
                    ->select('name')
                    ->whereColumn('monitored_services.id', 'service_checks.monitored_service_id')
                    ->limit(1)
            )
            ->orderBy('checked_at');
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
        ];
    }

    public function title(): string
    {
        return 'Raw Checks';
    }
}
