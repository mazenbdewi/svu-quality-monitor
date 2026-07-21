@php
    $locale = app()->getLocale();
    $isRtl = $locale === 'ar';
    $direction = $isRtl ? 'rtl' : 'ltr';
    $align = $isRtl ? 'right' : 'left';
    $summaryLabels = [
        'total_services',
        'active_services',
        'total_checks',
        'successful_checks',
        'failed_checks',
        'slow_checks',
        'open_incidents',
        'closed_incidents',
        'average_availability',
        'average_response_time',
        'total_control_charts',
        'out_of_control_points_count',
    ];
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            direction: {{ $direction }};
            text-align: {{ $align }};
            color: #111827;
            font-size: 12px;
            line-height: 1.6;
            margin: 28px;
        }

        h1, h2, h3, p {
            margin: 0;
        }

        h1 {
            font-size: 22px;
            margin-bottom: 8px;
        }

        h2 {
            font-size: 16px;
            margin: 24px 0 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid #d1d5db;
        }

        h3 {
            font-size: 13px;
            margin-bottom: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            page-break-inside: auto;
        }

        th, td {
            border: 1px solid #d1d5db;
            padding: 6px 8px;
            vertical-align: top;
        }

        th {
            background: #f3f4f6;
            font-weight: bold;
        }

        .cover {
            border: 1px solid #d1d5db;
            padding: 18px;
            margin-bottom: 18px;
            background: #f9fafb;
        }

        .muted {
            color: #4b5563;
        }

        .section {
            page-break-inside: avoid;
        }

        .page-break {
            page-break-before: always;
        }

        .note {
            border: 1px solid #d1d5db;
            background: #f9fafb;
            padding: 12px;
            margin-top: 12px;
        }

        .empty {
            color: #6b7280;
            font-style: italic;
            margin-top: 6px;
        }
    </style>
