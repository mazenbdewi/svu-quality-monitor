<?php

namespace App\Filament\Pages;

use App\Exports\Reports\ComprehensiveResearchReportExport;
use App\Exports\Reports\MinitabReadyExport;
use App\Exports\Reports\ReportExport;
use App\Exports\Reports\Sheets\ControlChartsSheet;
use App\Exports\Reports\Sheets\MaintenanceWindowsSheet;
use App\Exports\Reports\Sheets\OutOfControlPointsSheet;
use App\Exports\Reports\Sheets\ReliabilityMetricsSheet;
use App\Exports\Reports\Sheets\ServiceChecksSheet;
use App\Exports\Reports\Sheets\ServiceIncidentsSheet;
use App\Exports\Reports\Sheets\SlaMetricsSheet;
use App\Models\MonitoredService;
use App\Reports\ComprehensivePdfReport;
use App\Reports\ExecutiveMonthlyPdfReport;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

class ReportsPage extends Page
{
    public static function canAccess(): bool
    {
        return auth()->user()?->can('reports.view') ?? false;
    }

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $slug = 'reports';

    protected static ?int $navigationSort = 20;

    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

    public static function getNavigationLabel(): string
    {
        return __('monitoring.reports.page.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('monitoring.navigation_groups.reports');
    }

    public function getTitle(): string
    {
        return __('monitoring.reports.page.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'report_type' => 'service_checks',
            'export_format' => 'excel',
            'report_month' => now()->startOfMonth()->toDateString(),
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('report_type')
                    ->label(__('monitoring.reports.fields.report_type'))
                    ->helperText(fn (Get $get): string => $get('report_type') === 'minitab_ready'
                        ? __('monitoring.reports.helpers.minitab_ready')
                        : __('monitoring.reports.helpers.report_type'))
                    ->options(static::reportTypeOptions())
                    ->required()
                    ->live()
                    ->native(false)
                    ->afterStateUpdated(function (): void {
                        $this->data['status'] = null;
                        $this->data['slow_status'] = null;
                        $this->data['severity'] = null;
                        $this->data['incident_type'] = null;
                        $this->data['chart_type'] = null;
                        $this->data['metric_name'] = null;
                        $this->data['bucket_size'] = $this->data['report_type'] === 'minitab_ready' ? 'hourly' : null;
                    }),
                Select::make('export_format')
                    ->label(__('monitoring.reports.fields.export_format'))
                    ->helperText(__('monitoring.reports.helpers.export_format'))
                    ->options(static::exportFormatOptions())
                    ->required()
                    ->default('excel')
                    ->native(false),
                Select::make('service_id')
                    ->label(__('monitoring.reports.fields.service_id'))
                    ->helperText(__('monitoring.reports.helpers.service_id'))
                    ->options(fn (): array => MonitoredService::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->native(false),
                DatePicker::make('date_from')
                    ->label(__('monitoring.reports.fields.date_from'))
                    ->helperText(__('monitoring.reports.helpers.date_from'))
                    ->beforeOrEqual(fn (Get $get): mixed => $get('date_to')),
                DatePicker::make('date_to')
                    ->label(__('monitoring.reports.fields.date_to'))
                    ->helperText(__('monitoring.reports.helpers.date_to'))
                    ->afterOrEqual(fn (Get $get): mixed => $get('date_from')),
                DatePicker::make('report_month')
                    ->label(__('monitoring.executive.report.month'))
                    ->displayFormat('Y-m')
                    ->visible(fn (Get $get): bool => $get('report_type') === 'executive_monthly')
                    ->required(fn (Get $get): bool => $get('report_type') === 'executive_monthly'),
                Select::make('status')
                    ->label(__('monitoring.reports.fields.status'))
                    ->options(fn (Get $get): array => match ($get('report_type')) {
                        'service_checks' => [
                            'success' => __('monitoring.statuses.success'),
                            'failed' => __('monitoring.statuses.failed'),
                        ],
                        'incidents' => [
                            'open' => __('monitoring.statuses.open'),
                            'closed' => __('monitoring.statuses.closed'),
                        ],
                        default => [],
                    })
                    ->hidden(fn (Get $get): bool => ! in_array($get('report_type'), ['service_checks', 'incidents'], true))
                    ->native(false),
                Select::make('slow_status')
                    ->label(__('monitoring.reports.fields.slow_status'))
                    ->options([
                        'slow' => __('monitoring.statuses.slow'),
                        'not_slow' => __('monitoring.statuses.not_slow'),
                    ])
                    ->hidden(fn (Get $get): bool => $get('report_type') !== 'service_checks')
                    ->native(false),
                Select::make('period_type')
                    ->label(__('monitoring.reports.fields.period_type'))
                    ->helperText(__('monitoring.reports.helpers.period_type'))
                    ->options(static::periodTypeOptions())
                    ->hidden(fn (Get $get): bool => ! in_array($get('report_type'), ['reliability_metrics', 'control_charts', 'comprehensive'], true))
                    ->native(false),
                Select::make('chart_type')
                    ->label(__('monitoring.reports.fields.chart_type'))
                    ->helperText(__('monitoring.reports.helpers.chart_type'))
                    ->options(static::chartTypeOptions())
                    ->hidden(fn (Get $get): bool => ! in_array($get('report_type'), ['control_charts', 'comprehensive'], true))
                    ->native(false),
                Select::make('metric_name')
                    ->label(__('monitoring.reports.fields.metric_name'))
                    ->helperText(__('monitoring.reports.helpers.metric_name'))
                    ->options(static::metricNameOptions())
                    ->hidden(fn (Get $get): bool => ! in_array($get('report_type'), ['control_charts', 'comprehensive'], true))
                    ->native(false),
                Select::make('bucket_size')
                    ->label(__('monitoring.reports.fields.bucket_size'))
                    ->options(static::bucketSizeOptions())
                    ->default('hourly')
                    ->hidden(fn (Get $get): bool => $get('report_type') !== 'minitab_ready')
                    ->native(false),
                Select::make('severity')
                    ->label(__('monitoring.reports.fields.severity'))
                    ->options(static::severityOptions())
                    ->hidden(fn (Get $get): bool => $get('report_type') !== 'incidents')
                    ->native(false),
                Select::make('incident_type')
                    ->label(__('monitoring.reports.fields.incident_type'))
                    ->options(static::incidentTypeOptions())
                    ->hidden(fn (Get $get): bool => $get('report_type') !== 'incidents')
                    ->native(false),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('monitoring.reports.page.section_title'))
                    ->schema([
                        Form::make([EmbeddedSchema::make('form')])
                            ->id('form')
                            ->livewireSubmitHandler('export')
                            ->footer([
                                Actions::make([
                                    Action::make('export')
                                        ->label(__('monitoring.reports.actions.export'))
                                        ->icon(Heroicon::OutlinedArrowDownTray)
                                        ->submit('export'),
                                ]),
                            ]),
                    ]),
            ]);
    }

