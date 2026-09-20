<?php

namespace Tests\Feature;

use App\Exports\Reports\ComprehensiveResearchReportExport;
use App\Exports\Reports\MinitabReadyExport;
use App\Exports\Reports\ReportExport;
use App\Exports\Reports\Sheets\MinitabBucketedChecksSheet;
use App\Exports\Reports\Sheets\MinitabRawChecksSheet;
use App\Exports\Reports\Sheets\ServiceChecksSheet;
use App\Filament\Pages\ReportsPage;
use App\Models\ControlChart;
use App\Models\ControlChartPoint;
use App\Models\MonitoredService;
use App\Models\ReliabilityMetric;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Models\User;
use App\Reports\ComprehensivePdfReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ReportsExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('operator');
        $this->actingAs($user);
    }

    public function test_reports_page_renders_with_default_report_type(): void
    {
        Livewire::test(ReportsPage::class)
            ->assertSet('data.report_type', 'service_checks')
            ->assertSet('data.export_format', 'excel')
            ->assertSee(__('monitoring.reports.page.section_title'));
    }

    public function test_service_checks_report_uses_localized_headings_and_mapped_values(): void
    {
        app()->setLocale('en');

        $service = MonitoredService::factory()->create([
            'name' => 'SVU Portal',
        ]);

        ServiceCheck::query()->create([
            'monitored_service_id' => $service->id,
            'checked_at' => now(),
            'status_code' => 200,
            'response_time_ms' => 350,
            'is_success' => true,
            'is_slow' => false,
            'expected_keyword_found' => true,
        ]);

        $sheet = new ServiceChecksSheet([
            'service_id' => $service->id,
            'status' => 'success',
            'slow_status' => 'not_slow',
        ]);

        $this->assertSame('Service Name', $sheet->headings()[0]);
        $this->assertSame(1, $sheet->query()->count());

        $row = $sheet->map($sheet->query()->first());

        $this->assertSame('SVU Portal', $row[0]);
        $this->assertSame('Yes', $row[4]);
        $this->assertSame('No', $row[5]);
    }

    public function test_report_exports_generate_xlsx_content(): void
    {
        $this->seedReportData();

        $content = Excel::raw(new ReportExport('service_checks'), ExcelWriter::XLSX);

        $this->assertNotEmpty($content);
    }

    public function test_comprehensive_report_exports_generate_xlsx_content(): void
    {
        $this->seedReportData();

        $content = Excel::raw(new ComprehensiveResearchReportExport, ExcelWriter::XLSX);

        $this->assertNotEmpty($content);
    }

    public function test_minitab_ready_export_generates_xlsx_content(): void
    {
        $this->seedReportData();

        $content = Excel::raw(new MinitabReadyExport, ExcelWriter::XLSX);

        $this->assertNotEmpty($content);
    }

    public function test_minitab_ready_export_download_uses_expected_filename(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00'));

        try {
            $this->seedReportData();

            Livewire::test(ReportsPage::class)
                ->set('data.report_type', 'minitab_ready')
                ->set('data.export_format', 'excel')
                ->set('data.bucket_size', 'hourly')
                ->call('export')
                ->assertFileDownloaded('minitab-ready-export-2026-07-20.xlsx');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_minitab_raw_checks_sheet_maps_numeric_flags(): void
    {
        $service = MonitoredService::factory()->create([
            'name' => 'SVU Portal',
            'category' => 'LMS',
        ]);

        ServiceCheck::query()->create([
            'monitored_service_id' => $service->id,
            'source' => 'automatic', 'check_type' => 'http',
            'checked_at' => Carbon::parse('2026-07-20 09:15:00'),
            'status_code' => 500,
            'response_time_ms' => 2500,
            'is_success' => false,
            'is_slow' => true,
            'error_type' => 'server_error',
            'expected_keyword_found' => false,
        ]);

        $sheet = new MinitabRawChecksSheet(['date_from' => '2026-07-20', 'date_to' => '2026-07-20']);
        $row = $sheet->map($sheet->query()->first());

        $this->assertSame('service_name', $sheet->headings()[0]);
        $this->assertSame('SVU Portal', $row[0]);
        $this->assertSame('2026-07-20 09:15:00', $row[2]);
        $this->assertSame(9, $row[4]);
        $this->assertSame(0, $row[7]);
        $this->assertSame(1, $row[8]);
        $this->assertSame(1, $row[9]);
        $this->assertSame(1, $row[10]);
        $this->assertSame(0, $row[11]);
    }

    public function test_minitab_bucketed_checks_sheet_calculates_grouped_counts_and_proportions(): void
    {
        $service = MonitoredService::factory()->create([
            'name' => 'SVU Portal',
        ]);

        $this->createMinitabCheck($service, '2026-07-20 09:05:00', isSuccess: true, isSlow: false, responseTime: 300);
        $this->createMinitabCheck($service, '2026-07-20 09:15:00', isSuccess: false, isSlow: false, responseTime: 800);
        $this->createMinitabCheck($service, '2026-07-20 09:45:00', isSuccess: true, isSlow: true, responseTime: 2500);

        $row = (new MinitabBucketedChecksSheet([
            'bucket_size' => 'hourly', 'date_from' => '2026-07-20', 'date_to' => '2026-07-20',
        ]))->collection()->first(fn ($row) => $row[4] > 0);

        $this->assertSame('SVU Portal', $row[0]);
        $this->assertSame('2026-07-20T09:00:00+00:00', $row[1]);
        $this->assertSame(3, $row[4]);
        $this->assertSame(2, $row[7]);
        $this->assertSame(1, $row[9]);
        $this->assertEqualsWithDelta(2 / 3, $row[8], 0.000001);
    }

    public function test_comprehensive_report_generates_pdf_content(): void
    {
        $this->seedReportData();

        $report = new ComprehensivePdfReport;
        $content = Pdf::loadView('reports.comprehensive-pdf', [
            'report' => $report,
            ...$report->data(),
        ])->output();

        $this->assertStringStartsWith('%PDF', $content);
        $this->assertNotEmpty($content);
    }

    public function test_comprehensive_report_can_be_downloaded_as_pdf(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00'));

        try {
            $this->seedReportData();

            Livewire::test(ReportsPage::class)
                ->set('data.report_type', 'comprehensive')
                ->set('data.export_format', 'pdf')
                ->call('export')
                ->assertFileDownloaded('comprehensive-research-report-2026-07-20.pdf');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_pdf_export_is_available_only_for_comprehensive_report(): void
    {
        Livewire::test(ReportsPage::class)
            ->set('data.report_type', 'service_checks')
            ->set('data.export_format', 'pdf')
            ->call('export')
            ->assertHasErrors([
                'data.export_format' => __('monitoring.pdf_reports.pdf_only_comprehensive'),
            ]);
    }

    public function test_minitab_ready_export_rejects_pdf_format(): void
    {
        Livewire::test(ReportsPage::class)
            ->set('data.report_type', 'minitab_ready')
            ->set('data.export_format', 'pdf')
            ->call('export')
            ->assertHasErrors([
                'data.export_format' => __('monitoring.reports.validation.minitab_excel_only'),
            ]);
    }

    private function seedReportData(): void
    {
        $service = MonitoredService::factory()->create([
            'is_active' => true,
        ]);

        ServiceCheck::query()->create([
            'monitored_service_id' => $service->id,
            'checked_at' => now(),
            'status_code' => 500,
            'response_time_ms' => 2500,
            'is_success' => false,
            'is_slow' => true,
            'error_type' => 'server_error',
            'error_message' => 'Server error',
            'expected_keyword_found' => false,
        ]);

        ServiceIncident::query()->create([
            'monitored_service_id' => $service->id,
            'started_at' => now()->subMinutes(30),
            'ended_at' => now(),
            'duration_minutes' => 30,
            'incident_type' => 'server_error',
            'severity' => 'high',
            'status' => 'closed',
            'root_cause' => 'Server overload',
            'corrective_action' => 'Restarted service',
            'notes' => 'Resolved',
        ]);

        ReliabilityMetric::query()->create([
            'monitored_service_id' => $service->id,
            'period_type' => 'daily',
            'period_start' => now()->startOfDay(),
            'period_end' => now()->endOfDay(),
            'total_checks' => 10,
            'successful_checks' => 9,
            'failed_checks' => 1,
            'incidents_count' => 1,
            'uptime_minutes' => 1410,
            'downtime_minutes' => 30,
            'availability_percent' => 97.9167,
            'mtbf_minutes' => 1410,
            'mttr_minutes' => 30,
            'failure_rate' => 0.00070922,
            'calculated_at' => now(),
        ]);

        $chart = ControlChart::query()->create([
            'monitored_service_id' => $service->id,
            'chart_type' => 'i_chart',
            'metric_name' => 'response_time_ms',
            'period_type' => 'daily',
            'period_start' => now()->startOfDay(),
            'period_end' => now()->endOfDay(),
            'center_line' => 500,
            'ucl' => 1000,
            'lcl' => 0,
            'points_count' => 1,
            'out_of_control_count' => 1,
            'calculated_at' => now(),
        ]);

        ControlChartPoint::query()->create([
            'control_chart_id' => $chart->id,
            'point_time' => now(),
            'value' => 1500,
            'center_line' => 500,
            'ucl' => 1000,
            'lcl' => 0,
            'sample_size' => 1,
            'failed_count' => 1,
            'is_out_of_control' => true,
            'signal_type' => 'above_ucl',
            'note' => 'Above limit',
        ]);
    }

    private function createMinitabCheck(MonitoredService $service, string $checkedAt, bool $isSuccess, bool $isSlow, int $responseTime): void
    {
        ServiceCheck::query()->create([
            'monitored_service_id' => $service->id,
            'source' => 'automatic', 'check_type' => 'http',
            'checked_at' => Carbon::parse($checkedAt),
            'status_code' => $isSuccess ? 200 : 500,
            'response_time_ms' => $responseTime,
            'is_success' => $isSuccess,
            'is_slow' => $isSlow,
            'expected_keyword_found' => $isSuccess,
        ]);
    }
}
