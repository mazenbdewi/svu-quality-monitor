@props(['value', 'status' => 'no_data'])
@if ($value === null)
    <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('monitoring.sla.statuses.no_data') }}</span>
@else
    @php($percent = (float) $value)
    <div class="min-w-32 space-y-1" title="{{ __('ux.help.budget') }}">
        <p class="text-sm tabular-nums">{{ __('ux.budget_used', ['percent' => number_format($percent, 1)]) }}</p>
        <div role="progressbar" aria-label="{{ __('ux.help.budget') }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, max(0, $percent)) }}" aria-valuetext="{{ __('ux.budget_used', ['percent' => number_format($percent, 1)]) }}" class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
            <div @class(['h-full rounded-full', 'bg-danger-600' => $status === 'breached', 'bg-warning-600' => $status === 'at_risk', 'bg-success-600' => $status === 'met', 'bg-gray-500' => ! in_array($status, ['breached', 'at_risk', 'met'], true)]) style="width: {{ min(100, max(0, $percent)) }}%"></div>
        </div>
    </div>
@endif
