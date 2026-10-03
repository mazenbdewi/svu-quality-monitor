<x-filament-panels::page>
    <x-filament::section>
        @include('filament.baselines.creation-help')
        <p>{{ __('baseline_creation.group_help') }}</p>
        <p>{{ __('baseline_creation.legacy') }}</p>
        @unless(\App\Filament\Resources\ControlChartBaselines\ControlChartBaselineResource::canManage())
            <p>{{ __('baseline_creation.permission') }}</p>
        @endunless
    </x-filament::section>
    {{ $this->content }}
</x-filament-panels::page>
