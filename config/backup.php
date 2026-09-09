<?php

return [
    'directory' => env('BACKUP_DIRECTORY', '/var/backups/svu-quality-monitor'),
    'files' => ['public' => storage_path('app/public'), 'private' => storage_path('app/private')],
    'keep_last' => (int) env('BACKUP_KEEP_LAST', 14),
    'minimum_free_bytes' => (int) env('BACKUP_MINIMUM_FREE_BYTES', 1073741824),
    'warning_hours' => (int) env('BACKUP_WARNING_HOURS', 30),
    'down_hours' => (int) env('BACKUP_DOWN_HOURS', 54),
    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL', 'mysql'),
    'timeout' => (int) env('BACKUP_TIMEOUT', 1800),
];
