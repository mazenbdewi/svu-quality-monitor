<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            {{ __('monitoring.interpretation.sections.key_findings') }}
        </x-slot>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="flex min-w-0 flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-base font-semibold text-gray-950 dark:text-white">{{ __('monitoring.dashboard.research_cards.performance') }}</p>
                <div><p class="text-sm text-gray-500 dark:text-gray-400">{{ __('monitoring.dashboard.research_cards.slow_checks_today') }}</p><p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white" dir="ltr">{{ $performance['value'] }}</p></div>
                <x-filament::badge :color="$performance['color']" class="w-fit">{{ __('monitoring.interpretation.levels.acceptable') }}</x-filament::badge>
                <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $performance['message'] }}</p>
            </div>

            <div class="flex min-w-0 flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-base font-semibold text-gray-950 dark:text-white">{{ __('monitoring.dashboard.research_cards.reliability') }}</p>
                <div><p class="text-sm text-gray-500 dark:text-gray-400">{{ __('monitoring.dashboard.research_cards.average_availability') }}</p><p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white" dir="ltr">{{ $reliability['value'] ?? __('monitoring.dashboard.empty.value') }}</p></div>
                <x-filament::badge :color="$reliability['color']" class="w-fit">{{ __("monitoring.interpretation.levels.{$reliability['level']}") }}</x-filament::badge>
                <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('monitoring.dashboard.research_cards.reliability_message') }}</p>
            </div>

            <div class="flex min-w-0 flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <p class="text-base font-semibold text-gray-950 dark:text-white">{{ __('monitoring.dashboard.research_cards.spc') }}</p>
                @if ($spc['has_data'])
                    <div><p class="text-sm text-gray-500 dark:text-gray-400">{{ __('monitoring.dashboard.research_cards.out_of_control_points') }}</p><p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white" dir="ltr">{{ $spc['value'] }}</p></div>
                    <x-filament::badge :color="$spc['color']" class="w-fit">{{ $spc['value'] === '0' ? __('monitoring.interpretation.levels.stable') : __('monitoring.interpretation.levels.warning') }}</x-filament::badge>
                    <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('monitoring.dashboard.research_cards.spc_message') }}</p>
                @else
                    <x-filament::badge color="gray" class="w-fit">{{ __('monitoring.dashboard.research_cards.awaiting_data') }}</x-filament::badge>
                    <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('monitoring.dashboard.research_cards.spc_waiting_message') }}</p>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
