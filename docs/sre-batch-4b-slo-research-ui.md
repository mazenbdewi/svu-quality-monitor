# Batch 4B: contextual SLO research lifecycle

The existing Performance Analysis page hosts the SLO review controls inside its on-demand service-quality overview. No primary navigation item, scheduler, migration, statistical formula, or operational threshold change was introduced.

Arabic and English Draft-screen screenshots are in [Arabic](screenshots/batch-4b-draft-ar.png) and [English](screenshots/batch-4b-draft-en.png). They use a disposable local SQLite demonstration, not production measurements or proposals.

## Automatic preparation follow-up (Batch 4B.1)

Batch 4B intentionally had no preparation trigger. Batch 4B.1 adds the scheduled, queued preparation described in [the automation protocol](sre-batch-4b1-automatic-slo-preparation.md). There is still no page-open trigger or manual preparation button. The original `SloProposalService::prepare(service, actor)` remains available to authorized application workflows and tests; the new automatic path is system-attributed, one-time per service, and only creates an evidence-adequate provisional Draft.

## Lifecycle and access

Users need both the existing page permission (`control_charts.view`) and `reliability.view` to load the overview. Draft, Reviewed, and Retired proposal details and actions additionally require `slo.proposals.manage`. Reliability viewers without that permission see only Approved research targets and evaluations. The page does not expose JSON payloads or mutable calibration fields.

The current proposal is loaded only on demand for the selected HTTP/API service. Review is offered for Draft with required notes and explicit limitations acknowledgement. Approval is offered for Reviewed only when evidence is provisional and current configuration matches calibration, with a required rationale. Retirement is offered for Approved with a required reason. Every action uses a confirmation modal and delegates to `SloProposalService`, which rechecks active-user permission, status, frozen digest and configuration inside a database transaction and writes the existing audit event. A stale loaded proposal is rejected; opening a page never changes proposal state.

The summary shows service, lifecycle, objective in milliseconds and seconds, policy, calibration period/cutoff, P90/P99, compliance target, executed and valid-latency counts, coverage, missing opportunities, warnings, and configuration compatibility. Frozen values are display-only. Inadequate coverage and incompatible configuration never supply headline combined-compliance cards; the existing evaluator's `insufficient_evidence` state is shown with an unassessable actual target result. Retrospective observations remain explicitly separate from prospective post-approval evaluation. No operational `warning_response_ms` or `critical_response_ms` is changed.

Performance remains on demand for one selected service. A successful lifecycle action explicitly reloads the overview, so it may incur the fixed-window percentile query; ordinary page rendering does not. There is no cross-request cache, and the calculation timestamp identifies retained Livewire results.
