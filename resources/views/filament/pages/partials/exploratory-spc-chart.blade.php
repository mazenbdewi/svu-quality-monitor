@php
    $chartKey = $chart['chart_type'];
    $title = __('performance_analysis.charts.'.$chartKey.'.title');
    $description = __('performance_analysis.charts.'.$chartKey.'.description');
@endphp

<article class="rounded-xl border p-4" wire:key="exploratory-chart-{{ $chartKey }}">
    <h3 class="text-base font-semibold">{{ $title }}</h3>
    <p class="mt-1 text-sm text-gray-500">{{ $description }}</p>
    @if ($chart['sufficiency'] !== 'analyzable')
        <p class="mt-2 text-sm text-warning-600">{{ __('performance_analysis.chart_status_'.$chart['sufficiency']) }}</p>
    @endif
    @if (data_get($chart, 'visualization.downsampled'))
        <p class="mt-2 text-sm text-gray-500">{{ __('performance_analysis.visualization_downsampled') }}</p>
    @endif
    <div x-data="historicalSpcChart(@js($chartKey))" x-init="init()" class="mt-4">
        <div wire:ignore class="h-[360px] w-full">
            <canvas x-ref="canvas" role="img" aria-label="{{ $title }}"></canvas>
        </div>
    </div>
</article>
