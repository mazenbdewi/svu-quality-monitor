@php
    $pollingInterval = $this->getPollingInterval();
@endphp

<x-filament-widgets::widget
    :attributes="
        (new \Illuminate\View\ComponentAttributeBag)
            ->merge([
                'wire:poll.' . $pollingInterval => $pollingInterval ? true : null,
            ], escape: false)
            ->class(['fi-wi-stats-overview', 'cc-control-chart-overview'])
    "
>
    <style>
        .cc-control-chart-overview .fi-wi-stats-overview-stat-label,
        .cc-control-chart-overview .cc-card-title {
            font-size: 14px !important;
            font-weight: 400 !important;
            line-height: 1.5 !important;
        }

        .cc-control-chart-overview .fi-wi-stats-overview-stat-value,
        .cc-control-chart-overview .cc-card-value {
            font-size: 16px !important;
            font-weight: 500 !important;
            line-height: 1.5 !important;
        }

        .cc-control-chart-overview .cc-card-value-number {
            font-size: 18px !important;
            font-weight: 600 !important;
            line-height: 1.5 !important;
        }

        .cc-control-chart-overview .cc-card-description {
            font-size: 13px !important;
            font-weight: 400 !important;
            line-height: 1.5 !important;
        }
    </style>

    {{ $this->content }}
</x-filament-widgets::widget>
