@php($preview = $this->qualityPreviewCurrent())
<div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
    <h3 class="font-semibold">{{ __('baseline_creation.step_preview') }}</h3>
    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('baseline_creation.preview_explanation') }}</p>
    <x-filament::button type="button" color="gray" wire:click="checkBaselineQuality">
        {{ __('baseline_creation.check_quality') }}
    </x-filament::button>

    @if ($preview)
        <p role="status" class="font-semibold">{{ __('baseline_creation.preview_status.'.$preview['status']) }}</p>
        @if ($preview['reason'] ?? null)
            <p class="text-sm">{{ __('baseline_creation.preview_reason.'.$preview['reason']) }}</p>
        @endif
        @foreach ($preview['charts'] ?? [] as $type => $chart)
            <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-800">
                <h4 class="font-semibold">{{ __('baseline_creation.preview_charts.'.$type) }} · {{ __('baseline_creation.preview_sufficiency.'.$chart['sufficiency']) }}</h4>
                <p>{{ __('baseline_creation.preview_included') }}: {{ $chart['included'] }} · {{ __('baseline_creation.preview_points') }}: {{ $chart['point_count'] }}
                    @if ($type === 'mr_chart') · {{ __('baseline_creation.preview_pairs') }}: {{ $chart['point_count'] }} @endif
                    @if ($type === 'p_chart') · {{ __('baseline_creation.preview_subgroups') }}: {{ $chart['nonempty_subgroups'] }} @endif
                </p>
                <p>{{ __('baseline_creation.preview_coverage') }}: {{ $chart['coverage'] === null ? '—' : number_format($chart['coverage'], 2).'%' }}
                    · {{ __('baseline_creation.preview_expected') }}: {{ $chart['expected'] }}
                    · {{ __('baseline_creation.preview_missing') }}: {{ $chart['missing'] }}
                    · {{ __('baseline_creation.preview_signals') }}: {{ $chart['out_of_control'] }}</p>
                @if ($chart['exclusions'])
                    <p>{{ __('baseline_creation.preview_exclusions') }}:
                        @foreach ($chart['exclusions'] as $reason => $count)
                            {{ __('baseline_creation.exclusion_reasons.'.$reason) }} ({{ $count }}){{ $loop->last ? '' : ' · ' }}
                        @endforeach
                    </p>
                @endif
                @if ($chart['active_reference']) <p>{{ __('baseline_creation.preview_existing_reference') }}</p> @endif
                @if ($chart['duplicate']) <p>{{ __('baseline_creation.preview_duplicate') }}</p> @endif
            </div>
        @endforeach
        @if (($preview['configuration_changes'] ?? 0) > 0)
            <p class="text-sm">{{ __('baseline_creation.preview_config_changes', ['count' => $preview['configuration_changes']]) }}</p>
        @endif
        @if (in_array($preview['status'], ['eligible', 'needs_review'], true))
            <div class="rounded-lg border border-primary-300 p-3 text-sm dark:border-primary-700">
                <strong>{{ __('baseline_creation.confirm_summary') }}</strong>
                <p>{{ $preview['service_name'] }} · {{ implode(' / ', array_map(fn ($type) => __('baseline_creation.preview_charts.'.$type), array_keys($preview['charts']))) }}</p>
                <p dir="ltr">{{ \Carbon\Carbon::parse($preview['start'])->setTimezone($preview['timezone'])->format('Y-m-d H:i:s') }} → {{ \Carbon\Carbon::parse($preview['end'])->setTimezone($preview['timezone'])->format('Y-m-d H:i:s') }} ({{ $preview['timezone'] }})</p>
                <p>{{ __('baseline_creation.confirm_draft_only') }}</p>
            </div>
        @endif
    @endif
</div>
