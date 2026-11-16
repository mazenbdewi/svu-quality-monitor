<?php

namespace App\Services;

use App\Models\MonitoredService;
use App\Models\SloProposal;
use App\Models\User;
use App\Services\Baselines\ResearchSnapshotEncoding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Controlled lifecycle for frozen, provisional research objectives; no operational activation. */
class SloProposalService
{
    public const PERMISSION = 'slo.proposals.manage';

    public function __construct(private SloCalibrationAnalysis $calibration, private InstitutionTimezone $institutionTimezone, private AuditLogger $audit) {}

    public function prepare(MonitoredService $service, User $actor): SloProposal
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($service, $actor): SloProposal {
            $service = MonitoredService::query()->lockForUpdate()->findOrFail($service->id);
            $payload = $this->calibration->propose($service);

            return $this->persist($service, $payload, $actor);
        }, 3);
    }

    /** Scheduler-only path. The caller verifies evidence; this method also enforces atomic locking. */
    public function prepareAutomatically(MonitoredService $service): ?SloProposal
    {
        return DB::transaction(function () use ($service): ?SloProposal {
            $service = MonitoredService::query()->lockForUpdate()->findOrFail($service->id);
            if (! $service->is_active || ! in_array($service->check_type->value, ['http', 'api'], true)
                || SloProposal::query()->where('monitored_service_id', $service->id)->exists()) {
                return null;
            }

            $payload = $this->calibration->propose($service);
            if ($payload['status'] !== 'provisional_research_only') {
                return null;
            }

            return $this->persist($service, $payload, null);
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function persist(MonitoredService $service, array $payload, ?User $actor): SloProposal
    {
        $identity = hash('sha256', ResearchSnapshotEncoding::encode([
            $service->id, $payload['policy_version'], $payload['calibration_start'], $payload['calibration_end'],
            $payload['calibration_cutoff'], $payload['configuration_fingerprint'],
        ]));
        $existing = SloProposal::query()->where('identity_digest', $identity)->first();
        if ($existing) {
            self::assertIntegrity($existing);
            // MySQL JSON can change the final binary float digits on storage.
            // Compare research calculations to 12 decimal places, but
            // never replace the already frozen snapshot.
            if (! hash_equals(self::comparableDigest($existing->calibration_payload), self::comparableDigest($payload))) {
                throw new LogicException('Historical calibration differs from the frozen proposal; investigate data provenance.');
            }

            return $existing;
        }
        $proposal = SloProposal::query()->create([
            'monitored_service_id' => $service->id,
            'policy_version' => $payload['policy_version'],
            'calibration_start' => $payload['calibration_start'],
            'calibration_end' => $payload['calibration_end'],
            'calibration_cutoff' => $payload['calibration_cutoff'],
            'analysis_timezone' => $payload['analysis_timezone'],
            'configuration_fingerprint' => $payload['configuration_fingerprint'],
            'identity_digest' => $identity,
            'payload_digest' => self::digest($payload),
            'calibration_payload' => $payload,
            'proposed_latency_ms' => $payload['proposed_combined_latency_objective_ms'],
            'proposed_compliance_percent' => $payload['proposed_combined_compliance_percent'],
            'status' => 'draft', 'created_by' => $actor?->id,
        ]);
        // Freeze the bytes represented by the database's JSON value, not PHP's
        // pre-insert float serialization. This remains atomic in the caller's transaction.
        $storedDigest = self::digest($proposal->fresh()->calibration_payload);
        if (! hash_equals($proposal->payload_digest, $storedDigest)) {
            DB::table('slo_proposals')->where('id', $proposal->id)->update(['payload_digest' => $storedDigest]);
            $proposal->refresh();
        }
        $this->audit->log('slo.proposal.created', $proposal, 'Provisional SLO research proposal created',
            context: ['service_id' => $service->id, 'policy_version' => $proposal->policy_version,
                'actor_id' => $actor?->id, 'initiator' => $actor ? 'researcher' : 'scheduled_system'], actor: $actor);

        return $proposal;
    }

    /** Float comparison only for repeat preparation; the stored digest stays exact. */
    private static function comparableDigest(array $payload): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (is_float($value)) {
                return round($value, 12);
            }
            if (is_array($value)) {
                return array_map($normalize, $value);
            }

            return $value;
        };

        return hash('sha256', ResearchSnapshotEncoding::encode($normalize($payload)));
    }

    public function review(SloProposal $proposal, User $actor, string $notes, bool $acknowledgeLimitations): SloProposal
    {
        return $this->transition($proposal, $actor, 'draft', 'reviewed', function (SloProposal $row) use ($actor, $notes, $acknowledgeLimitations): array {
            if (trim($notes) === '' || ! $acknowledgeLimitations) {
                $this->invalid('Review requires notes and explicit acknowledgement of research limitations.');
            }

            return ['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => trim($notes), 'limitations_acknowledged' => true];
        });
    }

    public function approve(SloProposal $proposal, User $actor, string $rationale): SloProposal
    {
        return $this->transition($proposal, $actor, 'reviewed', 'approved', function (SloProposal $row) use ($actor, $rationale): array {
            if (! $row->limitations_acknowledged || trim($rationale) === '') {
                $this->invalid('Approval requires acknowledged limitations and a decision rationale.');
            }
            if ($row->calibration_payload['status'] !== 'provisional_research_only' || $row->proposed_latency_ms === null) {
                $this->invalid('Insufficient-evidence proposals cannot be approved.');
            }
            $service = MonitoredService::query()->findOrFail($row->monitored_service_id);
            if (! hash_equals($row->configuration_fingerprint, $this->calibration->configurationFingerprint($service, $this->institutionTimezone->name()))) {
                $this->invalid('Monitoring configuration differs from calibration; prepare a new proposal.');
            }

            return ['approved_by' => $actor->id, 'approved_at' => now(), 'approval_rationale' => trim($rationale)];
        });
    }

    public function retire(SloProposal $proposal, User $actor, string $reason): SloProposal
    {
        return $this->transition($proposal, $actor, 'approved', 'retired', function () use ($actor, $reason): array {
            if (trim($reason) === '') {
                $this->invalid('Retirement requires a reason.');
            }

            return ['retired_by' => $actor->id, 'retired_at' => now(), 'retirement_reason' => trim($reason)];
        });
    }

    public static function assertIntegrity(SloProposal $proposal): void
    {
        $payload = $proposal->calibration_payload;
        if (! hash_equals($proposal->payload_digest, self::digest($payload))
            || $proposal->policy_version !== $payload['policy_version']
            || $proposal->configuration_fingerprint !== $payload['configuration_fingerprint']
            || $proposal->calibration_start->toIso8601String() !== $payload['calibration_start']
            || $proposal->calibration_end->toIso8601String() !== $payload['calibration_end']
            || $proposal->calibration_cutoff->toIso8601String() !== $payload['calibration_cutoff']
            || $proposal->proposed_latency_ms !== $payload['proposed_combined_latency_objective_ms']
            || (float) $proposal->proposed_compliance_percent !== (float) $payload['proposed_combined_compliance_percent']
            || ! hash_equals($proposal->identity_digest, hash('sha256', ResearchSnapshotEncoding::encode([
                $proposal->monitored_service_id, $payload['policy_version'], $payload['calibration_start'],
                $payload['calibration_end'], $payload['calibration_cutoff'], $payload['configuration_fingerprint'],
            ])))) {
            throw new LogicException('Frozen SLO calibration payload integrity check failed.');
        }
    }

    private function transition(SloProposal $proposal, User $actor, string $from, string $to, callable $change): SloProposal
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($proposal, $actor, $from, $to, $change): SloProposal {
            MonitoredService::query()->lockForUpdate()->findOrFail($proposal->monitored_service_id);
            $row = SloProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($row->status !== $from) {
                $this->invalid("Only {$from} proposals can become {$to}.");
            }
            self::assertIntegrity($row);
            $updates = $change($row);
            DB::table('slo_proposals')->where('id', $row->id)->update($updates + ['status' => $to, 'updated_at' => now()]);
            $row->refresh();
            $this->audit->log('slo.proposal.'.$to, $row, 'SLO research proposal '.$to,
                context: ['service_id' => $row->monitored_service_id, 'policy_version' => $row->policy_version, 'actor_id' => $actor->id], actor: $actor);

            return $row;
        }, 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->fresh()?->is_active) {
            throw new AuthorizationException;
        }
        Gate::forUser($actor->fresh())->authorize(self::PERMISSION);
    }

    private static function digest(array $payload): string
    {
        return hash('sha256', ResearchSnapshotEncoding::encode($payload));
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['slo_proposal' => $message]);
    }
}
