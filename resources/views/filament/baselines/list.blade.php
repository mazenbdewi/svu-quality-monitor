<x-filament-panels::page>
    <x-filament::section>
        <p>{{ __('baseline_creation.scheduled_page_intro') }}</p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('baseline_creation.automatic_policy_summary') }}</p>
        @unless(\App\Filament\Resources\ControlChartBaselines\ControlChartBaselineResource::canManage())
            <p>{{ __('baseline_creation.permission') }}</p>
        @endunless
    </x-filament::section>
    <div class="[&_.fi-ta-cell]:py-2 [&_.fi-ta-text]:text-sm">
        {{ $this->content }}
    </div>
</x-filament-panels::page>
