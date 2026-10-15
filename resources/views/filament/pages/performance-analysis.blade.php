<x-filament-panels::page>
    @vite('resources/js/charts.js')
    @include('filament.pages.partials.exploratory-spc-script')
    <p>{{ __('performance_analysis.intro') }}</p>
    <div class="grid gap-4 md:grid-cols-3">
        <label>{{ __('performance_analysis.service') }}
            <select wire:model.live="serviceId" class="fi-input w-full">
                <option value="">—</option>
                @foreach ($services as $service)
                    <option value="{{ $service->id }}">{{ $service->name }} · {{ strtoupper($service->check_type->value) }}</option>
                @endforeach
            </select>
        </label>
        <label>{{ __('performance_analysis.window') }}
            <select wire:model.live="window" class="fi-input w-full">
                @foreach (['7', '15', '30', '60', '90'] as $days)
                    <option value="{{ $days }}">{{ trans_choice('performance_analysis.days', (int) $days, ['count' => $days]) }}</option>
                @endforeach
                <option value="custom">{{ __('performance_analysis.custom') }}</option>
            </select>
        </label>
        <div class="text-sm text-gray-500 self-end">{{ __('performance_analysis.calendar_policy', ['timezone' => $timezone]) }}</div>
    </div>
    @if ($window === 'custom')
        <div class="grid gap-4 md:grid-cols-2 mt-4">
            <label>{{ __('performance_analysis.start') }}<input type="datetime-local" wire:model.live.debounce.400ms="customStart" class="fi-input w-full" /></label>
            <label>{{ __('performance_analysis.end') }}<input type="datetime-local" wire:model.live.debounce.400ms="customEnd" class="fi-input w-full" /></label>
        </div>
    @endif
    @if (($summary['state'] ?? null) === 'invalid')
        <p class="text-danger-600 mt-4">{{ collect($summary['errors'] ?? [])->flatten()->first() }}</p>
    @elseif (($summary['state'] ?? null) === 'select_service')
        <p class="mt-6">{{ __('performance_analysis.select_service') }}</p>
    @elseif (($summary['state'] ?? null) === 'missing_period')
        <p class="mt-6">{{ __('performance_analysis.missing_period') }}</p>
    @elseif (($summary['state'] ?? null) === 'unsupported')
        <p class="mt-6">{{ __('performance_analysis.unsupported', ['type' => strtoupper($summary['service']->check_type->value)]) }}</p>
    @else
        <div class="mt-6 rounded-xl border p-4">
            <h2 class="text-lg font-semibold">{{ $summary['service']->name }} · {{ strtoupper($summary['service']->check_type->value) }}</h2>
            <p>{{ __('performance_analysis.effective_period', ['start' => $summary['start']->copy()->setTimezone($timezone)->format('Y-m-d H:i'), 'end' => $summary['end']->copy()->setTimezone($timezone)->format('Y-m-d H:i'), 'timezone' => $timezone]) }}</p>
            @if (in_array($summary['state'], ['no_data', 'insufficient', 'preliminary'], true))
                <p class="mt-2">{{ __('performance_analysis.state_'.$summary['state']) }}</p>
            @endif
            <div class="grid gap-4 md:grid-cols-3 mt-4">
                <div>{{ __('performance_analysis.eligible') }}<strong class="block">{{ number_format($summary['eligible_count']) }}</strong></div>
                <div>{{ __('performance_analysis.latency') }}<strong class="block">{{ number_format($summary['latency_count']) }}</strong></div>
                <div>{{ __('performance_analysis.problematic') }}<strong class="block">{{ number_format($summary['problematic_count']) }} ({{ $summary['problematic_percent'] === null ? '—' : number_format($summary['problematic_percent'], 2).'%' }})</strong></div>
                <div>{{ __('performance_analysis.mean_latency') }}<strong class="block">{{ $summary['mean_latency'] === null ? '—' : number_format($summary['mean_latency'], 2).' ms' }}</strong></div>
                <div>{{ __('performance_analysis.coverage') }}<strong class="block">{{ $summary['coverage']['coverage_percent'] === null ? '—' : number_format($summary['coverage']['coverage_percent'], 2).'%' }}</strong></div>
                <div>{{ __('performance_analysis.missing') }}<strong class="block">{{ number_format($summary['coverage']['missing_checks']) }} / {{ number_format($summary['missing_buckets']) }}</strong></div>
            </div>
            <p class="mt-4 text-sm text-gray-500">{{ __('performance_analysis.exploratory_notice') }}</p>
        </div>
        @if (! empty($charts))
            <div class="mt-6 rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800">
                {{ __('performance_analysis.exploratory_warning') }}
            </div>
            <section class="mt-6 space-y-4" aria-labelledby="response-time-charts">
                <h2 id="response-time-charts" class="text-xl font-semibold">{{ __('performance_analysis.sections.response_time') }}</h2>
                @foreach (['i_chart', 'mr_chart'] as $chartType)
                    @if (isset($charts[$chartType]))
                        @include('filament.pages.partials.exploratory-spc-chart', ['chart' => $charts[$chartType]])
                    @endif
                @endforeach
            </section>
            <section class="mt-8 space-y-4" aria-labelledby="problematic-check-charts">
                <h2 id="problematic-check-charts" class="text-xl font-semibold">{{ __('performance_analysis.sections.problematic_checks') }}</h2>
                @if (isset($charts['p_chart']))
                    @include('filament.pages.partials.exploratory-spc-chart', ['chart' => $charts['p_chart']])
                @endif
            </section>
        @endif
    @endif
</x-filament-panels::page>
