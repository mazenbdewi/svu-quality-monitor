<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            {{ __('monitoring.interpretation.sections.key_findings') }}
        </x-slot>

        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($findings as $area => $finding)
                @php
                    $type = $finding['type'] ?? 'gray';
                    $badgeClasses = match ($type) {
                        'success' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30',
                        'info' => 'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-400/10 dark:text-info-400 dark:ring-info-400/30',
                        'warning' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30',
                        'danger' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30',
                        default => 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-300 dark:ring-gray-400/30',
                    };
                    $levelLabel = match ($type) {
                        'success' => __('monitoring.interpretation.levels.stable'),
                        'info' => __('monitoring.interpretation.levels.acceptable'),
                        'warning' => __('monitoring.interpretation.levels.warning'),
                        'danger' => __('monitoring.interpretation.levels.needs_investigation'),
                        default => __('monitoring.interpretation.levels.no_data'),
                    };
                @endphp

                <div class="flex min-w-0 flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ __("monitoring.dashboard.research_cards.{$area}") }}
                        </p>
                        <p class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $finding['title'] ?? __('monitoring.dashboard.empty.value') }}
                        </p>
                        <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                            {{ $finding['message'] ?? __('monitoring.interpretation.recommendations.continue_monitoring') }}
                        </p>
                    </div>

                    <span class="{{ $badgeClasses }} inline-flex w-fit items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset">
                        {{ $levelLabel }}
                    </span>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
