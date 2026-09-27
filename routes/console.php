<?php

use App\Services\InstitutionTimezone;
use App\Services\SystemHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Resolve database settings only when the scheduler is requested, not during artisan bootstrap.
app()->afterResolving(Schedule::class, function ($schedule): void {
    $timezone = app(InstitutionTimezone::class)->name();
    $schedule->command('services:check-due')
        ->everyMinute()
        ->withoutOverlapping();

    $schedule->call(fn (): mixed => app(SystemHealthService::class)->recordSchedulerHeartbeat())
        ->name('system-health:scheduler-heartbeat')
        ->everyMinute()
        ->evenInMaintenanceMode()
        ->withoutOverlapping();

    $schedule->command('system:check-background-health')
        ->everyFiveMinutes()
        ->withoutOverlapping();

    $schedule->command('reliability:calculate --period=daily')
        ->timezone($timezone)
        ->dailyAt('00:10')
        ->withoutOverlapping();

    $schedule->command('sla:calculate')
        ->timezone($timezone)
        ->dailyAt('00:20')
        ->withoutOverlapping();

    $schedule->command('control-charts:calculate --period=daily')
        ->timezone($timezone)
        ->dailyAt('00:25')
        ->withoutOverlapping();

    $schedule->command('backup:create --scheduled')
        ->timezone($timezone)
        ->dailyAt('02:00')
        ->withoutOverlapping(120)
        ->onFailure(fn () => Log::error('Scheduled backup command failed.'));

    $schedule->command('backup:cleanup --scheduled')
        ->timezone($timezone)
        ->dailyAt('03:00')
        ->withoutOverlapping(120);

    $schedule->command('spc:evaluate')->everyMinute()->withoutOverlapping();
});
