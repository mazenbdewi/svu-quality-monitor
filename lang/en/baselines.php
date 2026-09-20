<?php

return [
    'title' => 'Phase I Baselines', 'create' => 'Create Phase I Baseline', 'service' => 'Service', 'chart' => 'Chart',
    'version' => 'Version', 'status' => 'Status', 'start' => 'Phase I start (selected timezone)', 'end' => 'Phase I end (exclusive, selected timezone)',
    'timezone' => 'Analysis timezone', 'aggregation' => 'P aggregation interval', 'sufficiency' => 'Data sufficiency',
    'review' => 'Review', 'approve' => 'Approve', 'retire' => 'Retire', 'reject' => 'Reject', 'export' => 'Export frozen Phase I sample',
    'review_notes' => 'Stability review: distribution, signals, gaps, configuration changes and period selection rationale',
    'acknowledge' => 'I reviewed coverage, signals and configuration warnings; preliminary/analyzable is not proof of stability.',
    'reason' => 'Decision rationale', 'scope' => 'Phase I review only. Approval identifies a study reference; it does not certify a perfect or stable process.',
    'context' => 'Frozen research context', 'counts' => 'Candidates, included and exclusions', 'parameters' => 'Frozen parameters',
    'coverage' => 'Coverage and missing periods', 'configuration' => 'Safe measurement configuration', 'warnings' => 'Configuration review',
    'history_limit' => 'Audit history may not cover direct database edits or the full historical configuration. The configuration snapshot is taken at creation.',
    'compatible' => 'Current measurement fingerprint matches', 'incompatible' => 'Warning: measurement configuration has changed since baseline creation.',
    'graph' => 'Phase I exploratory points (blue values, red exceedances, gray estimated limits)',
    'no_points' => 'No computable points', 'cutoff' => 'Data cutoff (UTC)', 'review_context' => 'Stability and time-coverage review',
    'bucket_start' => 'Bucket start (UTC)', 'observed' => 'Observed', 'missing' => 'Missing',
    'lifecycle' => 'Review, approval and validity history',
];
