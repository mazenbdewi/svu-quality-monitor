<?php

return [
    'title' => 'Performance Analysis & Control Charts', 'open' => 'Performance Analysis & Charts', 'create_baseline' => 'Create Baseline from This Period',
    'intro' => 'Review eligible historical monitoring data. This page is read-only and does not create a Phase I baseline.',
    'service' => 'Monitored service', 'window' => 'Analysis period', 'days' => '{1}:count day|[2,*]:count days', 'custom' => 'Custom (exclusive end)',
    'start' => 'Custom start', 'end' => 'Custom end (exclusive)', 'calendar_policy' => 'Preset periods use complete local calendar days in :timezone; storage remains UTC.',
    'select_service' => 'Select a service to begin.', 'missing_period' => 'Enter both custom dates to analyze a period.', 'unsupported' => 'This service uses :type checks. Historical research analysis supports HTTP/API services only.',
    'invalid_range' => 'The selected period is invalid. Choose a positive period that is not entirely in the future.', 'invalid_reversed' => 'The custom end must be after the custom start.', 'future_only' => 'A custom period entirely in the future cannot be analyzed.',
    'effective_period' => 'Effective period: :start → :end (:timezone), end exclusive.', 'eligible' => 'Eligible automatic HTTP/API checks', 'latency' => 'Eligible latency observations', 'problematic' => 'Problematic checks', 'mean_latency' => 'Mean eligible response time', 'coverage' => 'Monitoring coverage', 'missing' => 'Missing checks / buckets',
    'state_no_data' => 'No eligible observations are available in this period.', 'state_insufficient' => 'Observations exist, but monitoring coverage is incomplete. Missing data is unknown, not successful.', 'state_preliminary' => 'The period has fewer observations than the exploratory sufficiency policy.',
    'exploratory_notice' => 'These are read-only exploratory summaries. They are not approved Phase I limits and do not activate Phase II detection.',
    'visualization_downsampled' => 'The calculation used all eligible observations. The chart shows a reduced visual set while preserving gaps, extremes, and out-of-control points.',
    'exploratory_warning' => 'Exploratory analysis — limits are estimated from the selected data. They are not an approved Phase I baseline or live Phase II signals.',
    'chart_actual' => 'Observed value', 'chart_center_line' => 'Center line', 'chart_ucl' => 'UCL', 'chart_lcl' => 'LCL',
    'chart_status_no_data' => 'No eligible observations are available for this chart.', 'chart_status_insufficient' => 'At least two observations are needed for this chart.', 'chart_status_preliminary' => 'The chart is exploratory because the selected period is below the sufficiency policy or has missing observations.',
    'tooltip_sample_size' => 'Sample size', 'tooltip_problematic' => 'Problematic checks',
    'sections' => ['response_time' => 'Response Time', 'problematic_checks' => 'Problematic Checks'],
    'charts' => [
        'i_chart' => ['title' => 'I Chart — Individual response times', 'description' => 'Each point is one eligible automatic HTTP/API response-time observation, ordered by check time.'],
        'mr_chart' => ['title' => 'MR Chart — Moving range', 'description' => 'Each point is the absolute difference from the preceding eligible observation in this selected period.'],
        'p_chart' => ['title' => 'P Chart — Problematic check proportion', 'description' => 'Each hourly subgroup shows problematic = not successful or slow. Missing subgroups remain gaps.'],
    ],
];
