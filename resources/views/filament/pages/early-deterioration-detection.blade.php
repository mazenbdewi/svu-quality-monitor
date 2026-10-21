<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('early_deterioration.intro') }}</p>

    <label class="block max-w-xl space-y-2">
        <span class="font-medium">{{ __('early_deterioration.service') }}</span>
        <select wire:model.live="serviceId" class="fi-input block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
            @foreach ($services as $option)
                <option value="{{ $option->id }}">{{ $option->name }}</option>
            @endforeach
        </select>
    </label>

    @if (! $service)
        <p role="status">{{ __('early_deterioration.no_service') }}</p>
    @else
        <div class="space-y-4">
            <h2 class="text-xl font-semibold">{{ $service->name }}</h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ __('early_deterioration.slow_threshold') }}:
                {{ $service->warning_response_ms === null ? '—' : number_format($service->warning_response_ms).' ms' }}.
                {{ __('early_deterioration.threshold_note') }}
            </p>

            <div class="grid gap-4 lg:grid-cols-3">
                @foreach ($rows as $row)
                    @php
                        $signal = $row['pointSignal'];
                        $latest = $row['latestLiveSignal'];
                        $point = $row['point'];
                        $state = $row['state'];
                    @endphp
                    <x-filament::section :heading="__('early_deterioration.charts.'.$row['type'])">
                        <div class="space-y-3 text-sm">
                            <p><strong>{{ __('early_deterioration.reference') }}:</strong>
                                {{ $row['baseline'] ? __('early_deterioration.approved_version', ['version' => $row['baseline']->version]) : __('early_deterioration.missing_reference') }}
                            </p>
                            <p role="status" class="font-semibold {{ $state === 'signal' ? 'text-danger-600 dark:text-danger-400' : ($state === 'normal' ? 'text-success-600 dark:text-success-400' : 'text-warning-700 dark:text-warning-400') }}">
                                {{ __('early_deterioration.states.'.$state) }}
                            </p>
                            @if ($row['evaluation'])
                                <p>{{ __('early_deterioration.last_evaluation') }}:
                                    {{ $row['evaluation']->detected_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s') }} ({{ $timezone }})
                                </p>
                            @endif
                            @if ($point?->status === 'eligible')
                                <p>{{ __('early_deterioration.observed') }}: {{ number_format((float) $point->value, $row['type'] === 'p_chart' ? 4 : 2) }}
                                    · {{ __('early_deterioration.upper_limit') }}: {{ $point->upper_control_limit === null ? '—' : number_format((float) $point->upper_control_limit, $row['type'] === 'p_chart' ? 4 : 2) }}
                                    · {{ __('early_deterioration.lower_limit') }}: {{ $point->lower_control_limit === null ? '—' : number_format((float) $point->lower_control_limit, $row['type'] === 'p_chart' ? 4 : 2) }}
                                    {{ $row['type'] === 'p_chart' ? __('early_deterioration.proportion_unit') : 'ms' }}
                                </p>
                            @endif
                            @if ($latest)
                                <p><strong>{{ __('early_deterioration.latest_live_signal') }} · {{ __('early_deterioration.reference_version', ['version' => $latest->baseline_version]) }}:</strong>
                                    {{ __('early_deterioration.directions.'.$latest->direction) }} ·
                                    {{ __('early_deterioration.observed') }} {{ number_format((float) $latest->value, $row['type'] === 'p_chart' ? 4 : 2) }} ·
                                    {{ __('early_deterioration.limit') }} {{ number_format((float) ($latest->direction === 'lower' ? $latest->lower_control_limit : $latest->upper_control_limit), $row['type'] === 'p_chart' ? 4 : 2) }}
                                    {{ $row['type'] === 'p_chart' ? __('early_deterioration.proportion_unit') : 'ms' }}
                                </p>
                                <p>{{ __('early_deterioration.detected_at') }}:
                                    {{ $latest->detected_at->copy()->setTimezone($timezone)->format('Y-m-d H:i:s') }} ({{ $timezone }})
                                </p>
                                @if ($signal?->id !== $latest->id || $row['baseline']?->id !== $latest->baseline_id)
                                    <p class="text-gray-600 dark:text-gray-300">{{ __('early_deterioration.historical_signal_note') }}</p>
                                @endif
                            @else
                                <p>{{ __('early_deterioration.no_live_signal') }}</p>
                            @endif
                            @if ($row['latestRetrospectiveSignal'])
                                <p class="text-gray-600 dark:text-gray-300">{{ __('early_deterioration.retrospective_only') }}</p>
                            @endif
                            @if ($row['baseline'])
                                <a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\SpcResearchMonitoring::getUrl(['baseline' => $row['baseline']->id]) }}">
                                    {{ __('early_deterioration.details') }}
                                </a>
                            @endif
                            @if ($latest && $row['baseline']?->id !== $latest->baseline_id)
                                <a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\SpcResearchMonitoring::getUrl(['baseline' => $latest->baseline_id]) }}">
                                    {{ __('early_deterioration.historical_details') }}
                                </a>
                            @endif
                        </div>
                    </x-filament::section>
                @endforeach
            </div>

            <div class="flex flex-wrap gap-4 text-sm">
                <a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Resources\ControlChartBaselines\ControlChartBaselineResource::getUrl('index') }}">{{ __('early_deterioration.reference_setup') }}</a>
                <a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\SpcResearchMonitoring::getUrl() }}">{{ __('early_deterioration.advanced_monitoring') }}</a>
                <a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\AnalysisMethodologyPage::getUrl() }}">{{ \App\Filament\Pages\AnalysisMethodologyPage::getNavigationLabel() }}</a>
                <a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Resources\ControlCharts\ControlChartResource::getUrl('index') }}">{{ \App\Filament\Resources\ControlCharts\ControlChartResource::getNavigationLabel() }}</a>
            </div>
        </div>
    @endif
</x-filament-panels::page>
