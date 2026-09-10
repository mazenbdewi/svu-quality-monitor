@php
    $formatTime = fn ($time): string => $time?->format('Y-m-d H:i:s') ?? __('monitoring.operations.health.never');
    $formatCount = fn ($count): string => $count === null ? __('monitoring.operations.health.unavailable') : number_format($count);
    $commands = [
        'scheduler' => 'php artisan schedule:work',
        'cron' => '* * * * * cd /path/to/svu-quality-monitor && php artisan schedule:run >> /dev/null 2>&1',
        'manual_check' => 'php artisan services:check-due',
        'reliability' => 'php artisan reliability:calculate --period=daily',
        'control_charts' => 'php artisan control-charts:calculate --period=daily',
    ];
@endphp
<x-filament-panels::page>
    <div class="grid min-w-0 gap-6 lg:grid-cols-2">
        @foreach (['scheduler', 'queue'] as $healthPart)
            @php($healthPartHealth = $health[$healthPart])
            <x-filament::section>
                <x-slot name="heading">{{ __('monitoring.operations.health.'.$healthPart.'.title') }}</x-slot>
                <x-slot name="description">{{ __('ux.help.'.$healthPart) }}</x-slot>
                <x-filament::badge :color="match ($healthPartHealth['status']) { 'healthy' => 'success', 'warning' => 'warning', default => 'danger' }">{{ __('monitoring.operations.health.statuses.'.$healthPartHealth['status']) }}</x-filament::badge>
                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex flex-wrap justify-between gap-2"><dt>{{ __('monitoring.operations.health.last_heartbeat') }}</dt><dd><bdi>{{ $formatTime($healthPartHealth['last_heartbeat']) }}</bdi></dd></div>
                    @if ($healthPart === 'scheduler')
                        <div class="flex flex-wrap justify-between gap-2"><dt>{{ __('monitoring.operations.health.expected_interval') }}</dt><dd>{{ number_format($healthPartHealth['expected_interval_seconds']) }} {{ __('monitoring.operations.health.seconds') }}</dd></div>
                    @else
                        @foreach (['last_success', 'last_failure', 'pending_jobs', 'failed_jobs'] as $field)
                            <div class="flex flex-wrap justify-between gap-2"><dt>{{ __('monitoring.operations.health.queue.'.$field) }}</dt><dd><bdi>{{ in_array($field, ['last_success', 'last_failure'], true) ? $formatTime($healthPartHealth[$field]) : $formatCount($healthPartHealth[$field]) }}</bdi></dd></div>
                        @endforeach
                    @endif
                </dl>
            </x-filament::section>
        @endforeach
        <x-filament::section>
            <x-slot name="heading">{{ __('monitoring.operations.health.monitoring.title') }}</x-slot>
            <dl class="space-y-3 text-sm">
                @foreach (['last_success' => 'last_automatic_success', 'last_failure' => 'last_automatic_failure', 'active_services' => 'active_services', 'due_services' => 'due_services'] as $label => $field)
                    <div class="flex flex-wrap justify-between gap-2"><dt>{{ __('monitoring.operations.health.monitoring.'.$label) }}</dt><dd><bdi>{{ str_starts_with($field, 'last_') ? $formatTime($health['monitoring'][$field]) : $formatCount($health['monitoring'][$field]) }}</bdi></dd></div>
                @endforeach
            </dl>
        </x-filament::section>
        <x-filament::section>
            <x-slot name="heading">{{ __('monitoring.operations.health.application.title') }}</x-slot>
            <dl class="space-y-3 text-sm">
                <div class="flex flex-wrap justify-between gap-2"><dt>{{ __('monitoring.operations.health.application.database') }}</dt><dd><x-filament::badge :color="$health['application']['database_healthy'] ? 'success' : 'danger'">{{ __('monitoring.operations.health.database_status.'.($health['application']['database_healthy'] ? 'healthy' : 'down')) }}</x-filament::badge></dd></div>
                @foreach (['environment', 'version', 'server_time', 'timezone'] as $field)
                    <div class="flex flex-wrap justify-between gap-2"><dt>{{ __('monitoring.operations.health.application.'.$field) }}</dt><dd><bdi>{{ $field === 'server_time' ? $formatTime($health['application'][$field]) : $health['application'][$field] }}</bdi></dd></div>
                @endforeach
            </dl>
        </x-filament::section>
    </div>
    @can('backups.view')
        @php($backupHealth = $this->backupHealth())
        <x-filament::section :heading="__('backup.title')" wire:poll.60s>
            <x-filament::badge :color="match ($backupHealth['status']) { 'healthy' => 'success', 'warning' => 'warning', default => 'danger' }">{{ __('monitoring.operations.health.statuses.'.$backupHealth['status']) }}</x-filament::badge>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-gray-600 dark:text-gray-400">{{ __('backup.last_success') }}</dt><dd class="mt-1"><bdi>{{ $backupHealth['success']['finished_at'] ?? '—' }}</bdi></dd></div>
                <div><dt class="text-gray-600 dark:text-gray-400">{{ __('backup.last_failure') }}</dt><dd class="mt-1"><bdi>{{ $backupHealth['failure']['finished_at'] ?? '—' }}</bdi></dd></div>
                <div><dt class="text-gray-600 dark:text-gray-400">{{ __('backup.age') }}</dt><dd class="mt-1">{{ $backupHealth['age'] === null ? '—' : number_format($backupHealth['age'], 1) }}</dd></div>
                <div><dt class="text-gray-600 dark:text-gray-400">{{ __('backup.size') }}</dt><dd class="mt-1">{{ $backupHealth['success']['size'] ?? '—' }}</dd></div>
            </dl>
        </x-filament::section>
    @endcan
    <x-filament::section collapsible collapsed>
        <x-slot name="heading">{{ __('monitoring.operations.commands_title') }}</x-slot>
        <x-slot name="description">{{ __('monitoring.operations.health.rules') }}</x-slot>
        <div class="min-w-0 space-y-5">
            @foreach ($commands as $key => $command)
                <div class="min-w-0"><h3 class="text-sm font-medium">{{ __('monitoring.operations.commands.'.$key) }}</h3><pre class="mt-2 max-w-full overflow-x-auto rounded-lg bg-gray-100 p-3 text-sm dark:bg-gray-950" dir="ltr"><code>{{ $command }}</code></pre></div>
            @endforeach
            <h3 class="font-medium">{{ __('monitoring.operations.backup_title') }}</h3><p class="text-sm">{{ __('monitoring.operations.backup_body') }}</p>
            <h3 class="font-medium">{{ __('monitoring.operations.production_title') }}</h3><p class="text-sm">{{ __('monitoring.operations.production_body') }}</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
