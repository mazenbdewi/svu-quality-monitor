<x-filament-panels::page>
    <p>{{ __('spc_phase2.notice') }}</p>
    <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <label>{{ __('spc_phase2.baseline') }}
            <select wire:model.live="baselineId" class="fi-input" style="color:inherit;background:transparent">
                <option value="">—</option>
                @foreach($baselines as $b)<option value="{{ $b->id }}">{{ $b->monitoredService?->name }} · {{ $b->chart_type }} · v{{ $b->version }} · {{ $b->status }}</option>@endforeach
            </select>
        </label>
        <label>{{ __('spc_phase2.mode') }}<select wire:model.live="mode" style="color:inherit;background:transparent"><option value="live">{{ __('spc_phase2.live') }}</option><option value="retrospective">{{ __('spc_phase2.retrospective') }}</option></select></label>
    </div>
    @if($baseline)
        <p>{{ $baseline->chart_type }} · v{{ $baseline->version }} · {{ $baseline->status }} · {{ __('spc_phase2.compatibility') }}: {{ $compatible ? '✓' : 'baseline_incompatible' }}<br>
        {{ __('spc_phase2.last') }}: {{ $latestEvaluation?->detected_at?->toIso8601String() ?? '—' }} · {{ $latestEvaluation?->status ?? 'awaiting_evaluation' }}</p>
        <p>{{ __('spc_phase2.limits') }}</p>
        @if($points->isNotEmpty())
            @php
                $max = max(1, (float)$points->max('value'), (float)$points->max('upper_control_limit')) * 1.1;
                $firstTime = $points->first()->observed_at->getTimestamp();
                $timeSpan = max(1,$points->last()->observed_at->getTimestamp()-$firstTime);
                $x = fn($i) => 45 + ($points[$i]->observed_at->getTimestamp()-$firstTime) * 900 / $timeSpan;
                $y = fn($v) => 250 - 210 * $v / $max;
                $signalPointIds = \App\Models\SpcSignal::whereIn('point_id',$points->pluck('id'))->pluck('point_id')->flip();
            @endphp
            <figure><figcaption>{{ __('spc_phase2.graph') }}</figcaption>
            <svg viewBox="0 0 1000 290" role="img" aria-label="Phase II {{ $baseline->chart_type }} v{{ $baseline->version }}" style="width:100%;max-height:340px" dir="ltr">
                <path d="M45 20V250H950" stroke="currentColor" fill="none"/>
                <text x="0" y="35" font-size="12" fill="currentColor">{{ round($max,3) }}</text><text x="20" y="250" fill="currentColor">0</text>
                @foreach(['center_line'=>'#22c55e','upper_control_limit'=>'#f59e0b','lower_control_limit'=>'#f59e0b'] as $field=>$color)
                    @foreach($points as $i=>$p)
                        @if($p->$field !== null)
                            <line x1="{{ $x(max(0,$i-1)) }}" y1="{{ $y($points[max(0,$i-1)]->$field ?? $p->$field) }}" x2="{{ $x($i) }}" y2="{{ $y($p->$field) }}" stroke="{{ $color }}" stroke-width="2"/>
                        @endif
                    @endforeach
                @endforeach
                @foreach($points as $i=>$p)
                    @if($p->value !== null)
                        <circle cx="{{ $x($i) }}" cy="{{ $y($p->value) }}" r="4" fill="{{ $signalPointIds->has($p->id) ? '#ef4444' : '#3b82f6' }}"><title>{{ $p->status }} · observed {{ $p->observed_at->toIso8601String() }} · detected {{ $p->detected_at->toIso8601String() }} · value {{ $p->value }} · n {{ $p->subgroup_size ?? '—' }}</title></circle>
                    @endif
                @endforeach
                <text x="45" y="280" font-size="12" fill="currentColor">{{ $points->first()->observed_at->toIso8601String() }}</text><text x="680" y="280" font-size="12" fill="currentColor">{{ $points->last()->observed_at->toIso8601String() }}</text>
            </svg></figure>
        @endif
    @else <p>{{ __('spc_phase2.empty') }}</p> @endif
    <x-filament::section :heading="__('spc_phase2.signals')">
        <div style="overflow:auto"><table style="width:100%" dir="ltr"><thead><tr><th>ID</th><th>Chart / version / mode</th><th>Observed UTC</th><th>Detected UTC</th><th>Value / direction</th></tr></thead><tbody>
        @foreach($signals as $s)<tr><td>{{ $s->id }}</td><td>{{ $s->chart_type }} / {{ $s->baseline_version }} / {{ $s->mode }}</td><td>{{ $s->observed_at }}</td><td>{{ $s->detected_at }}</td><td>{{ $s->value }} / {{ $s->direction }}</td></tr>@endforeach
        </tbody></table></div>
    </x-filament::section>
    <x-filament::section :heading="__('spc_phase2.episodes')">
        <div style="overflow:auto"><table style="width:100%" dir="ltr"><thead><tr><th>ID / direction</th><th>First detected</th><th>Last detected</th><th>Signals / gap min</th><th>Closed</th></tr></thead><tbody>
        @foreach($episodeRows as $e)<tr><td>{{ $e->id }} / {{ $e->direction }}</td><td>{{ $e->first_detected_at }}</td><td>{{ $e->last_detected_at }}</td><td>{{ $e->signal_count }} / {{ $e->gap_minutes }}</td><td>{{ $e->closed_at ?? '—' }}</td></tr>@endforeach
        </tbody></table></div>
    </x-filament::section>
    <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <label>{{ __('spc_phase2.start') }}<input type="datetime-local" wire:model="periodStart" style="color:inherit;background:transparent"></label>
        <label>{{ __('spc_phase2.end') }}<input type="datetime-local" wire:model="periodEnd" style="color:inherit;background:transparent"></label>
        @can('control_charts.baselines.manage')<x-filament::button wire:click="generateReport" wire:loading.attr="disabled">{{ __('spc_phase2.report') }}</x-filament::button>@endcan
    </div>
    @foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
    <label>{{ __('spc_phase2.saved') }}
        <select wire:model.live="runId" style="color:inherit;background:transparent"><option value="">—</option>
            @foreach($runs as $saved)<option value="{{ $saved->id }}">#{{ $saved->id }} · {{ $saved->mode }} · {{ $saved->period_start }} → {{ $saved->period_end }} · H={{ $saved->horizon_minutes }} / G={{ $saved->episode_gap_minutes }}</option>@endforeach
        </select>
    </label>
    @if($run)
        <p>Run #{{ $run->id }} · {{ $run->mode }} · {{ $run->evaluation_version }} · horizon {{ $run->horizon_minutes }} min · gap {{ $run->episode_gap_minutes }} min · cutoff {{ $run->data_cutoff->toIso8601String() }}</p>
        <x-filament::button wire:click="exportReport">{{ __('spc_phase2.download') }}</x-filament::button>
        <x-filament::section :heading="__('spc_phase2.metrics')">
            @foreach($run->metrics['groups'] as $metric)
                <p dir="ltr">{{ $metric['chart_type'] }} · service #{{ $metric['service_id'] }} · baseline #{{ $metric['baseline_id'] }} v{{ $metric['baseline_version'] }}</p>
                <table style="width:100%" dir="ltr"><tbody>
                    <tr><th>Incident recall</th><td>{{ $metric['incidents_with_prior_episode'] }} / {{ $metric['total_eligible_incidents'] }} · {{ $metric['recall'] === null ? '—' : round(100*$metric['recall'],2).'%' }}</td></tr>
                    <tr><th>Episode precision</th><td>{{ $metric['matched_episodes'] }} / {{ $metric['eligible_episodes'] }} · {{ $metric['precision'] === null ? '—' : round(100*$metric['precision'],2).'%' }}</td></tr>
                    <tr><th>Unmatched / censored episodes</th><td>{{ $metric['unmatched_episodes'] }} / {{ $metric['censored_episodes'] }}</td></tr>
                    <tr><th>Incidents without prior warning</th><td>{{ $metric['incidents_without_prior_signal'] }}</td></tr>
                    <tr><th>Lead seconds: median / mean / min / max</th><td>{{ $metric['median_lead_seconds'] ?? '—' }} / {{ $metric['mean_lead_seconds'] ?? '—' }} / {{ $metric['min_lead_seconds'] ?? '—' }} / {{ $metric['max_lead_seconds'] ?? '—' }}</td></tr>
                </tbody></table>
            @endforeach
            <p dir="ltr">Any SPC incident recall: {{ $run->metrics['any_spc']['incidents_with_prior_episode'] }} / {{ $run->metrics['any_spc']['eligible_incidents'] }}</p>
        </x-filament::section>
        <x-filament::section :heading="__('spc_phase2.timeline')">
            @foreach($run->dataset as $dataset)
                @foreach($dataset['incidents'] as $incident)
                    <p>Incident #{{ $incident['incident_id'] }} · baseline #{{ $dataset['baseline_id'] }} · {{ $incident['status'] }}</p>
                    <ol dir="ltr">@foreach($incident['timeline'] as $event=>$timestamp)<li>{{ $event }}: {{ $timestamp ?? '—' }}</li>@endforeach</ol>
                @endforeach
                <ul dir="ltr">
                    @foreach($dataset['episodes'] as $episode)<li>Episode #{{ $episode['episode_id'] }} · {{ $episode['status'] }} · {{ $episode['first_detected_at'] }} · {{ $episode['signal_count'] }} signals</li>@endforeach
                    @foreach($dataset['excluded_incidents'] as $excluded)<li>Incident #{{ $excluded['incident_id'] }} · excluded: {{ $excluded['reason'] }}</li>@endforeach
                </ul>
            @endforeach
            @foreach($links as $link)<p dir="ltr">Incident #{{ $link->incident_id }} ← episode #{{ $link->episode_id }} · {{ $link->lead_seconds }} s · {{ $link->is_primary ? 'primary / earliest' : 'secondary' }} · {{ $link->is_nearest ? 'nearest' : '' }}</p>@endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
