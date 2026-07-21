# Minitab Validation Guide

## Purpose

SVU Quality Monitor remains the main applied system for collecting service-check data, calculating reliability indicators, detecting incidents, and building control charts.

Minitab can be used as an external statistical validation tool for selected results, especially control chart outputs. It does not replace the developed system. It provides an external reference for comparing selected chart limits, signals, and grouped statistics.

## Export Steps

1. Open **Reports & Export**.
2. Select **Minitab-ready Export**.
3. Choose a monitored service if validation should focus on one service.
4. Choose a date range if validation should focus on a specific study period.
5. Select bucket size: **Hourly** or **Daily**.
6. Export the Excel file.

The exported file uses simple English column headings and numeric flags so it can be imported into Minitab more easily.

## Sheet Usage

### Raw Checks

Use the `Raw Checks` sheet for raw service-check observations.

Recommended Minitab use:
- Create an I-MR chart for `response_time_ms`.
- Run descriptive statistics for response time.
- Run Pareto analysis using `error_type`.

### Bucketed Checks

Use the `Bucketed Checks` sheet for grouped observations by time bucket.

Recommended Minitab use:
- Validate p-chart behavior using `failure_proportion` or `problematic_proportion`.
- Validate c-chart behavior using `failed_count` or `problematic_count`.
- Validate u-chart behavior using `problematic_count` with `sample_size`.
- Compare service behavior across hourly or daily buckets.

### Control Chart Summary

Use the `Control Chart Summary` sheet to compare SVU Quality Monitor control chart outputs with Minitab results.

Compare:
- `center_line`
- `ucl`
- `lcl`
- `points_count`
- `out_of_control_count`

### Out Of Control Points

Use the `Out Of Control Points` sheet to review signals that need interpretation.

Recommended use:
- Compare out-of-control signals with Minitab chart results.
- Compare signal timing with incidents.
- Compare signal timing with raw service-check records.

### Reliability Metrics

Use the `Reliability Metrics` sheet when reliability indicators need external review or comparison.

Useful columns include:
- `availability_percent`
- `mtbf_minutes`
- `mttr_minutes`
- `failure_rate`
- `downtime_minutes`

## Suggested Thesis Wording

Arabic:

> تم استخدام النظام المطوّر لجمع البيانات وحساب مؤشرات الموثوقية وبناء خرائط المراقبة، كما تم تصدير بيانات مهيأة إلى برنامج Minitab للتحقق من بعض النتائج الإحصائية ومقارنتها بأداة إحصائية مرجعية.

English:

> The developed system was used to collect data, calculate reliability indicators, and build control charts. Minitab-ready data was also exported to validate selected statistical results and compare them with an external statistical reference tool.
