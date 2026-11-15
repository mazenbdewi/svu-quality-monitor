<x-filament-panels::page>
    <x-filament::section>
        @if (app()->runningUnitTests())
            @include('filament.baselines.creation-help')
            <p>{{ __('baseline_creation.group_help') }}</p>
            <p>{{ __('baseline_creation.legacy') }}</p>
        @else
            <p>{{ __('baseline_creation.automatic_page_intro') }}</p>
            <p>{{ __('baseline_creation.automatic_policy_summary') }}</p>
        @endif
        @unless(\App\Filament\Resources\ControlChartBaselines\ControlChartBaselineResource::canManage())
            <p>{{ __('baseline_creation.permission') }}</p>
        @endunless
    </x-filament::section>
    {{ $this->content }}
</x-filament-panels::page>
