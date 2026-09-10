@php
    $counts = $dashboard['counts'];
    $sla = $dashboard['sla'];
    $health = $dashboard['system_health'];
    $attention = \App\Filament\Support\DashboardPresentation::attention($dashboard);
    $cards = [
        ['key' => 'healthy', 'value' => $counts['healthy'], 'color' => 'success'],
        ['key' => 'down', 'value' => $counts['down'], 'color' => 'danger'],
        ['key' => 'open_incidents', 'value' => $counts['open_incidents'], 'color' => 'danger'],
        ['key' => 'maintenance', 'value' => $counts['maintenance'], 'color' => 'gray'],
        ['key' => 'sla_breached', 'value' => $sla['breached'], 'color' => 'danger'],
        ['key' => 'sla_at_risk', 'value' => $sla['at_risk'], 'color' => 'warning'],
    ];
@endphp
<x-filament-widgets::widget>
    <div class="space-y-6" wire:poll.60s>
        <x-filament::section>
            <x-slot name="heading">{{ __('monitoring.executive.dashboard.title') }}</x-slot>
            <x-slot name="description">{{ __('ux.dashboard_scope', ['count' => $counts['total']]) }}</x-slot>
            <div class="mb-5 flex flex-wrap gap-3 text-sm">
                @foreach (['scheduler', 'queue'] as $healthPart)
                    <x-filament::badge :color="match ($health[$healthPart]['status']) { 'healthy' => 'success', 'warning' => 'warning', default => 'danger' }">
                        {{ __('monitoring.operations.health.'.$healthPart.'.title') }}: {{ __('monitoring.operations.health.statuses.'.$health[$healthPart]['status']) }}
                    </x-filament::badge>
                @endforeach
            </div>
            <dl class="grid grid-cols-2 gap-5 sm:grid-cols-3">
                @foreach ($cards as $card)
                    @if ($card['key'] === 'healthy' || $card['value'] > 0)
                        <div class="min-w-0 space-y-2">
                            <dt class="text-sm text-gray-600 dark:text-gray-400">{{ __('monitoring.executive.cards.'.$card['key']) }}</dt>
                            <dd @class(['text-2xl font-semibold tabular-nums', 'text-success-700 dark:text-success-400' => $card['color'] === 'success', 'text-danger-700 dark:text-danger-400' => $card['color'] === 'danger', 'text-warning-700 dark:text-warning-400' => $card['color'] === 'warning', 'text-gray-700 dark:text-gray-300' => $card['color'] === 'gray'])>{{ number_format($card['value']) }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
            @if ($counts['total'] === 0)
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">{{ __('ux.empty.services_help') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('monitoring.executive.sections.attention') }}</x-slot>
            <x-slot name="description">{{ __('ux.attention_help') }}</x-slot>
            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($attention->take(10) as $item)
                    @php
                        $status = $item['status'];
                        $label = in_array($status, ['breached', 'at_risk'], true)
                            ? __('monitoring.sla.labels.status').': '.__('monitoring.sla.statuses.'.$status)
                            : (in_array($status, ['ssl_expiring', 'out_of_control'], true) ? __('ux.signals.'.$status) : __('monitoring.service_status.statuses.'.$status));
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                        @if ($item['service'] && \App\Filament\Resources\MonitoredServices\MonitoredServiceResource::canEdit($item['service']))
                            <x-filament::link :href="\App\Filament\Resources\MonitoredServices\MonitoredServiceResource::getUrl('edit', ['record' => $item['service']])" color="gray"><bdi class="break-words">{{ $item['name'] }}</bdi></x-filament::link>
                        @else
                            <span class="min-w-0 break-words text-sm"><bdi>{{ $item['name'] }}</bdi></span>
                        @endif
                        <x-filament::badge :color="in_array($status, ['down', 'breached', 'critical'], true) ? 'danger' : 'warning'">{{ $label }}</x-filament::badge>
                    </li>
                @empty
                    <li class="flex items-center gap-3 py-3 text-sm">
                        <x-filament::icon icon="heroicon-o-check-circle" class="size-5 text-success-600" />
                        {{ __('monitoring.executive.empty') }}
                    </li>
                @endforelse
            </ul>
            @if ($attention->count() > 10)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('ux.more_attention', ['count' => $attention->count() - 10]) }}</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('monitoring.executive.sections.sla') }} · {{ $dashboard['period_start']->translatedFormat('F Y') }}</x-slot>
            <x-slot name="description">{{ __('ux.help.sla') }}</x-slot>
            <dl class="mb-5 flex flex-wrap gap-x-8 gap-y-3 text-sm">
                <div><dt class="text-gray-600 dark:text-gray-400">{{ __('monitoring.executive.sla.configured') }}</dt><dd class="mt-1 font-medium">{{ number_format($sla['configured']) }}</dd></div>
                <div><dt class="text-gray-600 dark:text-gray-400">{{ __('monitoring.executive.sla.weighted_availability') }}</dt><dd class="mt-1 font-medium"><bdi>{{ $sla['weighted_availability'] === null ? '—' : number_format($sla['weighted_availability'], 2).'%' }}</bdi></dd></div>
                <div><dt class="text-gray-600 dark:text-gray-400">{{ __('monitoring.executive.cards.sla_met') }}</dt><dd class="mt-1 font-medium">{{ number_format($sla['met']) }}</dd></div>
            </dl>
            <div class="space-y-5">
                @forelse ($sla['top_budget_consumers'] as $metric)
                    <div class="space-y-2">
                        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                            <span class="font-medium"><bdi>{{ $metric->monitoredService?->name }}</bdi></span>
                            <span class="text-gray-600 dark:text-gray-400">{{ __('monitoring.sla.labels.target') }}: <bdi>{{ number_format((float) $metric->target_percent, 2) }}%</bdi> · {{ __('monitoring.sla.labels.actual') }}: <bdi>{{ $metric->availability_percent === null ? '—' : number_format((float) $metric->availability_percent, 2).'%' }}</bdi></span>
                            <x-filament::badge :color="match ($metric->status) { 'met' => 'success', 'at_risk' => 'warning', 'breached' => 'danger', default => 'gray' }">{{ __('monitoring.sla.statuses.'.$metric->status) }}</x-filament::badge>
                        </div>
                        <x-sla-budget :value="$metric->error_budget_consumed_percent" :status="$metric->status" />
                    </div>
                @empty
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('ux.empty.sla') }}</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">{{ __('ux.monthly_context') }}</x-slot>
            <div class="grid gap-6 md:grid-cols-3">
                <div class="space-y-2 text-sm"><h3 class="font-medium">{{ __('monitoring.executive.sections.incidents') }}</h3><p>{{ __('monitoring.executive.incidents.count', ['count' => $dashboard['incidents']['count']]) }}</p><p>{{ __('monitoring.executive.incidents.downtime') }}: <bdi>{{ \App\Filament\Support\DashboardPresentation::duration($dashboard['incidents']['downtime_seconds']) }}</bdi></p><p>{{ __('monitoring.executive.incidents.most_affected') }}: <bdi>{{ $dashboard['incidents']['most_affected'] ?? '—' }}</bdi></p></div>
                <div class="space-y-2 text-sm"><h3 class="font-medium">{{ __('monitoring.executive.sections.ssl') }}</h3>@foreach ([30, 14, 7] as $days)<p>{{ __('ux.ssl_within', ['days' => $days, 'count' => $dashboard['ssl']['within_'.$days]]) }}</p>@endforeach</div>
                <div class="space-y-2 text-sm"><h3 class="font-medium">{{ __('monitoring.executive.sections.spc') }}</h3><p>{{ __('monitoring.executive.spc.points', ['count' => $dashboard['spc']['points']]) }}</p><p>{{ __('ux.help.spc') }}</p></div>
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
