<?php

namespace App\Services\Baselines;

use App\Models\ControlChartBaseline;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeletePhaseOneBaseline
{
    private const DELETABLE_STATUSES = ['draft', 'rejected'];

    private const PROTECTED_REFERENCE_TABLES = [
        'spc_monitoring_evaluations',
        'spc_monitoring_points',
        'spc_signal_episodes',
        'spc_signals',
        'spc_incident_links',
    ];

    public function __construct(private AuditLogger $audit) {}

    /** Number shown in the confirmation dialog; final eligibility is rechecked under locks. */
    public function count(ControlChartBaseline $baseline): int
    {
        if (! in_array($baseline->status, self::DELETABLE_STATUSES, true)) {
            return 0;
        }

        return $this->deletionTargets($baseline)->count();
    }

    /**
     * Rejected baselines are always explicit single-record deletions. A selected draft
     * may delete its explicit creation set only while every member remains a draft.
     */
    public function delete(ControlChartBaseline $baseline, User $actor): int
    {
        return DB::transaction(function () use ($baseline, $actor): int {
            $this->authorize($actor);
            $snapshot = ControlChartBaseline::query()->find($baseline->id);
            if (! $snapshot) {
                $this->invalid();
            }

            // Match creation/lifecycle lock order and prevent concurrent set expansion.
            DB::table('monitored_services')->where('id', $snapshot->monitored_service_id)->lockForUpdate()->first();
            $selected = ControlChartBaseline::query()->lockForUpdate()->find($baseline->id);
            if (! $selected || ! in_array($selected->status, self::DELETABLE_STATUSES, true)) {
                $this->invalid();
            }

            $rows = $this->deletionTargets($selected, true);
            if ($rows->isEmpty() || ! $rows->contains($selected->id)) {
                $this->invalid();
            }

            $ids = $rows->pluck('id');
            foreach (self::PROTECTED_REFERENCE_TABLES as $table) {
                if (DB::table($table)->whereIn('baseline_id', $ids)->lockForUpdate()->first(['baseline_id'])) {
                    throw ValidationException::withMessages(['baseline' => __('baselines.delete_protected')]);
                }
            }

            $deletedAt = now();
            foreach ($rows as $row) {
                $this->audit->log('baseline.deleted', $row, 'Phase I baseline permanently deleted', context: [
                    'baseline_id' => $row->id,
                    'deleted_baseline_ids' => $ids->values()->all(),
                    'draft_set_id' => $row->draft_set_id,
                    'previous_status' => $row->status,
                    'actor_id' => $actor->id,
                    'deleted_at' => $deletedAt->toIso8601String(),
                ], actor: $actor);
            }

            // Samples are frozen copies specific to these records. Raw service checks and
            // all operational/research history tables above remain untouched.
            DB::table('control_chart_baseline_samples')->whereIn('control_chart_baseline_id', $ids)->delete();
            $deleted = DB::table('control_chart_baselines')->whereIn('id', $ids)->whereIn('status', self::DELETABLE_STATUSES)->delete();
            if ($deleted !== $ids->count()) {
                throw ValidationException::withMessages(['baseline' => __('baselines.delete_changed')]);
            }

            return $deleted;
        }, 3);
    }

    /** @return Collection<int, ControlChartBaseline> */
    private function deletionTargets(ControlChartBaseline $selected, bool $lock = false): Collection
    {
        if ($selected->status === 'rejected' || ! $selected->draft_set_id) {
            $query = ControlChartBaseline::query()->whereKey($selected->id);
        } else {
            $query = ControlChartBaseline::query()->where('draft_set_id', $selected->draft_set_id);
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->orderBy('id')->get();

        // Never include rejected or other mixed-status siblings implicitly.
        if ($selected->status === 'draft' && $rows->every(fn (ControlChartBaseline $row): bool => $row->status === 'draft')) {
            return $rows;
        }

        return $rows->where('id', $selected->id)
            ->filter(fn (ControlChartBaseline $row): bool => in_array($row->status, self::DELETABLE_STATUSES, true))
            ->values();
    }

    private function authorize(User $actor): void
    {
        $fresh = $actor->fresh();
        if (! $fresh?->is_active) {
            throw new AuthorizationException;
        }
        Gate::forUser($fresh)->authorize(PhaseOneBaselineService::PERMISSION);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['baseline' => __('baselines.delete_changed')]);
    }
}
