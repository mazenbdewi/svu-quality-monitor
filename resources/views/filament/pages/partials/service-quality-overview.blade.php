<section class="mt-6 space-y-4" aria-labelledby="service-quality-overview">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 id="service-quality-overview" class="text-xl font-semibold">{{ __('slo_overview.title') }}</h2>
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('slo_overview.synthetic_duration') }}</p>
        </div>
        <button type="button" wire:click="loadQualityOverview" wire:loading.attr="disabled" wire:target="loadQualityOverview"
            class="rounded-lg bg-primary-600 px-4 py-2 font-medium text-white disabled:opacity-50">
            <span wire:loading.remove wire:target="loadQualityOverview">{{ $loaded ? __('slo_overview.refresh') : __('slo_overview.load') }}</span>
            <span wire:loading wire:target="loadQualityOverview">{{ __('slo_overview.loading') }}</span>
        </button>
    </div>

    <p wire:loading wire:target="loadQualityOverview" role="status" class="text-sm text-gray-600 dark:text-gray-300">{{ __('slo_overview.loading') }}</p>
    @if ($error)
        <p role="alert" class="text-danger-700 dark:text-danger-400">{{ __('slo_overview.load_error') }}</p>
    @endif
    @if (! $loaded)
        @if (! $error)
            <p role="status" class="text-sm text-gray-600 dark:text-gray-300">{{ __('slo_overview.lazy_note') }}</p>
        @endif
    @else
        @php
            $latency = $overview['latency'];
            $sli = $overview['sli'];
            $latest = $overview['latest_proposal'];
            $approved = $overview['approved_proposal'];
            $localZone = $latency['analysis_timezone'];
            $formatTime = fn ($value) => \Carbon\Carbon::parse($value)->setTimezone($localZone)->format('Y-m-d H:i');
            $formatPercent = fn ($value) => $value === null ? '—' : number_format((float) $value, 2).'%';
            $formatMs = fn ($value) => $value === null ? '—' : number_format((float) $value, 2).' ms';
        @endphp
        <p role="status" class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('slo_overview.calculated_at') }}: {{ $formatTime($overview['calculated_at']) }} ({{ $localZone }}) ·
            {{ __('slo_overview.fixed_window') }}: {{ $formatTime($latency['period_start']) }} – {{ $formatTime($latency['period_end']) }}
        </p>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('slo_overview.sli_basis.'.$overview['sli_basis']) }}</p>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                'availability' => $formatPercent($sli['availability_percent']),
                'conditional' => $formatPercent($sli['conditional_successful_latency_percent']),
                'combined' => $formatPercent($sli['combined_latency_success_percent']),
                'p50' => $formatMs($latency['p50_ms']),
                'p90' => $formatMs($latency['p90_ms']),
                'p95' => $formatMs($latency['p95_ms']),
                'p99' => $formatMs($latency['p99_ms']),
                'coverage' => $formatPercent($latency['coverage']['coverage_percent']),
            ] as $key => $value)
                <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('slo_overview.metrics.'.$key) }}</p>
                    <strong class="mt-1 block text-lg">{{ $value }}</strong>
                </div>
            @endforeach
        </div>
        <p class="text-sm">
            {{ __('slo_overview.denominators', [
                'n' => number_format($sli['executed_checks']),
                's' => number_format($sli['successful_checks']),
                'v' => number_format($sli['successful_with_valid_latency']),
                'g' => $sli['successful_within_objective'] === null ? '—' : number_format($sli['successful_within_objective']),
            ]) }}
            · {{ __('slo_overview.failed') }}: {{ number_format($sli['failed_checks']) }}
            · {{ __('slo_overview.invalid_latency') }}: {{ number_format($sli['successful_without_valid_latency']) }}
        </p>
        <p class="text-sm">
            {{ __('slo_overview.missing_opportunities') }}: {{ number_format($latency['coverage']['missing_opportunities']) }}
            / {{ number_format($latency['coverage']['expected_opportunities']) }}
            · {{ __('slo_overview.valid_latency_observations') }}: {{ number_format($latency['latency_observations']) }}
        </p>
        @if (! ($latency['coverage']['exact_opportunity_ledger'] ?? false))
            <p class="rounded-lg bg-warning-50 p-3 text-sm text-warning-800 dark:bg-warning-950/30 dark:text-warning-300">
                {{ __('performance_analysis.coverage_estimate_notice') }}
            </p>
        @endif
        @if ($latency['sample_status'] !== 'analyzable' || $latency['coverage']['status'] !== 'observed')
            <p role="status" class="text-warning-700 dark:text-warning-400">{{ __('slo_overview.data_quality_warning') }}</p>
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
            <x-filament::section :heading="__('slo_overview.proposal_heading')">
                @if (! $latest)
                    <p role="status">{{ $overview['proposal_access_limited'] ? __('slo_overview.no_visible_proposal') : __('slo_overview.no_proposal') }}</p>
                    @can('slo.proposals.manage')
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('slo_overview.preparation_state.'.$overview['preparation_state']) }}</p>
                    @endcan
                @else
                    <div class="space-y-2 text-sm">
                        @if ($overview['preparation_state'] === 'ready_for_review')
                            <p role="status" class="text-primary-700 dark:text-primary-400">{{ __('slo_overview.preparation_state.ready_for_review') }}</p>
                        @endif
                        <p><strong>{{ __('slo_overview.service') }}:</strong> {{ $latest['service_name'] }}</p>
                        <p role="status"><strong>{{ __('slo_overview.proposal_status') }}:</strong> {{ __('slo_overview.status.'.$latest['status']) }}</p>
                        <p><strong>{{ __('slo_overview.evidence_status') }}:</strong> {{ __('slo_overview.evidence.'.$latest['scientific_status']) }}</p>
                        <p>{{ __('slo_overview.policy') }}: {{ $latest['policy_version'] }}</p>
                        <p>{{ __('slo_overview.calibration_period') }}: {{ $formatTime($latest['calibration_start']) }} – {{ $formatTime($latest['calibration_end']) }} ({{ $latest['analysis_timezone'] }})</p>
                        <p>{{ __('slo_overview.cutoff') }}: {{ $formatTime($latest['calibration_cutoff']) }}</p>
                        <p>{{ __('slo_overview.historical_p90') }}: {{ $formatMs($latest['measured_p90_ms']) }} · {{ __('slo_overview.historical_p99') }}: {{ $formatMs($latest['measured_p99_ms']) }}</p>
                        <p>{{ __('slo_overview.proposed_guardrail') }}: {{ $formatMs($latest['proposed_p90_guardrail_ms']) }} · {{ __('slo_overview.latency_objective') }}: {{ $formatMs($latest['proposed_latency_ms']) }} ({{ $latest['proposed_latency_seconds'] === null ? '—' : number_format($latest['proposed_latency_seconds'], 3).' s' }})</p>
                        <p>{{ __('slo_overview.target') }}: {{ $formatPercent($latest['proposed_compliance_percent']) }} · {{ __('slo_overview.rounding') }}: {{ __('slo_overview.rounding_rule.'.$latest['rounding_rule']) }}</p>
                        <p>{{ __('slo_overview.calibration_counts') }}: {{ number_format($latest['executed_checks']) }} / {{ number_format($latest['latency_observations']) }}</p>
                        <p>{{ __('slo_overview.calibration_coverage') }}: {{ $formatPercent($latest['coverage_percent']) }} · {{ __('slo_overview.missing_opportunities') }}: {{ number_format($latest['missing_opportunities']) }}</p>
                        <p role="status">{{ __('slo_overview.configuration_compatibility') }}: {{ __('slo_overview.compatibility.'.($latest['configuration_compatible'] ? 'compatible' : 'incompatible')) }}</p>
                        <p class="text-gray-600 dark:text-gray-300">{{ __('slo_overview.research_only') }}</p>
                        @foreach ($latest['warnings'] as $warning)
                            <p class="text-warning-700 dark:text-warning-400">{{ __('slo_overview.warnings.'.$warning) }}</p>
                        @endforeach
                        @can('slo.proposals.manage')
                            <div class="flex flex-wrap gap-2 pt-2">
                                @if ($latest['status'] === 'draft')
                                    {{ $this->reviewSloProposalAction }}
                                @elseif ($latest['status'] === 'reviewed' && $latest['scientific_status'] === 'provisional_research_only' && $latest['configuration_compatible'])
                                    {{ $this->approveSloProposalAction }}
                                @elseif ($latest['status'] === 'approved')
                                    {{ $this->retireSloProposalAction }}
                                @endif
                            </div>
                        @endcan
                    </div>
                @endif
            </x-filament::section>

            <x-filament::section :heading="__('slo_overview.evaluation_heading')">
                <div class="space-y-3 text-sm">
                    @if ($approved)
                        <p><strong>{{ __('slo_overview.approved_target') }}:</strong> {{ $formatMs($approved['proposed_latency_ms']) }} / {{ $formatPercent($approved['proposed_compliance_percent']) }}</p>
                    @else
                        <p role="status">{{ __('slo_overview.no_approved_target') }}</p>
                    @endif
                    @foreach (['prospective', 'retrospective'] as $mode)
                        @php $evaluation = $overview[$mode]; @endphp
                        @if ($evaluation)
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                <h3 class="font-semibold">{{ __('slo_overview.evaluation_mode.'.$evaluation['evaluation_mode']) }}</h3>
                                <p>{{ $formatTime($evaluation['evaluation_start']) }} – {{ $formatTime($evaluation['evaluation_end']) }}</p>
                                <p role="status">{{ __('slo_overview.target_status.'.$evaluation['target_status']) }}
                                    · {{ __('slo_overview.actual_combined') }}: {{ $evaluation['target_status'] === 'insufficient_evidence' ? '—' : $formatPercent($evaluation['sli']['combined_latency_success_percent']) }}</p>
                                <p>{{ __('slo_overview.evaluation_coverage') }}: {{ $formatPercent($evaluation['coverage']['coverage_percent']) }}
                                    · {{ __('slo_overview.missing_opportunities') }}: {{ number_format($evaluation['coverage']['missing_opportunities']) }}</p>
                                @foreach ($evaluation['warnings'] as $warning)
                                    <p class="text-warning-700 dark:text-warning-400">{{ __('slo_overview.warnings.'.$warning) }}</p>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                    @if ($approved && ! $overview['prospective'] && ! $overview['retrospective'])
                        <p role="status">{{ __('slo_overview.awaiting_evaluation') }}</p>
                    @endif
                </div>
            </x-filament::section>
        </div>

        <x-filament::section :heading="__('slo_overview.spc_heading')">
            <div class="grid gap-2 sm:grid-cols-3 text-sm">
                @foreach ($overview['spc'] as $chartType => $state)
                    <p>{{ __('early_deterioration.charts.'.$chartType) }}: <strong>{{ __('early_deterioration.states.'.$state) }}</strong></p>
                @endforeach
            </div>
            <a class="mt-2 inline-block text-primary-600 underline dark:text-primary-400"
                href="{{ \App\Filament\Pages\EarlyDeteriorationDetection::getUrl(['service' => $overview['service_id']]) }}">
                {{ __('slo_overview.spc_details') }}
            </a>
        </x-filament::section>
    @endif
</section>
