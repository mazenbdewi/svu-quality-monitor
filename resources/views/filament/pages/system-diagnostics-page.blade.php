<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-center gap-3" role="status" aria-live="polite">
            <x-filament::badge :color="match($diagnostics['status']) { 'ready' => 'success', 'attention' => 'warning', default => 'danger' }">
                {{ __('diagnostics.overall.'.$diagnostics['status']) }}
            </x-filament::badge>
            <span class="text-sm">{{ __('diagnostics.checked_at') }}: {{ $diagnostics['checked_at'] }}</span>
            <a class="text-sm underline" href="{{ \App\Filament\Pages\SystemOperationsPage::getUrl() }}">{{ __('diagnostics.operations') }}</a>
        </div>
    </x-filament::section>
    <div class="grid gap-4 md:grid-cols-2">
        @foreach (collect($diagnostics['checks'])->groupBy('group', preserveKeys: true) as $group => $checks)
            <x-filament::section :heading="__('diagnostics.groups.'.$group)">
                <div class="space-y-4">
                    @foreach ($checks as $key => $check)
                        <div wire:key="diagnostic-{{ $key }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-medium">{{ __('diagnostics.checks.'.$key) }}</span>
                                <x-filament::badge :color="match($check['status']) { 'healthy' => 'success', 'warning' => 'warning', default => 'danger' }">
                                    {{ __('diagnostics.status.'.$check['status']) }}
                                </x-filament::badge>
                            </div>
                            @foreach ($check['details'] as $field => $value)
                                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('diagnostics.fields.'.$field) }}: {{ $value ?? __('diagnostics.unavailable') }}</p>
                            @endforeach
                            @if ($check['status'] !== 'healthy')
                                <p class="text-sm">{{ __('diagnostics.recommendations.'.$key) }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