</head>
<body>
    <section class="cover">
        <h1>{{ __('monitoring.pdf_reports.comprehensive_title') }}</h1>
        <p class="muted">{{ __('monitoring.pdf_reports.system_subtitle') }}</p>
        <table>
            <tbody>
                <tr>
                    <th>{{ __('monitoring.pdf_reports.generated_at') }}</th>
                    <td>{{ $report->dateTime($generatedAt) }}</td>
                </tr>
                <tr>
                    <th>{{ __('monitoring.pdf_reports.report_period') }}</th>
                    <td>{{ $dateRange }}</td>
                </tr>
                <tr>
                    <th>{{ __('monitoring.pdf_reports.selected_service') }}</th>
                    <td>{{ $selectedService }}</td>
                </tr>
            </tbody>
        </table>
    </section>

    <section class="section">
        <h2>{{ __('monitoring.pdf_reports.executive_summary') }}</h2>
        <table>
            <tbody>
                @foreach ($summaryLabels as $summaryKey)
                    <tr>
                        <th>{{ __("monitoring.pdf_reports.columns.{$summaryKey}") }}</th>
                        <td>{{ $summary[$summaryKey] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="section">
        <h2>{{ __('monitoring.interpretation.sections.key_findings_and_recommendations') }}</h2>
        @if (empty($keyFindings))
            <p class="empty">{{ __('monitoring.pdf_reports.no_data') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ __('monitoring.interpretation.labels.finding') }}</th>
                        <th>{{ __('monitoring.interpretation.labels.recommendation') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($keyFindings as $finding)
                        <tr>
                            <td>
                                <strong>{{ $finding['title'] }}</strong><br>
                                {{ $finding['message'] }}
                            </td>
                            <td>{{ $finding['recommendation'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="section">
        <h2>{{ __('monitoring.pdf_reports.service_checks_summary') }}</h2>
        @if ($serviceChecksSummary->isEmpty())
            <p class="empty">{{ __('monitoring.pdf_reports.no_data') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ __('monitoring.pdf_reports.columns.service_name') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.total_checks') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.successful_checks') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.failed_checks') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.slow_checks') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.average_response_time') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($serviceChecksSummary as $row)
                        <tr>
                            <td>{{ $row->name }}</td>
                            <td>{{ number_format((int) $row->total_checks) }}</td>
                            <td>{{ number_format((int) $row->successful_checks) }}</td>
                            <td>{{ number_format((int) $row->failed_checks) }}</td>
                            <td>{{ number_format((int) $row->slow_checks) }}</td>
                            <td>{{ $report->milliseconds($row->average_response_time) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="section">
        <h2>{{ __('monitoring.pdf_reports.incidents_summary') }}</h2>
        @if ($incidentsSummary->isEmpty())
            <p class="empty">{{ __('monitoring.pdf_reports.no_data') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ __('monitoring.pdf_reports.columns.service_name') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.open_incidents') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.closed_incidents') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.total_incidents') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.average_duration_minutes') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($incidentsSummary as $row)
                        <tr>
                            <td>{{ $row->name }}</td>
                            <td>{{ number_format((int) $row->open_incidents) }}</td>
                            <td>{{ number_format((int) $row->closed_incidents) }}</td>
                            <td>{{ number_format((int) $row->total_incidents) }}</td>
                            <td>{{ $report->number($row->average_duration_minutes, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="section page-break">
        <h2>{{ __('monitoring.pdf_reports.reliability_metrics_summary') }}</h2>
        @if ($reliabilityMetricsSummary->isEmpty())
            <p class="empty">{{ __('monitoring.pdf_reports.no_data') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ __('monitoring.pdf_reports.columns.service_name') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.availability_percent') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.mtbf') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.mttr') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.failure_rate') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.period_start') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.period_end') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reliabilityMetricsSummary as $row)
                        <tr>
                            <td>{{ $row->monitoredService?->name }}</td>
                            <td>{{ $report->percent($row->availability_percent, 4) }}</td>
                            <td>{{ $report->number($row->mtbf_minutes, 2) }}</td>
                            <td>{{ $report->number($row->mttr_minutes, 2) }}</td>
                            <td>{{ $report->number($row->failure_rate, 8) }}</td>
                            <td>{{ $report->date($row->period_start) }}</td>
                            <td>{{ $report->date($row->period_end) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="section">
        <h2>{{ __('monitoring.pdf_reports.control_charts_summary') }}</h2>
        @if ($controlChartsSummary->isEmpty())
            <p class="empty">{{ __('monitoring.pdf_reports.no_data') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ __('monitoring.pdf_reports.columns.service_name') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.chart_type') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.metric_name') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.points_count') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.out_of_control_count') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.period_start') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.period_end') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($controlChartsSummary as $row)
                        <tr>
                            <td>{{ $row->monitoredService?->name }}</td>
                            <td>{{ $report->chartTypeLabel($row->chart_type) }}</td>
                            <td>{{ $report->metricNameLabel($row->metric_name) }}</td>
                            <td>{{ number_format((int) $row->points_count) }}</td>
                            <td>{{ number_format((int) $row->out_of_control_count) }}</td>
                            <td>{{ $report->date($row->period_start) }}</td>
                            <td>{{ $report->date($row->period_end) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="section page-break">
        <h2>{{ __('monitoring.pdf_reports.out_of_control_points') }}</h2>
        @if ($outOfControlPoints->isEmpty())
            <p class="empty">{{ __('monitoring.pdf_reports.no_data') }}</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>{{ __('monitoring.pdf_reports.columns.service_name') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.chart_type') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.metric_name') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.point_time') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.value') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.ucl') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.lcl') }}</th>
                        <th>{{ __('monitoring.pdf_reports.columns.signal_type') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($outOfControlPoints as $row)
                        <tr>
                            <td>{{ $row->controlChart?->monitoredService?->name }}</td>
                            <td>{{ $report->chartTypeLabel($row->controlChart?->chart_type) }}</td>
                            <td>{{ $report->metricNameLabel($row->controlChart?->metric_name) }}</td>
                            <td>{{ $report->dateTime($row->point_time) }}</td>
                            <td>{{ $report->number($row->value, 4) }}</td>
                            <td>{{ $report->number($row->ucl, 4) }}</td>
                            <td>{{ $report->number($row->lcl, 4) }}</td>
                            <td>{{ $report->signalTypeLabel($row->signal_type) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="section">
        <h2>{{ __('monitoring.pdf_reports.interpretation_notes') }}</h2>
        <div class="note">{{ __('monitoring.pdf_reports.interpretation_notes_body') }}</div>
    </section>

    <section class="section">
        <h2>{{ __('monitoring.pdf_reports.safe_use_note') }}</h2>
        <div class="note">{{ __('monitoring.pdf_reports.safe_use_note_body') }}</div>
    </section>
</body>
</html>
