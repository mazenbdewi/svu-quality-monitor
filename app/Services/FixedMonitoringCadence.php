<?php

namespace App\Services;

use App\Jobs\CheckMonitoredServiceJob;
use App\Models\MonitoredService;
use App\Models\MonitoringOpportunity;
use App\Models\MonitoringScheduleEpoch;
use App\Services\Baselines\BaselineConfiguration;
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Durable nominal UTC opportunities; no historical check is ever synthesized. */
class FixedMonitoringCadence
{
    public const VERSION = 'fixed-utc-slot-v1';

    public function __construct(private BaselineConfiguration $configuration) {}

    public function cutover(): ?Carbon
    {
        if (! config('monitoring.fixed_cadence.enabled')) {
            return null;
        }
        $raw = config('monitoring.fixed_cadence.cutover_utc');
        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00Z$/', $raw)) {
            throw new InvalidArgumentException('Fixed monitoring cadence requires an explicit UTC minute cutover.');
        }
        $cutover = Carbon::parse($raw)->utc();
        if ($cutover->format('Y-m-d\TH:i:s\Z') !== $raw) {
            throw new InvalidArgumentException('Invalid fixed monitoring cadence UTC cutover.');
        }

        return $cutover;
    }

    public function active(): bool
    {
        $cutover = $this->cutover();

        return $cutover !== null && now()->utc()->gte($cutover);
    }

    public function assertAtomicDatabaseQueue(): void
    {
        $queue = config('queue.connections.database');
        if (config('queue.default') !== 'database' || ($queue['driver'] ?? null) !== 'database'
            || (($queue['connection'] ?? null) !== null && $queue['connection'] !== DB::getDefaultConnection())
            || ($queue['after_commit'] ?? false) || (int) ($queue['retry_after'] ?? 0) <= CheckMonitoredServiceJob::TIMEOUT_SECONDS) {
            throw new LogicException('Fixed monitoring cadence requires the same database transaction for opportunities and queue insertion.');
        }
    }

    /** @return array{due: int, dispatched: int, missed: int, pending: int, disabled: int} */
    public function process(MonitoredService $candidate, Dispatcher $dispatcher): array
    {
        $this->assertAtomicDatabaseQueue();

        return DB::transaction(function () use ($candidate, $dispatcher): array {
            $service = MonitoredService::query()->lockForUpdate()->findOrFail($candidate->id);
            $clock = now()->utc()->startOfSecond();
            $counts = ['due' => 0, 'dispatched' => 0, 'missed' => 0, 'pending' => 0, 'disabled' => 0];
            $open = MonitoringScheduleEpoch::query()->where('monitored_service_id', $service->id)
                ->whereNull('effective_until_utc')->lockForUpdate()->first();
            if (! $open && ! MonitoringScheduleEpoch::query()->where('monitored_service_id', $service->id)->exists()
                && $service->created_at->lte($this->cutover()) && $clock->gt($this->cutover()->copy()->addMinute())) {
                throw new LogicException('Initial fixed-cadence cutover was not observed within one minute; choose a reviewed future cutover.');
            }

            if (! $service->is_active) {
                if ($open) {
                    $this->close($open, $service->updated_at->copy()->utc()->min($clock), 'service_disabled');
                    $this->cancelOutstanding($open, 'service_disabled');
                } elseif (! MonitoringScheduleEpoch::query()->where('monitored_service_id', $service->id)->exists()) {
                    $from = $this->cutover()->copy()->max($service->created_at->copy()->utc());
                    MonitoringScheduleEpoch::query()->create([
                        'monitored_service_id' => $service->id,
                        'generation' => 1,
                        'policy_version' => self::VERSION,
                        'interval_minutes' => (int) $service->check_interval_minutes,
                        'configuration_fingerprint' => $this->configuration->fingerprint($this->snapshot($service)),
                        'configuration_snapshot' => $this->snapshot($service),
                        'effective_from_utc' => $from,
                        'effective_until_utc' => $clock,
                        'next_nominal_due_at_utc' => $this->firstUnclaimedSlot($service, $clock, (int) $service->check_interval_minutes),
                        'end_reason' => 'service_disabled',
                    ]);
                }
                $counts['disabled']++;
            } else {
                $snapshot = $this->snapshot($service);
                $fingerprint = $this->configuration->fingerprint($snapshot);
                if ($open && $open->configuration_fingerprint !== $fingerprint) {
                    $this->close($open, $service->updated_at->copy()->utc()->min($clock), 'configuration_changed');
                    $this->cancelOutstanding($open, 'configuration_changed');
                    $open = null;
                }
                if (! $open) {
                    $last = MonitoringScheduleEpoch::query()->where('monitored_service_id', $service->id)->orderByDesc('generation')->first();
                    $from = $last
                        ? $last->effective_until_utc->copy()->max($service->updated_at->copy()->utc())
                        : $this->cutover()->copy()->max($service->created_at->copy()->utc());
                    $open = MonitoringScheduleEpoch::query()->create([
                        'monitored_service_id' => $service->id,
                        'generation' => ($last?->generation ?? 0) + 1,
                        'policy_version' => self::VERSION,
                        'interval_minutes' => (int) $service->check_interval_minutes,
                        'configuration_fingerprint' => $fingerprint,
                        'configuration_snapshot' => $snapshot,
                        'effective_from_utc' => $from,
                        'next_nominal_due_at_utc' => $this->firstUnclaimedSlot($service, $from, (int) $service->check_interval_minutes),
                        'active_key' => (string) $service->id,
                    ]);
                }
            }

            // A long outage is accounted in bounded chunks, including for closed epochs.
            $budget = max(1, (int) config('monitoring.fixed_cadence.maximum_slots_per_service_poll', 120));
            foreach (MonitoringScheduleEpoch::query()->where('monitored_service_id', $service->id)
                ->whereNotNull('effective_until_utc')->whereColumn('next_nominal_due_at_utc', '<', 'effective_until_utc')
                ->orderBy('id')->lockForUpdate()->get() as $closed) {
                $this->materialize($service, $closed, $clock, $dispatcher, $counts, false, $budget);
                if ($budget === 0) {
                    break;
                }
            }
            if ($service->is_active && $budget > 0) {
                $this->materialize($service, $open, $clock, $dispatcher, $counts, true, $budget);
            }

            return $counts;
        }, 3);
    }

    private function snapshot(MonitoredService $service): array
    {
        return $this->configuration->snapshot($service, 'UTC', 'nominal-utc-slot', self::VERSION);
    }

    public function fingerprint(MonitoredService $service): string
    {
        return $this->configuration->fingerprint($this->snapshot($service));
    }

    /** A research interval must not silently span a sampling-policy boundary. */
    public function crossesRegime(MonitoredService $service, Carbon $start, Carbon $end): bool
    {
        $cutover = $this->cutover();
        if ($cutover && $start->lt($cutover) && $end->gt($cutover)) {
            return true;
        }

        return MonitoringScheduleEpoch::query()->where('monitored_service_id', $service->id)
            ->where('effective_from_utc', '>', $start->copy()->utc())
            ->where('effective_from_utc', '<', $end->copy()->utc())->exists();
    }

    /** Frozen references from the prior regime need a new, separately reviewed calibration. */
    public function referenceIncompatible(MonitoredService $service, Carbon $start, Carbon $end): bool
    {
        if ($this->crossesRegime($service, $start, $end)) {
            return true;
        }
        $cutover = $this->cutover();
        if ($cutover && $end->lte($cutover) && now()->utc()->gte($cutover)) {
            return true;
        }

        return MonitoringScheduleEpoch::query()->where('monitored_service_id', $service->id)
            ->where('effective_from_utc', '>=', $end->copy()->utc())
            ->where('effective_from_utc', '<=', now()->utc())->exists();
    }

    /** Ledger completeness, separate from legacy estimated coverage and from availability. */
    public function opportunityCoverage(MonitoredService $service, Carbon $start, Carbon $end): array
    {
        if ($start->gte($end)) {
            throw new InvalidArgumentException('Opportunity coverage requires a positive UTC half-open period.');
        }
        $start = $start->copy()->utc();
        $end = $end->copy()->utc()->min(now()->utc());
        $counts = MonitoringOpportunity::query()->where('monitored_service_id', $service->id)
            ->where('nominal_due_at_utc', '>=', $start)->where('nominal_due_at_utc', '<', $end)
            ->selectRaw("COUNT(*) AS due, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed, SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) AS missed, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed, SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled, SUM(CASE WHEN status IN ('queued', 'running') THEN 1 ELSE 0 END) AS pending")
            ->first();
        $backlog = MonitoringScheduleEpoch::query()->where('monitored_service_id', $service->id)
            ->where('next_nominal_due_at_utc', '<', $end)->where(function ($query): void {
                $query->whereNull('effective_until_utc')->orWhereColumn('next_nominal_due_at_utc', '<', 'effective_until_utc');
            })->exists();
        $scopeCompatible = ! $this->crossesRegime($service, $start, $end);
        $due = (int) ($counts->due ?? 0);
        $completed = (int) ($counts->completed ?? 0);

        return [
            'basis' => 'fixed_utc_slot_ledger_v1', 'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(), 'boundary' => '[start,end)',
            'exact_opportunity_ledger' => true,
            'due' => $due, 'completed' => $completed, 'missed' => (int) ($counts->missed ?? 0),
            'failed' => (int) ($counts->failed ?? 0), 'cancelled' => (int) ($counts->cancelled ?? 0),
            'pending' => (int) ($counts->pending ?? 0), 'accounting_complete' => ! $backlog && $scopeCompatible,
            'completion_percent' => ! $backlog && $scopeCompatible && $due > 0 ? round(100 * $completed / $due, 2) : null,
        ];
    }

    private function close(MonitoringScheduleEpoch $epoch, Carbon $at, string $reason): void
    {
        $epoch->update(['effective_until_utc' => $at->max($epoch->effective_from_utc), 'end_reason' => $reason, 'active_key' => null]);
    }

    private function cancelOutstanding(MonitoringScheduleEpoch $epoch, string $reason): void
    {
        MonitoringOpportunity::query()->where('monitoring_schedule_epoch_id', $epoch->id)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'cancelled', 'reason' => $reason, 'completed_at_utc' => now()->utc()]);
    }

    /** @param array<string, int> $counts */
    private function materialize(MonitoredService $service, MonitoringScheduleEpoch $epoch, Carbon $clock, Dispatcher $dispatcher, array &$counts, bool $dispatch, int &$budget): void
    {
        $minute = $clock->copy()->startOfMinute();
        $interval = (int) $epoch->interval_minutes;
        if ($interval < 1) {
            throw new LogicException('Monitoring interval must be positive.');
        }
        $next = $epoch->next_nominal_due_at_utc->copy()->utc();
        $end = $epoch->effective_until_utc?->copy()->utc();
        $outstanding = MonitoringOpportunity::query()->where('monitored_service_id', $service->id)
            ->whereIn('status', ['queued', 'running'])->exists();
        while ($budget > 0 && $next->lte($minute) && ($end === null || $next->lt($end))) {
            $budget--;
            $counts['due']++;
            $current = $dispatch && $next->equalTo($minute);
            if (! $current || $outstanding) {
                MonitoringOpportunity::query()->create([
                    'monitored_service_id' => $service->id,
                    'monitoring_schedule_epoch_id' => $epoch->id,
                    'nominal_due_at_utc' => $next,
                    'status' => 'missed',
                    'reason' => $outstanding ? 'previous_check_pending' : 'scheduler_poll_missed',
                    'completed_at_utc' => $clock,
                ]);
                $counts['missed']++;
                if ($outstanding) {
                    $counts['pending']++;
                }
            } else {
                $opportunity = MonitoringOpportunity::query()->create([
                    'monitored_service_id' => $service->id,
                    'monitoring_schedule_epoch_id' => $epoch->id,
                    'nominal_due_at_utc' => $next,
                    'status' => 'queued',
                ]);
                $dispatcher->dispatch(new CheckMonitoredServiceJob($service->id, $opportunity->id));
                $opportunity->update(['dispatched_at_utc' => now()->utc()->startOfSecond()]);
                $counts['dispatched']++;
                $outstanding = true;
            }
            $next->addMinutes($interval);
        }
        $epoch->update(['next_nominal_due_at_utc' => $next]);
    }

    private function nextSlot(Carbon $from, int $interval): Carbon
    {
        if ($interval < 1) {
            throw new LogicException('Monitoring interval must be positive.');
        }
        $seconds = $interval * 60;

        return Carbon::createFromTimestamp((int) (ceil($from->timestamp / $seconds) * $seconds), 'UTC');
    }

    private function firstUnclaimedSlot(MonitoredService $service, Carbon $from, int $interval): Carbon
    {
        $next = $this->nextSlot($from, $interval);
        $latest = MonitoringOpportunity::query()->where('monitored_service_id', $service->id)->max('nominal_due_at_utc');
        if ($latest && $next->lte(Carbon::parse($latest, 'UTC'))) {
            return $this->nextSlot(Carbon::parse($latest, 'UTC')->addSecond(), $interval);
        }

        return $next;
    }
}
