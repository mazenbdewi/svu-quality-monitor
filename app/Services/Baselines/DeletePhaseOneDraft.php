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

class DeletePhaseOneDraft
{
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
        if ($baseline->status !== 'draft') {
            return 0;
        }

        return $this->eligibleSetIds($baseline)->count();
    }

    /**
     * Delete the selected draft, or its explicitly identified creation set when every
     * member remains a draft. Operational and Phase II records are never cascaded.
     */
    public function delete(ControlChartBaseline $baseline, User $actor): int
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($baseline, $actor): int {
            $snapshot = ControlChartBaseline::query()->find($baseline->id);
            if (! $snapshot) {
                $this->invalid();
            }

            // Match creation/lifecycle lock order and prevent concurrent set expansion.
            DB::table('monitored_services')->where('id', $snapshot->monitored_service_id)->lockForUpdate()->first();
            $selected = ControlChartBaseline::query()->lockForUpdate()->find($baseline->id);
            if (! $selected || $selected->status !== 'draft') {
                $this->invalid();
            }

            $rows = $this->eligibleSetIds($selected, true);
            if ($rows->isEmpty() || ! $rows->contains($selected->id)) {
                $this->invalid();
            }

            $ids = $rows->pluck('id');
            foreach (self::PROTECTED_REFERENCE_TABLES as $table) {
                if (DB::table($table)->whereIn('baseline_id', $ids)->exists()) {
                    throw ValidationException::withMessages(['baseline' => __('baselines.delete_protected')]);
                }
            }

            foreach ($rows as $row) {
                $this->audit->log('baseline.deleted', $row, 'Phase I draft baseline permanently deleted', context: [
                    'baseline_id' => $row->id,
                    'draft_set_id' => $row->draft_set_id,
                    'deleted_count' => $rows->count(),
                    'service_id' => $row->monitored_service_id,
                    'chart_type' => $row->chart_type,
                    'version' => $row->version,
                    'status' => $row->status,
                    'actor_id' => $actor->id,
                ], actor: $actor);
            }

            // Samples are frozen copies specific to these drafts. Raw service checks and
            // all operational/research history tables above remain untouched.
            DB::table('control_chart_baseline_samples')->whereIn('control_chart_baseline_id', $ids)->delete();
            $deleted = DB::table('control_chart_baselines')->whereIn('id', $ids)->where('status', 'draft')->delete();
            if ($deleted !== $ids->count()) {
                throw ValidationException::withMessages(['baseline' => __('baselines.delete_changed')]);
            }

            return $deleted;
        }, 3);
    }

    /** @return Collection<int, ControlChartBaseline> */
    private function eligibleSetIds(ControlChartBaseline $selected, bool $lock = false): Collection
    {
        $query = ControlChartBaseline::query();
        if ($selected->draft_set_id) {
            $query->where('draft_set_id', $selected->draft_set_id);
        } else {
            $query->whereKey($selected->id);
        }
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->orderBy('id')->get();

        // A known set is deleted as one unit only while every member is eligible.
        // Otherwise, retain the set and permit deletion of the selected draft alone.
        if ($rows->contains(fn (ControlChartBaseline $row): bool => $row->status !== 'draft')) {
            return $rows->where('id', $selected->id)->where('status', 'draft')->values();
        }

        return $rows;
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
