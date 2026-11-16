<?php

return [
    'slo' => [
        // Deployment gate only; reviewed/approved research and read-only evaluation remain available.
        'automatic_preparation_enabled' => (bool) env('SLO_AUTO_PREPARATION_ENABLED', false),
    ],
    'spc' => [
        'episode_gap_minutes' => (int) env('SPC_SIGNAL_EPISODE_GAP_MINUTES', 30),
        'association_horizon_minutes' => (int) env('SPC_INCIDENT_ASSOCIATION_HORIZON_MINUTES', 60),
        // Exploratory reporting policy, not a universal scientific minimum.
        'exploratory_min_points' => 20,
        // Display-only cap. Statistical calculations always use the full population.
        'visualization_max_points' => 1000,
        /*
         * Project research-policy parameters, not mandatory ISO requirements.
         * Window expansion uses quantities only; signals never steer selection.
         */
        'automatic_baseline' => [
            'version' => 'phase1-automatic-v1',
            'window_days' => [14, 21, 28],
            'minimum_i_observations' => 20,
            'minimum_mr_pairs' => 20,
            'minimum_p_observations' => 20,
            'minimum_p_subgroups' => 8,
            'p_aggregation' => 'hourly',
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | Background processing health thresholds
    |--------------------------------------------------------------------------
    |
    | The scheduler reports once per minute. A short grace period prevents a
    | transient delay from being displayed as an outage. The queue worker emits
    | a heartbeat independently of jobs while it is polling the queue.
    |
    */
    'scheduler' => [
        'expected_interval_seconds' => (int) env('MONITORING_SCHEDULER_EXPECTED_INTERVAL_SECONDS', 60),
        'warning_after_seconds' => (int) env('MONITORING_SCHEDULER_WARNING_AFTER_SECONDS', 180),
        'down_after_seconds' => (int) env('MONITORING_SCHEDULER_DOWN_AFTER_SECONDS', 300),
    ],

    'queue' => [
        'expected_interval_seconds' => (int) env('MONITORING_QUEUE_EXPECTED_INTERVAL_SECONDS', 30),
        'warning_after_seconds' => (int) env('MONITORING_QUEUE_WARNING_AFTER_SECONDS', 90),
        'down_after_seconds' => (int) env('MONITORING_QUEUE_DOWN_AFTER_SECONDS', 180),
        'heartbeat_write_interval_seconds' => (int) env('MONITORING_QUEUE_HEARTBEAT_WRITE_INTERVAL_SECONDS', 15),
    ],

    'incidents' => [
        'flapping_transition_count' => (int) env('MONITORING_FLAPPING_TRANSITION_COUNT', 4),
        'flapping_window_seconds' => (int) env('MONITORING_FLAPPING_WINDOW_SECONDS', 900),
    ],
    'sla' => [
        'at_risk_error_budget_percent' => (int) env('MONITORING_SLA_AT_RISK_ERROR_BUDGET_PERCENT', 80),
    ],
    'notifications' => [
        'ssl' => ['enabled' => (bool) env('MONITORING_SSL_EXPIRY_NOTIFICATIONS_ENABLED', true), 'thresholds' => [30, 14, 7, 3, 1]],
    ],
];
