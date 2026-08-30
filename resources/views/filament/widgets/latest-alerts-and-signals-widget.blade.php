<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('monitoring.dashboard.widgets.latest_alerts_and_signals') }}</x-slot>
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 text-xs font-semibold text-gray-500 dark:bg-gray-800 dark:text-gray-400"><tr><th class="px-4 py-3 text-start">{{ __('monitoring.dashboard.columns.service_name') }}</th><th class="px-4 py-3 text-start">{{ __('monitoring.dashboard.columns.alert_type') }}</th><th class="px-4 py-3 text-start">{{ __('monitoring.dashboard.columns.alert_details') }}</th><th class="px-4 py-3 text-start">{{ __('monitoring.dashboard.columns.alert_time') }}</th></tr></thead>
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                    @forelse ($alerts as $alert)
                        <tr><td class="whitespace-nowrap px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $alert['service'] ?? __('monitoring.dashboard.empty.value') }}</td><td class="whitespace-nowrap px-4 py-3"><x-filament::badge :color="$alert['color']">{{ $alert['type'] }}</x-filament::badge></td><td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $alert['details'] }}</td><td class="whitespace-nowrap px-4 py-3 text-gray-700 dark:text-gray-300" dir="ltr">{{ $alert['time']?->format(app()->getLocale() === 'ar' ? 'd-m-Y H:i' : 'Y-m-d H:i') }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('monitoring.dashboard.empty_states.no_alerts_or_signals') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
