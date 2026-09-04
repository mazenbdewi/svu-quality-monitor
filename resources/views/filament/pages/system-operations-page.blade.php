@php
    $isRtl = app()->getLocale() === 'ar';
    $direction = $isRtl ? 'rtl' : 'ltr';
    $commands = [
        'scheduler' => 'php artisan schedule:work',
        'cron' => '* * * * * cd /path/to/svu-quality-monitor && php artisan schedule:run >> /dev/null 2>&1',
        'manual_check' => 'php artisan services:check-due',
        'reliability' => 'php artisan reliability:calculate --period=daily',
        'control_charts' => 'php artisan control-charts:calculate --period=daily',
    ];
    $statusColor = fn (string $status): string => match ($status) {
        'healthy' => 'success',
        'warning' => 'warning',
        default => 'danger',
    };
    $formatTime = fn ($time): string => $time?->format('Y-m-d H:i:s') ?? __('monitoring.operations.health.never');
    $formatCount = fn ($count): string => $count === null ? __('monitoring.operations.health.unavailable') : number_format($count);
@endphp

<x-filament-panels::page>
    <div dir="{{ $direction }}" class="space-y-6 {{ $isRtl ? 'text-right' : 'text-left' }}">
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-semibold text-primary-600 dark:text-primary-400">
                {{ __('monitoring.operations.subtitle') }}
            </p>
            <h2 class="mt-2 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ __('monitoring.operations.heading') }}
            </h2>
            <p class="mt-4 text-base leading-8 text-gray-700 dark:text-gray-300">
                {{ __('monitoring.operations.body') }}
            </p>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div>
                <p class="text-sm font-semibold text-primary-600 dark:text-primary-400">
                    {{ __('monitoring.operations.health.subtitle') }}
                </p>
                <h2 class="mt-1 text-xl font-bold text-gray-950 dark:text-white">
                    {{ __('monitoring.operations.health.title') }}
                </h2>
                <p class="mt-2 text-sm leading-6 text-gray-700 dark:text-gray-300">
                    {{ __('monitoring.operations.health.rules') }}
                </p>
            </div>

            <div class="mt-5 grid gap-4 xl:grid-cols-2">
                @foreach (['scheduler', 'queue'] as $component)
                    @php($componentHealth = $health[$component])
                    <article class="rounded-lg bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-semibold text-gray-950 dark:text-white">
                                {{ __('monitoring.operations.health.'.$component.'.title') }}
                            </h3>
                            <span @class([
                                'rounded-full px-2.5 py-1 text-xs font-semibold',
                                'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-300' => $statusColor($componentHealth['status']) === 'success',
                                'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-300' => $statusColor($componentHealth['status']) === 'warning',
                                'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-300' => $statusColor($componentHealth['status']) === 'danger',
                            ])>
                                {{ __('monitoring.operations.health.statuses.'.$componentHealth['status']) }}
                            </span>
                        </div>
                        <dl class="mt-4 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                            <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.last_heartbeat') }}</dt><dd dir="ltr">{{ $formatTime($componentHealth['last_heartbeat']) }}</dd></div>
                            @if ($component === 'scheduler')
                                <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.expected_interval') }}</dt><dd>{{ number_format($componentHealth['expected_interval_seconds']) }} {{ __('monitoring.operations.health.seconds') }}</dd></div>
                            @else
                                <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.queue.last_success') }}</dt><dd dir="ltr">{{ $formatTime($componentHealth['last_success']) }}</dd></div>
                                <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.queue.last_failure') }}</dt><dd dir="ltr">{{ $formatTime($componentHealth['last_failure']) }}</dd></div>
                                <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.queue.pending_jobs') }}</dt><dd>{{ $formatCount($componentHealth['pending_jobs']) }}</dd></div>
                                <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.queue.failed_jobs') }}</dt><dd>{{ $formatCount($componentHealth['failed_jobs']) }}</dd></div>
                            @endif
                        </dl>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-2">
            <article class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="text-xl font-bold text-gray-950 dark:text-white">{{ __('monitoring.operations.health.monitoring.title') }}</h2>
                <dl class="mt-4 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                    <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.monitoring.last_success') }}</dt><dd dir="ltr">{{ $formatTime($health['monitoring']['last_automatic_success']) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.monitoring.last_failure') }}</dt><dd dir="ltr">{{ $formatTime($health['monitoring']['last_automatic_failure']) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.monitoring.active_services') }}</dt><dd>{{ number_format($health['monitoring']['active_services']) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.monitoring.due_services') }}</dt><dd>{{ number_format($health['monitoring']['due_services']) }}</dd></div>
                </dl>
            </article>
            <article class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="text-xl font-bold text-gray-950 dark:text-white">{{ __('monitoring.operations.health.application.title') }}</h2>
                <dl class="mt-4 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                    <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.application.database') }}</dt><dd>{{ __('monitoring.operations.health.database_status.'.($health['application']['database_healthy'] ? 'healthy' : 'down')) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.application.environment') }}</dt><dd dir="ltr">{{ $health['application']['environment'] }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.application.version') }}</dt><dd dir="ltr">{{ $health['application']['version'] }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.application.server_time') }}</dt><dd dir="ltr">{{ $formatTime($health['application']['server_time']) }}</dd></div>
                    <div class="flex justify-between gap-4"><dt>{{ __('monitoring.operations.health.application.timezone') }}</dt><dd dir="ltr">{{ $health['application']['timezone'] }}</dd></div>
                </dl>
            </article>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                {{ __('monitoring.operations.commands_title') }}
            </h2>
            <div class="mt-5 grid gap-4">
                @foreach ($commands as $key => $command)
                    <article class="rounded-lg bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                            {{ __("monitoring.operations.commands.{$key}") }}
                        </h3>
                        <pre class="mt-3 overflow-x-auto rounded-md bg-gray-950 px-3 py-2 text-left text-sm text-gray-100" dir="ltr"><code>{{ $command }}</code></pre>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <article class="rounded-xl bg-warning-50 p-6 text-warning-950 shadow-sm ring-1 ring-warning-500/20 dark:bg-warning-500/10 dark:text-warning-100">
                <h2 class="text-xl font-bold">
                    {{ __('monitoring.operations.backup_title') }}
                </h2>
                <p class="mt-3 text-sm leading-7">
                    {{ __('monitoring.operations.backup_body') }}
                </p>
            </article>

            <article class="rounded-xl bg-info-50 p-6 text-info-950 shadow-sm ring-1 ring-info-500/20 dark:bg-info-500/10 dark:text-info-100">
                <h2 class="text-xl font-bold">
                    {{ __('monitoring.operations.production_title') }}
                </h2>
                <p class="mt-3 text-sm leading-7">
                    {{ __('monitoring.operations.production_body') }}
                </p>
            </article>
        </section>
    </div>
</x-filament-panels::page>