    public function export(): ?Response
    {
        abort_unless(auth()->user()?->can('reports.generate'), 403);
        $data = $this->normalizeFilters($this->form->getState());
        $reportType = (string) $data['report_type'];
        $exportFormat = (string) ($data['export_format'] ?? 'excel');
        unset($data['report_type']);
        unset($data['export_format']);

        if ($exportFormat === 'pdf' && $reportType === 'minitab_ready') {
            Notification::make()
                ->danger()
                ->title(__('monitoring.reports.validation.minitab_excel_only'))
                ->send();

            throw ValidationException::withMessages([
                'data.export_format' => __('monitoring.reports.validation.minitab_excel_only'),
            ]);
        }

        if ($exportFormat === 'pdf' && ! in_array($reportType, ['comprehensive', 'executive_monthly'], true)) {
            Notification::make()
                ->danger()
                ->title(__('monitoring.pdf_reports.pdf_only_comprehensive'))
                ->send();

            throw ValidationException::withMessages([
                'data.export_format' => __('monitoring.pdf_reports.pdf_only_comprehensive'),
            ]);
        }

        if (! $this->reportHasData($reportType, $data)) {
            Notification::make()
                ->warning()
                ->title(__('monitoring.reports.notifications.no_data'))
                ->send();

            return null;
        }

        if ($exportFormat === 'pdf' && $reportType === 'executive_monthly') {
            $report = new ExecutiveMonthlyPdfReport((string) ($data['report_month'] ?? now()->toDateString()));
            $pdf = Pdf::loadView('reports.executive-monthly-pdf', $report->data())->setPaper('a4');

            return response()->streamDownload(static function () use ($pdf): void {
                echo $pdf->output();
            }, 'executive-quality-report-'.Carbon::parse($data['report_month'] ?? now())->format('Y-m').'.pdf', ['Content-Type' => 'application/pdf']);
        }

        if ($exportFormat === 'pdf') {
            $report = new ComprehensivePdfReport($data);
            $pdf = Pdf::loadView('reports.comprehensive-pdf', [
                'report' => $report,
                ...$report->data(),
            ])->setPaper('a4');

            Notification::make()
                ->success()
                ->title(__('monitoring.reports.notifications.success'))
                ->send();

            return response()->streamDownload(
                static function () use ($pdf): void {
                    echo $pdf->output();
                },
                $this->pdfFileName(),
                ['Content-Type' => 'application/pdf'],
            );
        }

        $export = match ($reportType) {
            'comprehensive' => new ComprehensiveResearchReportExport($data),
            'minitab_ready' => new MinitabReadyExport($data),
            default => new ReportExport($reportType, $data),
        };

        Notification::make()
            ->success()
            ->title(__('monitoring.reports.notifications.success'))
            ->send();

        return Excel::download($export, $this->fileName($reportType));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeFilters(array $data): array
    {
        $data = Arr::map($data, fn (mixed $value): mixed => $value === '' ? null : $value);

        if (($data['report_type'] ?? null) !== 'service_checks') {
            $data['slow_status'] = null;
        }

        if (($data['report_type'] ?? null) !== 'executive_monthly') {
            $data['report_month'] = null;
        }

        if (! in_array($data['report_type'] ?? null, ['service_checks', 'incidents'], true)) {
            $data['status'] = null;
        }

        if (($data['report_type'] ?? null) !== 'incidents') {
            $data['severity'] = null;
            $data['incident_type'] = null;
        }

        if (! in_array($data['report_type'] ?? null, ['control_charts', 'comprehensive'], true)) {
            $data['chart_type'] = null;
            $data['metric_name'] = null;
        }

        if (! in_array($data['report_type'] ?? null, ['reliability_metrics', 'control_charts', 'comprehensive'], true)) {
            $data['period_type'] = null;
        }

        if (($data['report_type'] ?? null) === 'minitab_ready') {
            $data['bucket_size'] = ($data['bucket_size'] ?? 'hourly') === 'daily' ? 'daily' : 'hourly';
        } else {
            $data['bucket_size'] = null;
        }

        return array_filter($data, fn (mixed $value): bool => $value !== null);
    }

    protected function fileName(string $reportType): string
    {
        $baseName = match ($reportType) {
            'service_checks' => 'service-checks-report',
            'incidents' => 'incidents-report',
            'reliability_metrics' => 'reliability-metrics-report',
            'control_charts' => 'control-charts-report',
            'maintenance_windows' => 'maintenance-windows-report',
            'sla_metrics' => 'sla-metrics-report',
            'executive_monthly' => 'executive-quality-report',
            'comprehensive' => 'comprehensive-research-report',
            'minitab_ready' => 'minitab-ready-export',
            default => 'report',
        };

        return $baseName.'-'.now()->format('Y-m-d').'.xlsx';
    }

    protected function pdfFileName(): string
    {
        return 'comprehensive-research-report-'.now()->format('Y-m-d').'.pdf';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function reportHasData(string $reportType, array $filters): bool
    {
        return match ($reportType) {
            'service_checks' => (new ServiceChecksSheet($filters))->query()->exists(),
            'incidents' => (new ServiceIncidentsSheet($filters))->query()->exists(),
            'reliability_metrics' => (new ReliabilityMetricsSheet($filters))->query()->exists(),
            'control_charts' => (new ControlChartsSheet($filters))->query()->exists(),
            'maintenance_windows' => (new MaintenanceWindowsSheet($filters))->query()->exists(),
            'sla_metrics' => (new SlaMetricsSheet($filters))->query()->exists(),
            'executive_monthly' => (new ExecutiveMonthlyPdfReport((string) ($filters['report_month'] ?? now()->toDateString())))->hasData(),
            'comprehensive' => (new ServiceChecksSheet($filters))->query()->exists()
                || (new ServiceIncidentsSheet($filters))->query()->exists()
                || (new ReliabilityMetricsSheet($filters))->query()->exists()
                || (new ControlChartsSheet($filters))->query()->exists()
                || (new OutOfControlPointsSheet($filters))->query()->exists(),
            'minitab_ready' => (new ServiceChecksSheet($filters))->query()->exists()
                || (new ReliabilityMetricsSheet($filters))->query()->exists()
                || (new ControlChartsSheet($filters))->query()->exists()
                || (new OutOfControlPointsSheet($filters))->query()->exists(),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function reportTypeOptions(): array
    {
        return [
            'service_checks' => __('monitoring.report_types.service_checks'),
            'incidents' => __('monitoring.report_types.incidents'),
            'reliability_metrics' => __('monitoring.report_types.reliability_metrics'),
            'control_charts' => __('monitoring.report_types.control_charts'),
            'maintenance_windows' => __('monitoring.report_types.maintenance_windows'),
            'sla_metrics' => __('monitoring.report_types.sla_metrics'),
            'executive_monthly' => __('monitoring.report_types.executive_monthly'),
            'comprehensive' => __('monitoring.report_types.comprehensive'),
            'minitab_ready' => __('monitoring.report_types.minitab_ready'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function exportFormatOptions(): array
    {
        return [
            'excel' => __('monitoring.pdf_reports.export_formats.excel'),
            'pdf' => __('monitoring.pdf_reports.export_formats.pdf'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function periodTypeOptions(): array
    {
        return [
            'daily' => __('monitoring.reliability_metrics.period_types.daily'),
            'weekly' => __('monitoring.reliability_metrics.period_types.weekly'),
            'monthly' => __('monitoring.reliability_metrics.period_types.monthly'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function bucketSizeOptions(): array
    {
        return [
            'hourly' => __('monitoring.reports.bucket_sizes.hourly'),
            'daily' => __('monitoring.reports.bucket_sizes.daily'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function chartTypeOptions(): array
    {
        return [
            'i_chart' => __('monitoring.chart_types.i_chart'),
            'mr_chart' => __('monitoring.chart_types.mr_chart'),
            'p_chart' => __('monitoring.chart_types.p_chart'),
            'c_chart' => __('monitoring.chart_types.c_chart'),
            'u_chart' => __('monitoring.chart_types.u_chart'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function metricNameOptions(): array
    {
        return [
            'response_time_ms' => __('monitoring.metrics.response_time_ms'),
            'failure_proportion' => __('monitoring.metrics.failure_proportion'),
            'failed_checks_count' => __('monitoring.metrics.failed_checks_count'),
            'failures_per_check' => __('monitoring.metrics.failures_per_check'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function severityOptions(): array
    {
        return [
            'low' => __('monitoring.service_incidents.severities.low'),
            'medium' => __('monitoring.service_incidents.severities.medium'),
            'high' => __('monitoring.service_incidents.severities.high'),
            'critical' => __('monitoring.service_incidents.severities.critical'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function incidentTypeOptions(): array
    {
        return [
            'down' => __('monitoring.service_incidents.incident_types.down'),
            'slow' => __('monitoring.service_incidents.incident_types.slow'),
            'server_error' => __('monitoring.service_incidents.incident_types.server_error'),
            'timeout' => __('monitoring.service_incidents.incident_types.timeout'),
            'connection_error' => __('monitoring.service_incidents.incident_types.connection_error'),
            'keyword_missing' => __('monitoring.service_incidents.incident_types.keyword_missing'),
            'mixed' => __('monitoring.service_incidents.incident_types.mixed'),
            'unknown' => __('monitoring.service_incidents.incident_types.unknown'),
        ];
    }
}
