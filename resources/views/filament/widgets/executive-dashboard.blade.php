@php
    $counts = $dashboard['counts'];
    $sla = $dashboard['sla'];
    $health = $dashboard['system_health'];
    $isOperational = $health['scheduler']['status'] === 'healthy' && $health['queue']['status'] === 'healthy';
    $statusColor = ['down' => 'danger', 'pending_failure' => 'warning', 'recovering' => 'warning', 'breached' => 'danger', 'at_risk' => 'warning', 'critical' => 'danger', 'healthy' => 'success', 'maintenance' => 'gray'];
@endphp
<x-filament-widgets::widget>
    <div class="space-y-6" wire:poll.60s>
        <x-filament::section>
            <x-slot name="heading">{{ __('monitoring.executive.dashboard.title') }}</x-slot>
            <x-slot name="description">{{ $isOperational ? __('monitoring.executive.dashboard.operational') : __('monitoring.executive.dashboard.attention_required') }}</x-slot>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['label' => __('monitoring.executive.cards.total_services'), 'value' => $counts['total'], 'color' => 'info'],
                    ['label' => __('monitoring.executive.cards.healthy'), 'value' => $counts['healthy'], 'color' => 'success'],
                    ['label' => __('monitoring.executive.cards.down'), 'value' => $counts['down'], 'color' => 'danger'],
                    ['label' => __('monitoring.executive.cards.maintenance'), 'value' => $counts['maintenance'], 'color' => 'gray'],
                    ['label' => __('monitoring.executive.cards.open_incidents'), 'value' => $counts['open_incidents'], 'color' => 'danger'],
                    ['label' => __('monitoring.executive.cards.sla_met'), 'value' => $sla['met'], 'color' => 'success'],
                    ['label' => __('monitoring.executive.cards.sla_at_risk'), 'value' => $sla['at_risk'], 'color' => 'warning'],
                    ['label' => __('monitoring.executive.cards.sla_breached'), 'value' => $sla['breached'], 'color' => 'danger'],
                ] as $card)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="text-sm text-gray-500">{{ $card['label'] }}</div>
                        <div class="mt-1 text-2xl font-semibold text-{{ $card['color'] }}-600">{{ number_format($card['value']) }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section>
                <x-slot name="heading">{{ __('monitoring.executive.sections.attention') }}</x-slot>
                <div class="space-y-2">
                    @forelse ($dashboard['attention_services'] as $service)
                        @php($attention = in_array($service->executive_status, ['down', 'pending_failure', 'recovering', 'critical'], true) ? $service->executive_status : ($service->getRelation('executiveSlaMetric')?->status ?? $service->executive_status))
                        <a href="{{ \App\Filament\Resources\MonitoredServices\MonitoredServiceResource::getUrl('edit', ['record' => $service]) }}" class="flex items-center justify-between rounded-lg border border-gray-100 px-3 py-2 dark:border-white/10">
                            <span>{{ $service->name }}</span>
                            <x-filament::badge :color="$statusColor[$attention] ?? 'gray'">{{ __('monitoring.sla.statuses.'.$attention) }}</x-filament::badge>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">{{ __('monitoring.executive.empty') }}</p>
                    @endforelse
                </div>
            </x-filament::section>
            <x-filament::section>
                <x-slot name="heading">{{ __('monitoring.executive.sections.sla') }}</x-slot>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>{{ __('monitoring.executive.sla.configured') }} <strong>{{ $sla['configured'] }}</strong></div>
                    <div>{{ __('monitoring.executive.sla.weighted_availability') }} <strong>{{ $sla['weighted_availability'] === null ? '-' : number_format($sla['weighted_availability'], 2).'%' }}</strong></div>
                </div>
                <div class="mt-4 space-y-3">
                    @foreach ($sla['top_budget_consumers'] as $metric)
                        <div>
                            <div class="flex justify-between text-sm"><span>{{ $metric->monitoredService?->name }}</span><span>{{ number_format((float) $metric->error_budget_consumed_percent, 0) }}%</span></div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"><div class="h-full rounded-full {{ $metric->status === 'breached' ? 'bg-danger-500' : ($metric->status === 'at_risk' ? 'bg-warning-500' : 'bg-success-500') }}" style="width: {{ min(100, (float) $metric->error_budget_consumed_percent) }}%"></div></div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <x-filament::section><x-slot name="heading">{{ __('monitoring.executive.sections.incidents') }}</x-slot><p>{{ __('monitoring.executive.incidents.count', ['count' => $dashboard['incidents']['count']]) }}</p><p>{{ __('monitoring.executive.incidents.downtime') }}: {{ gmdate('H:i:s', $dashboard['incidents']['downtime_seconds']) }}</p><p>{{ __('monitoring.executive.incidents.most_affected') }}: {{ $dashboard['incidents']['most_affected'] ?? '-' }}</p></x-filament::section>
            <x-filament::section><x-slot name="heading">{{ __('monitoring.executive.sections.ssl') }}</x-slot><p>≤ 30 {{ __('monitoring.executive.days') }}: {{ $dashboard['ssl']['within_30'] }}</p><p>≤ 14 {{ __('monitoring.executive.days') }}: {{ $dashboard['ssl']['within_14'] }}</p><p>≤ 7 {{ __('monitoring.executive.days') }}: {{ $dashboard['ssl']['within_7'] }}</p></x-filament::section>
            <x-filament::section><x-slot name="heading">{{ __('monitoring.executive.sections.spc') }}</x-slot><p>{{ __('monitoring.executive.spc.points', ['count' => $dashboard['spc']['points']]) }}</p><p>{{ $dashboard['spc']['services']->join(', ') ?: '-' }}</p></x-filament::section>
        </div>
    </div>
</x-filament-widgets::widget>
