@php
    use App\Filament\Pages\Dashboard;
    use App\Filament\Pages\ReportsPage;
    use App\Filament\Resources\ControlCharts\ControlChartResource;
    use App\Filament\Resources\ReliabilityMetrics\ReliabilityMetricResource;

    $isRtl = app()->getLocale() === 'ar';
    $direction = $isRtl ? 'rtl' : 'ltr';
    $workflowSteps = trans('monitoring.methodology.workflow_steps');
    $metrics = trans('monitoring.methodology.metrics');
    $charts = trans('monitoring.methodology.charts');
    $minitabValidation = trans('monitoring.minitab_validation');
    $quickLinks = [
        [
            'label' => __('monitoring.methodology.quick_links.dashboard'),
            'url' => Dashboard::getUrl(),
        ],
        [
            'label' => __('monitoring.methodology.quick_links.reports'),
            'url' => ReportsPage::getUrl(),
        ],
        [
            'label' => __('monitoring.methodology.quick_links.control_charts'),
            'url' => ControlChartResource::getUrl('index'),
        ],
        [
            'label' => __('monitoring.methodology.quick_links.reliability_metrics'),
            'url' => ReliabilityMetricResource::getUrl('index'),
        ],
    ];
@endphp

<x-filament-panels::page>
    <div dir="{{ $direction }}" class="space-y-6 {{ $isRtl ? 'text-right' : 'text-left' }}">
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-sm font-semibold text-primary-600 dark:text-primary-400">
                {{ __('monitoring.methodology.subtitle') }}
            </p>
            <h2 class="mt-2 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ __('monitoring.methodology.sections.system_idea.title') }}
            </h2>
            <p class="mt-3 max-w-4xl text-base leading-8 text-gray-700 dark:text-gray-300">
                {{ __('monitoring.methodology.sections.system_idea.body') }}
            </p>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                {{ __('monitoring.methodology.sections.workflow.title') }}
            </h2>
            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($workflowSteps as $step)
                    <div class="rounded-lg bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <div class="flex items-center gap-3 {{ $isRtl ? 'flex-row-reverse justify-end' : '' }}">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-600 text-sm font-semibold text-white">
                                {{ $loop->iteration }}
                            </span>
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $step }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                {{ __('monitoring.methodology.sections.reliability_metrics.title') }}
            </h2>
            <div class="mt-5 grid gap-4 md:grid-cols-2">
                @foreach ($metrics as $metric)
                    <article class="rounded-lg bg-gray-50 p-5 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <h3 class="text-base font-bold text-gray-950 dark:text-white">{{ $metric['title'] }}</h3>
                        <p class="mt-2 text-sm leading-7 text-gray-700 dark:text-gray-300">
                            {{ $metric['meaning'] }}
                        </p>
                        @if (filled($metric['formula'] ?? null))
                            <div class="mt-4 rounded-md bg-white px-3 py-2 font-mono text-sm text-primary-700 ring-1 ring-gray-950/5 dark:bg-gray-950 dark:text-primary-300 dark:ring-white/10">
                                <span class="font-sans font-semibold">{{ __('monitoring.methodology.labels.formula') }}:</span>
                                {{ $metric['formula'] }}
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                {{ __('monitoring.methodology.sections.control_charts.title') }}
            </h2>
            <p class="mt-3 max-w-4xl text-sm leading-7 text-gray-700 dark:text-gray-300">
                {{ __('monitoring.methodology.sections.control_charts.body') }}
            </p>
            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach (['i_chart', 'mr_chart', 'p_chart', 'c_chart', 'u_chart'] as $chartKey)
                    <article class="rounded-lg bg-gray-50 p-5 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <h3 class="text-base font-bold text-gray-950 dark:text-white">{{ $charts[$chartKey]['title'] }}</h3>
                        <p class="mt-2 text-sm leading-7 text-gray-700 dark:text-gray-300">{{ $charts[$chartKey]['body'] }}</p>
                    </article>
                @endforeach
            </div>
            <div class="mt-5 rounded-lg bg-primary-50 p-5 text-primary-900 ring-1 ring-primary-500/20 dark:bg-primary-500/10 dark:text-primary-100">
                <h3 class="font-bold">{{ $charts['control_limits']['title'] }}</h3>
                <p class="mt-2 text-sm leading-7">{{ $charts['control_limits']['body'] }}</p>
            </div>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                {{ $minitabValidation['title'] }}
            </h2>
            <p class="mt-3 max-w-4xl text-sm leading-7 text-gray-700 dark:text-gray-300">
                {{ $minitabValidation['intro'] }}
            </p>
            <div class="mt-5 grid gap-4 md:grid-cols-2">
                @foreach ([
                    ['title' => 'raw_checks_title', 'description' => 'raw_checks_description'],
                    ['title' => 'bucketed_checks_title', 'description' => 'bucketed_checks_description'],
                    ['title' => 'control_chart_summary_title', 'description' => 'control_chart_summary_description'],
                    ['title' => 'out_of_control_points_title', 'description' => 'out_of_control_points_description'],
                ] as $card)
                    <article class="rounded-lg bg-gray-50 p-5 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <h3 class="text-base font-bold text-gray-950 dark:text-white">
                            {{ $minitabValidation[$card['title']] }}
                        </h3>
                        <p class="mt-2 text-sm leading-7 text-gray-700 dark:text-gray-300">
                            {{ $minitabValidation[$card['description']] }}
                        </p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <article class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                    {{ __('monitoring.methodology.sections.incident_rules.title') }}
                </h2>
                <p class="mt-3 text-sm leading-7 text-gray-700 dark:text-gray-300">
                    {{ __('monitoring.methodology.sections.incident_rules.body') }}
                </p>
                <div class="mt-4 rounded-lg bg-warning-50 p-4 text-sm leading-7 text-warning-900 ring-1 ring-warning-500/20 dark:bg-warning-500/10 dark:text-warning-100">
                    <span class="font-semibold">{{ __('monitoring.methodology.labels.note') }}:</span>
                    {{ __('monitoring.methodology.sections.incident_rules.note') }}
                </div>
            </article>

            <article class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                    {{ __('monitoring.methodology.sections.proactive_quality.title') }}
                </h2>
                <p class="mt-3 text-sm leading-7 text-gray-700 dark:text-gray-300">
                    {{ __('monitoring.methodology.sections.proactive_quality.body') }}
                </p>
            </article>
        </section>

        <section class="grid gap-6 xl:grid-cols-2">
            <article class="rounded-xl bg-info-50 p-6 text-info-950 shadow-sm ring-1 ring-info-500/20 dark:bg-info-500/10 dark:text-info-100">
                <h2 class="text-xl font-bold">{{ __('monitoring.methodology.sections.ethical_use.title') }}</h2>
                <p class="mt-3 text-sm leading-7">{{ __('monitoring.methodology.sections.ethical_use.body') }}</p>
            </article>

            <article class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                    {{ __('monitoring.methodology.sections.thesis_connection.title') }}
                </h2>
                <p class="mt-3 text-sm leading-7 text-gray-700 dark:text-gray-300">
                    {{ __('monitoring.methodology.sections.thesis_connection.body') }}
                </p>
            </article>
        </section>

        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-xl font-bold text-gray-950 dark:text-white">
                {{ __('monitoring.methodology.quick_links.title') }}
            </h2>
            <div class="mt-4 flex flex-wrap gap-3 {{ $isRtl ? 'justify-end' : '' }}">
                @foreach ($quickLinks as $link)
                    <a
                        href="{{ $link['url'] }}"
                        class="inline-flex items-center justify-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900"
                    >
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </div>
        </section>
    </div>
</x-filament-panels::page>
