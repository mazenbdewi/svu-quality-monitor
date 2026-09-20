<?php

namespace Tests\Feature;

use App\Exports\Baselines\PhaseOneBaselineExport;
use App\Filament\Resources\ControlChartBaselines\ControlChartBaselineResource;
use App\Filament\Resources\ControlChartBaselines\Pages\ListControlChartBaselines;
use App\Filament\Resources\ControlChartBaselines\Pages\ViewControlChartBaseline;
use App\Models\AuditLog;
use App\Models\ControlChart;
use App\Models\ControlChartBaseline;
use App\Models\ControlChartBaselineSample;
use App\Models\MonitoredService;
use App\Models\ServiceCheck;
use App\Models\User;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\Baselines\ResearchSnapshotEncoding;
use App\Services\ControlChartCalculator;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhaseOneBaselineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MonitoredService $service;

    private PhaseOneBaselineService $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-03 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');
        $this->service = MonitoredService::factory()->create(['created_at' => '2026-01-01', 'check_interval_minutes' => 60]);
        $this->engine = app(PhaseOneBaselineService::class);
    }

    private function check(string $at = '2026-09-01 00:00:00', array $attributes = [], ?string $recorded = null): ServiceCheck
    {
        $check = $this->service->serviceChecks()->make(array_merge([
            'checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => true,
            'is_slow' => false, 'is_during_maintenance' => false, 'response_time_ms' => 100, 'performance_status' => 'healthy',
        ], $attributes));
        $check->forceFill(['created_at' => $recorded ?? $at, 'updated_at' => $recorded ?? $at])->save();

        return $check;
    }

    private function fixture(): void
    {
        foreach ([100, 110, 130, 160] as $i => $value) {
            $this->check('2026-09-01 0'.$i.':00:00', ['response_time_ms' => $value]);
        }
    }

    private function baseline(string $type = 'i_chart', ?string $cutoff = null): ControlChartBaseline
    {
        return $this->engine->create($this->service, $type, '2026-09-01T00:00:00Z', '2026-09-01T04:00:00Z', 'Asia/Damascus', $this->admin, cutoff: $cutoff);
    }

    private function approve(ControlChartBaseline $baseline): ControlChartBaseline
    {
        $this->engine->review($baseline, $this->admin, 'Reviewed distribution, gaps, signals and historical configuration limitations.', true);

        return $this->engine->approve($baseline, $this->admin, 'Exploratory reference accepted with documented limitations.');
    }

    public function test_known_i_mr_parameters_and_independent_draft_entity(): void
    {
        $this->fixture();
        $i = $this->baseline();
        $mr = $this->baseline('mr_chart');
        $this->assertSame('draft', $i->status);
        $this->assertSame(1, $i->version);
        $this->assertNull($i->approved_at);
        $this->assertDatabaseCount('control_charts', 0);
        $this->assertSame(4, $i->sample_counts['included']);
        $this->assertEquals(125, $i->parameters['mean']);
        $this->assertEquals(20, $i->parameters['mr_bar']);
        $this->assertEqualsWithDelta(20 / 1.128, $i->parameters['sigma_estimate'], 1e-10);
        $this->assertEqualsWithDelta(125 + 60 / 1.128, $i->parameters['ucl'], 1e-10);
        $this->assertSame(3, $mr->parameters['pair_count']);
        $this->assertEqualsWithDelta(65.34, $mr->parameters['ucl'], 1e-10);
        $this->assertEquals(100, $i->coverage_context['coverage_percent']);
        $this->assertSame(['100', '110', '130', '160'], array_map(fn ($s) => (string) $s['measurement']['response_time_ms'], $this->engine->samples($i)));
    }

    public function test_p_stores_p0_and_variable_subgroups_without_universal_limits(): void
    {
        $this->check(attributes: ['is_success' => false]);
        $this->check('2026-09-01 00:10:00', ['is_slow' => true, 'performance_status' => 'critical']);
        $this->check('2026-09-01 01:00:00');
        $baseline = $this->baseline('p_chart');
        $this->assertSame(3, $baseline->parameters['total_observed']);
        $this->assertSame(2, $baseline->parameters['total_problematic']);
        $this->assertEqualsWithDelta(2 / 3, $baseline->parameters['p0'], 1e-12);
        $this->assertSame(2, $baseline->parameters['subgroup_count']);
        $this->assertArrayNotHasKey('ucl', $baseline->parameters);
        $this->assertArrayNotHasKey('lcl', $baseline->parameters);
        $this->assertSame([2, 1, 0, 0], array_column($baseline->coverage_context['buckets'], 'observed_count'));
        $this->assertSame([2, 1], array_column($this->engine->reproduce($baseline)['points'], 'sample_size'));
    }

    public function test_approval_is_explicit_reviewed_authorized_and_audited(): void
    {
        $this->fixture();
        $baseline = $this->baseline();
        try {
            $this->engine->approve($baseline, $this->admin, 'Skipped review');
            $this->fail('Review must be mandatory');
        } catch (ValidationException) {
            $this->assertSame('draft', $baseline->fresh()->status);
        }
        $this->approve($baseline);
        $this->assertSame('approved', $baseline->status);
        $this->assertEquals($this->admin->id, $baseline->approved_by);
        $this->assertTrue($baseline->approved_at->eq(now()));
        $this->assertTrue($baseline->effective_from->eq(now()));
        $this->assertNotNull($baseline->active_key);
        $this->assertSame(['baseline.created', 'baseline.reviewed', 'baseline.approved'], AuditLog::where('auditable_type', ControlChartBaseline::class)->orderBy('id')->pluck('event')->all());
    }

    public static function tooSmall(): array
    {
        return [[0, 'no_data'], [1, 'insufficient']];
    }

    #[DataProvider('tooSmall')]
    public function test_no_data_or_insufficient_cannot_be_approved(int $n, string $state): void
    {
        if ($n) {
            $this->check();
        }
        $baseline = $this->baseline();
        $this->assertSame($state, $baseline->review_context['sufficiency']);
        $this->engine->review($baseline, $this->admin, 'Reviewed sparse data', true);
        $this->expectException(ValidationException::class);
        $this->engine->approve($baseline, $this->admin, 'Attempt approval');
    }

    public function test_preliminary_requires_acknowledged_review_and_rationale(): void
    {
        $this->fixture();
        $baseline = $this->baseline();
        $this->assertSame('preliminary', $baseline->review_context['sufficiency']);
        try {
            $this->engine->review($baseline, $this->admin, 'Notes', false);
            $this->fail('Acknowledgement required');
        } catch (ValidationException) {
            $this->assertNull($baseline->fresh()->reviewed_at);
        }
        $this->approve($baseline);
        $this->assertSame('approved', $baseline->fresh()->status);
    }

    public static function unauthorizedRoles(): array
    {
        return [['viewer'], ['operator']];
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_unauthorized_roles_cannot_approve(string $role): void
    {
        $this->fixture();
        $baseline = $this->baseline();
        $this->engine->review($baseline, $this->admin, 'Reviewed', true);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->expectException(AuthorizationException::class);
        $this->engine->approve($baseline, $user, 'Not authorized');
    }

    public function test_revoked_or_inactive_actor_cannot_mutate_baseline(): void
    {
        $this->fixture();
        $baseline = $this->baseline();
        $this->admin->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $this->engine->review($baseline, $this->admin, 'Inactive', true);
    }

    public static function immutableFields(): array
    {
        return [
            ['baseline_start', '2020-01-01'], ['analysis_timezone', 'UTC'], ['aggregation_interval', 'daily'],
            ['parameters', ['mean' => 0]], ['configuration_snapshot', []], ['calculation_version', 'changed'],
            ['sample_counts', ['included' => 0]], ['status', 'draft'],
        ];
    }

    #[DataProvider('immutableFields')]
    public function test_approved_version_cannot_be_edited(string $field, mixed $value): void
    {
        $this->fixture();
        $baseline = $this->approve($this->baseline());
        $this->expectException(LogicException::class);
        $baseline->update([$field => $value]);
    }

    public function test_bulk_update_and_member_mutations_are_rejected(): void
    {
        $this->fixture();
        $baseline = $this->approve($this->baseline());
        foreach ([
            fn () => ControlChartBaseline::whereKey($baseline->id)->update(['parameters' => []]),
            fn () => $baseline->delete(),
            fn () => $baseline->samples()->delete(),
            fn () => $baseline->samples()->first()->update(['included' => false]),
            fn () => $baseline->samples()->create(['service_check_id' => 999, 'sequence' => 99, 'included' => true, 'measurement' => [], 'exclusion_reasons' => []]),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Mutation must fail');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(4, $baseline->samples()->count());
    }

    public function test_new_versions_retain_old_parameters_and_require_explicit_retirement(): void
    {
        $this->fixture();
        $old = $this->approve($this->baseline());
        $parameters = $old->parameters;
        $new = $this->baseline();
        $this->assertSame(2, $new->version);
        $this->engine->review($new, $this->admin, 'Reviewed v2', true);
        try {
            $this->engine->approve($new, $this->admin, 'Replace');
            $this->fail('Active conflict');
        } catch (ValidationException) {
            $this->assertSame('reviewed', $new->fresh()->status);
        }
        $this->travel(1)->hours();
        $this->engine->retire($old, $this->admin, 'Infrastructure change');
        $this->engine->approve($new, $this->admin, 'Replacement reference');
        $this->assertSame('retired', $old->fresh()->status);
        $this->assertSame($parameters, $old->fresh()->parameters);
        $this->assertNotNull($old->effective_to);
        $this->assertDatabaseCount('control_chart_baselines', 2);
        $this->assertSame(1, ControlChartBaseline::where('status', 'approved')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'baseline.retired', 'actor_id' => $this->admin->id]);
    }

    public function test_rejection_preserves_membership_and_audit(): void
    {
        $this->fixture();
        $baseline = $this->baseline();
        $this->engine->reject($baseline, $this->admin, 'Nonhomogeneous period');
        $this->assertSame('rejected', $baseline->status);
        $this->assertSame(4, $baseline->samples()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'baseline.rejected']);
    }

    public function test_end_cutoff_and_late_record_boundaries_prevent_look_ahead(): void
    {
        $a = $this->check('2026-09-01 00:00:00');
        $this->check('2026-09-01 01:00:00', recorded: '2026-09-02 00:00:00');
        $this->check('2026-09-01 02:00:00');
        $this->check('2026-09-01 04:00:00');
        $baseline = $this->baseline(cutoff: '2026-09-01T02:00:00Z');
        $this->assertSame([$a->id], $baseline->samples()->where('included', true)->pluck('service_check_id')->all());
        $this->assertSame(2, $baseline->sample_counts['candidates']);
        $this->assertSame(1, $baseline->sample_counts['exclusion_counts']['recorded_after_cutoff_or_unknown']);
        $this->assertTrue($baseline->review_context['partial']);
    }

    public function test_future_baseline_period_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->engine->create($this->service, 'i_chart', '2026-09-01', '2026-09-04', 'UTC', $this->admin);
    }

    public function test_damascus_midnight_and_month_conversion_are_half_open(): void
    {
        $a = $this->check('2026-08-31 21:00:00');
        $this->check('2026-08-31 20:59:59');
        $this->check('2026-09-01 21:00:00');
        $baseline = $this->engine->create($this->service, 'i_chart', '2026-09-01', '2026-09-02', 'Asia/Damascus', $this->admin);
        $this->assertSame('2026-08-31 21:00:00', $baseline->baseline_start->toDateTimeString());
        $this->assertSame([$a->id], $baseline->samples()->pluck('service_check_id')->all());
    }

    public function test_frozen_membership_reproduces_after_raw_edits_and_deletion(): void
    {
        $this->fixture();
        foreach (['i_chart', 'mr_chart', 'p_chart'] as $type) {
            $baseline = $this->baseline($type);
            $before[$type] = $baseline;
            $this->assertEquals($baseline->parameters, $this->engine->reproduce($baseline)['parameters']);
        }
        ServiceCheck::query()->update(['response_time_ms' => 999, 'is_success' => false]);
        ServiceCheck::query()->delete();
        foreach ($before as $baseline) {
            $this->assertEquals($baseline->parameters, $this->engine->reproduce($baseline)['parameters']);
            $sheets = (new PhaseOneBaselineExport($baseline))->sheets();
            $this->assertCount(4, $sheets[1]->array());
            $this->assertEquals(100, $sheets[1]->array()[0][10]);
        }
    }

    public function test_exclusion_traceability_and_no_automatic_outlier_removal(): void
    {
        $this->check(attributes: ['source' => 'manual']);
        $this->check(attributes: ['is_during_maintenance' => true]);
        $this->check(attributes: ['metadata' => ['is_synthetic' => true]]);
        $this->check(attributes: ['check_type' => 'dns']);
        $this->check(attributes: ['response_time_ms' => null]);
        $this->check(attributes: ['is_success' => false]);
        $outlier = $this->check(attributes: ['response_time_ms' => 999999]);
        $baseline = $this->baseline();
        $this->assertSame(7, $baseline->sample_counts['candidates']);
        $this->assertSame(6, $baseline->sample_counts['excluded']);
        $this->assertSame([$outlier->id], $baseline->samples()->where('included', true)->pluck('service_check_id')->all());
        $this->assertSame(1, $baseline->sample_counts['exclusion_counts']['manual_or_unknown_source']);
        $this->assertSame(1, $baseline->sample_counts['exclusion_counts']['maintenance']);
        $this->assertSame(1, $baseline->sample_counts['exclusion_counts']['invalid_latency']);
    }

    public function test_configuration_warnings_fingerprint_and_secret_free_export(): void
    {
        $this->fixture();
        $this->service->update(['url' => 'https://user:secret-password@example.test/?token=hidden-token', 'check_config' => ['headers' => ['Authorization' => 'Bearer do-not-export']]]);
        $audit = AuditLog::create(['event' => 'service.updated', 'auditable_type' => MonitoredService::class, 'auditable_id' => $this->service->id, 'description' => 'Changed', 'after' => ['check_interval_minutes' => 60], 'context' => ['endpoint_changed' => true]]);
        $audit->forceFill(['created_at' => '2026-09-01 01:00:00'])->save();
        $baseline = $this->baseline();
        $this->assertCount(1, $baseline->review_context['configuration_changes']);
        $this->assertTrue($this->engine->compatible($baseline));
        $serialized = json_encode([$baseline->toArray(), array_map(fn ($s) => $s->array(), (new PhaseOneBaselineExport($baseline))->sheets()), AuditLog::where('event', 'like', 'baseline.%')->get()->toArray()]);
        foreach (['secret-password', 'hidden-token', 'do-not-export', 'Authorization'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->service->update(['check_interval_minutes' => 5]);
        $this->assertFalse($this->engine->compatible($baseline->fresh()));
    }

    public function test_population_failure_rolls_back_baseline_membership_and_audit(): void
    {
        $this->fixture();
        $dispatcher = clone ControlChartBaselineSample::getEventDispatcher();
        $count = 0;
        ControlChartBaselineSample::creating(function () use (&$count) {
            if (++$count === 2) {
                throw new \RuntimeException('Injected membership failure');
            }
        });
        try {
            $this->baseline();
            $this->fail('Must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected membership failure', $e->getMessage());
        } finally {
            ControlChartBaselineSample::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseCount('control_chart_baselines', 0);
        $this->assertDatabaseCount('control_chart_baseline_samples', 0);
        $this->assertSame(0, AuditLog::where('event', 'like', 'baseline.%')->count());
    }

    public function test_review_ui_is_read_only_for_viewer_and_renders_frozen_context(): void
    {
        $this->fixture();
        $baseline = $this->baseline();
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $this->actingAs($viewer)->get(ControlChartBaselineResource::getUrl('view', ['record' => $baseline]))
            ->assertOk()->assertSee('Asia/Damascus')->assertSee(__('baselines.scope'))->assertSee(__('baselines.history_limit'));
        Livewire::actingAs($viewer)->test(ViewControlChartBaseline::class, ['record' => $baseline->id])
            ->assertActionHidden('review_baseline')->assertActionHidden('approve_baseline');
        $this->assertSame('draft', $baseline->fresh()->status);
    }

    public function test_excel_contains_frozen_measurements_and_numeric_parameters(): void
    {
        $this->fixture();
        $baseline = $this->baseline();
        $content = Excel::raw(new PhaseOneBaselineExport($baseline), \Maatwebsite\Excel\Excel::XLSX);
        $this->assertStringStartsWith('PK', $content);
        $sheets = (new PhaseOneBaselineExport($baseline))->sheets();
        $this->assertSame('Membership', $sheets[1]->title());
        $this->assertSame(1, $sheets[1]->array()[0][4]);
        $this->assertSame(4, count($sheets[3]->array()));
    }

    public function test_admin_can_generate_review_and_approve_through_ui(): void
    {
        $this->fixture();
        Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->callAction('create_phase_one_baseline', data: [
                'service_id' => $this->service->id, 'chart_type' => 'i_chart', 'baseline_start' => '2026-09-01 00:00:00',
                'baseline_end' => '2026-09-01 04:00:00', 'analysis_timezone' => 'UTC', 'aggregation_interval' => 'hourly',
            ])->assertHasNoActionErrors();
        $baseline = ControlChartBaseline::firstOrFail();
        $component = Livewire::test(ViewControlChartBaseline::class, ['record' => $baseline->id]);
        $component->callAction('review_baseline', data: ['notes' => 'Reviewed distribution, configuration, signals and missing periods.', 'acknowledge' => true])->assertHasNoActionErrors();
        $this->assertSame('reviewed', $baseline->fresh()->status);
        $component->callAction('approve_baseline', data: ['reason' => 'Accepted as study reference with explicit limitations.'])->assertHasNoActionErrors();
        $this->assertSame('approved', $baseline->fresh()->status);
    }

    public function test_role_revocation_after_mount_cannot_force_approval(): void
    {
        $this->fixture();
        $baseline = $this->baseline();
        $this->engine->review($baseline, $this->admin, 'Reviewed', true);
        $component = Livewire::actingAs($this->admin)->test(ViewControlChartBaseline::class, ['record' => $baseline->id]);
        $component->mountAction('approve_baseline')->fillForm(['reason' => 'Decision prepared before role revocation']);
        $this->admin->syncRoles(['viewer']);
        $component->call('callMountedAction');
        $this->assertSame('reviewed', $baseline->fresh()->status);
        $this->assertSame(0, AuditLog::where('event', 'baseline.approved')->count());
        // Direct service check is also enforced independently of UI visibility.
        try {
            $this->engine->approve($baseline, $this->admin, 'Cached role');
            $this->fail('Must deny revoked role');
        } catch (AuthorizationException) {
            $this->assertSame('reviewed', $baseline->fresh()->status);
        }
    }

    public function test_raw_check_modified_after_cutoff_is_not_used_in_a_new_version(): void
    {
        $check = $this->check();
        $check->update(['response_time_ms' => 999]);
        $baseline = $this->baseline();
        $this->assertSame(0, $baseline->sample_counts['included']);
        $this->assertSame(1, $baseline->sample_counts['exclusion_counts']['modified_after_cutoff']);
    }

    public function test_mr_order_and_start_boundary_reproduce_exact_pairs(): void
    {
        $this->check('2026-08-31 23:59:59', ['response_time_ms' => 99999]);
        $a = $this->check(attributes: ['response_time_ms' => 100]);
        $b = $this->check(attributes: ['response_time_ms' => 130]);
        $c = $this->check(attributes: ['response_time_ms' => 110]);
        $baseline = $this->baseline('mr_chart');
        $points = $this->engine->reproduce($baseline)['points'];
        $this->assertEquals([30, 20], array_column($points, 'value'));
        $this->assertSame([$b->id, $c->id], array_column($points, 'check_id'));
        $this->assertSame([$a->id, $b->id], array_column($points, 'previous_check_id'));
    }

    public function test_approval_audit_failure_rolls_back_status_and_active_key(): void
    {
        $this->fixture();
        $baseline = $this->baseline();
        $this->engine->review($baseline, $this->admin, 'Reviewed', true);
        $dispatcher = clone AuditLog::getEventDispatcher();
        AuditLog::creating(function ($log) {
            if ($log->event === 'baseline.approved') {
                throw new \RuntimeException('Audit failure');
            }
        });
        try {
            $this->engine->approve($baseline, $this->admin, 'Approved');
            $this->fail('Must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit failure', $e->getMessage());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
        $this->assertSame('reviewed', $baseline->fresh()->status);
        $this->assertNull($baseline->fresh()->active_key);
        $this->assertNull($baseline->fresh()->approved_at);
    }

    public function test_c_and_u_cannot_be_baselines_and_exploratory_history_is_untouched(): void
    {
        $this->fixture();
        $chart = app(ControlChartCalculator::class)->calculate($this->service, 'i_chart', Carbon::parse('2026-09-01 00:00'), Carbon::parse('2026-09-01 04:00'));
        $before = $chart->toArray();
        $this->baseline();
        $this->assertSame($before, $chart->fresh()->toArray());
        $this->assertSame(1, ControlChart::count());
        foreach (['c_chart', 'u_chart'] as $type) {
            try {
                $this->baseline($type);
                $this->fail('Unsupported baseline type');
            } catch (ValidationException) {
                $this->assertSame(1, ControlChartBaseline::count());
            }
        }
    }

    public function test_membership_digest_is_independent_of_json_object_key_order(): void
    {
        $left = ['measurement' => ['is_success' => true, 'response_time_ms' => 100.0], 'service_check_id' => 7];
        $right = ['service_check_id' => 7, 'measurement' => ['response_time_ms' => 100, 'is_success' => true]];
        $this->assertSame(ResearchSnapshotEncoding::encode($left), ResearchSnapshotEncoding::encode($right));
        $this->assertNotSame(ResearchSnapshotEncoding::encode([1, 2]), ResearchSnapshotEncoding::encode([2, 1]));
    }
}
