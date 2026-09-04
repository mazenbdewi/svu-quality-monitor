@php
    $statusLightColor = fn (string $status): string => match ($status) {
        'healthy' => '#22c55e',
        'warning' => '#f59e0b',
        default => '#ef4444',
    };
    $operationsUrl = \App\Filament\Pages\SystemOperationsPage::getUrl();
@endphp

<div wire:poll.60s="refreshHealth" style="display: flex; flex-shrink: 0; align-items: center; gap: 4px" aria-label="{{ __('monitoring.operations.health.indicator.title') }}">
    @foreach (['scheduler', 'queue'] as $componentName)
        @php
            $componentHealth = $health[$componentName];
            $status = $componentHealth['status'];
            $label = __('monitoring.operations.health.indicator.'.$componentName.'.'.$status);
            $color = $statusLightColor($status);
        @endphp

        <a
            href="{{ $operationsUrl }}"
            wire:navigate
            x-tooltip="{ content: @js($label), theme: $store.theme }"
            title="{{ $label }}"
            aria-label="{{ $label }}"
            data-system-health-indicator="{{ $componentName }}"
            style="display: inline-flex; flex: 0 0 32px; width: 32px; height: 32px; align-items: center; justify-content: center; border-radius: 9999px; background-color: transparent"
        >
            <span style="display: block; width: 16px; height: 16px; border-radius: 9999px; background-color: {{ $color }}; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2)" aria-hidden="true"></span>
        </a>
    @endforeach
</div>
