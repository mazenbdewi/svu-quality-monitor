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
