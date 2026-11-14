<?php

namespace Tests\Feature;

use App\Filament\Resources\ControlChartBaselines\Pages\ListControlChartBaselines;
use App\Filament\Resources\ControlChartBaselines\Pages\ViewControlChartBaseline;
use App\Models\AuditLog;
use App\Models\ControlChartBaseline;
use App\Models\MonitoredService;
use App\Models\User;
use App\Services\Baselines\DeletePhaseOneDraft;
use App\Services\Baselines\PhaseOneBaselineService;
use App\Services\Baselines\PhaseOneBaselineSubmission;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhaseOneDraftDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private MonitoredService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-03 12:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');
        $this->service = MonitoredService::factory()->create(['check_type' => 'http', 'created_at' => '2026-01-01', 'check_interval_minutes' => 60]);
        foreach ([100, 110, 130, 160] as $hour => $value) {
            $at = Carbon::parse('2026-09-01 00:00:00', 'UTC')->addHours($hour);
            $this->service->serviceChecks()->make([
                'checked_at' => $at, 'source' => 'automatic', 'check_type' => 'http', 'is_success' => true,
                'is_slow' => $hour === 3, 'response_time_ms' => $value, 'is_during_maintenance' => false,
            ])->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
        }
    }

    /** @return Collection<int, ControlChartBaseline> */
    private function createSet(): Collection
    {
        app(PhaseOneBaselineSubmission::class)->create([
            'service_id' => $this->service->id,
            'baseline_start' => '2026-09-01 00:00:00',
            'baseline_end' => '2026-09-01 04:00:00',
            'aggregation_interval' => 'hourly',
            'metrics' => ['latency', 'problematic'],
        ], 'UTC', $this->admin);

        return ControlChartBaseline::query()->orderBy('id')->get();
    }

    public function test_table_action_deletes_explicit_three_draft_set_and_only_its_samples(): void
    {
        $rows = $this->createSet();
        $this->assertCount(3, $rows);
        $this->assertNotNull($rows->first()->draft_set_id);
        $this->assertCount(1, $rows->pluck('draft_set_id')->unique());
        $checksBefore = DB::table('service_checks')->get()->toJson();

        $component = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->assertActionVisible(TestAction::make('delete_draft')->table($rows->first()))
            ->mountAction(TestAction::make('delete_draft')->table($rows->first()));
        $this->assertStringContainsString(
            e(__('baselines.delete_confirmation', ['count' => 3])),
            $component->getMountedActionModalHtml(),
        );
        $component
            ->callMountedAction()
            ->assertNotified(__('baselines.deleted', ['count' => 3]));

        $this->assertDatabaseCount('control_chart_baselines', 0);
        $this->assertDatabaseCount('control_chart_baseline_samples', 0);
        $this->assertSame($checksBefore, DB::table('service_checks')->get()->toJson());
        $this->assertSame(3, AuditLog::where('event', 'baseline.deleted')->count());
    }

    public function test_legacy_ungrouped_draft_deletes_only_selected_record(): void
    {
        $engine = app(PhaseOneBaselineService::class);
        $first = $engine->create($this->service, 'i_chart', '2026-09-01T00:00:00Z', '2026-09-01T04:00:00Z', 'UTC', $this->admin);
        $second = $engine->create($this->service, 'mr_chart', '2026-09-01T00:00:00Z', '2026-09-01T04:00:00Z', 'UTC', $this->admin);

        $this->assertNull($first->draft_set_id);
        $this->assertSame(1, app(DeletePhaseOneDraft::class)->delete($first, $this->admin));
        $this->assertDatabaseMissing('control_chart_baselines', ['id' => $first->id]);
        $this->assertDatabaseHas('control_chart_baselines', ['id' => $second->id, 'status' => 'draft']);
    }

    public function test_detail_page_has_the_same_single_confirmation_delete_action(): void
    {
        $draft = app(PhaseOneBaselineService::class)->create(
            $this->service,
            'i_chart',
            '2026-09-01T00:00:00Z',
            '2026-09-01T04:00:00Z',
            'UTC',
            $this->admin,
        );

        Livewire::actingAs($this->admin)->test(ViewControlChartBaseline::class, ['record' => $draft->id])
            ->assertActionVisible('delete_draft')
            ->mountAction('delete_draft')
            ->callMountedAction()
            ->assertNotified(__('baselines.deleted', ['count' => 1]))
            ->assertRedirect('/admin/control-chart-baselines');

        $this->assertDatabaseMissing('control_chart_baselines', ['id' => $draft->id]);
    }

    public function test_set_is_not_bulk_deleted_when_one_member_is_no_longer_a_draft(): void
    {
        $rows = $this->createSet();
        app(PhaseOneBaselineService::class)->review($rows[1], $this->admin, 'Reviewed before concurrent deletion', true);

        $this->assertSame(1, app(DeletePhaseOneDraft::class)->delete($rows[0], $this->admin));
        $this->assertDatabaseMissing('control_chart_baselines', ['id' => $rows[0]->id]);
        $this->assertDatabaseHas('control_chart_baselines', ['id' => $rows[1]->id, 'status' => 'reviewed']);
        $this->assertDatabaseHas('control_chart_baselines', ['id' => $rows[2]->id, 'status' => 'draft']);
    }

    public function test_server_side_permission_and_current_draft_status_are_required(): void
    {
        $draft = $this->createSet()->first();
        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');

        try {
            app(DeletePhaseOneDraft::class)->delete($draft, $viewer);
            $this->fail('Expected authorization to fail.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('control_chart_baselines', ['id' => $draft->id]);
        }

        app(PhaseOneBaselineService::class)->review($draft, $this->admin, 'Reviewed before deletion attempt', true);
        $this->expectException(ValidationException::class);
        app(DeletePhaseOneDraft::class)->delete($draft, $this->admin);
    }

    public static function nonDraftStatuses(): array
    {
        return [['reviewed'], ['approved'], ['rejected'], ['retired']];
    }

    #[DataProvider('nonDraftStatuses')]
    public function test_every_non_draft_lifecycle_status_is_hidden_and_rejected_server_side(string $status): void
    {
        $baseline = $this->createSet()->first();
        DB::table('control_chart_baselines')->where('id', $baseline->id)->update(['status' => $status]);
        $baseline->refresh();

        Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->assertActionHidden(TestAction::make('delete_draft')->table($baseline));

        try {
            app(DeletePhaseOneDraft::class)->delete($baseline, $this->admin);
            $this->fail("Expected {$status} baseline deletion to fail.");
        } catch (ValidationException) {
            $this->assertDatabaseHas('control_chart_baselines', ['id' => $baseline->id, 'status' => $status]);
        }
    }

    public static function locales(): array
    {
        return [['en'], ['ar']];
    }

    #[DataProvider('locales')]
    public function test_delete_label_and_single_confirmation_are_localized(string $locale): void
    {
        app()->setLocale($locale);
        $draft = $this->createSet()->first();
        $component = Livewire::actingAs($this->admin)->test(ListControlChartBaselines::class)
            ->assertSee(__('baselines.delete'))
            ->mountAction(TestAction::make('delete_draft')->table($draft));

        $modal = $component->getMountedActionModalHtml();
        $this->assertStringContainsString(e(__('baselines.delete')), $modal);
        $this->assertStringContainsString(e(__('baselines.delete_confirmation', ['count' => 3])), $modal);
    }

    public function test_phase_two_reference_blocks_deletion_without_removing_history(): void
    {
        $draft = $this->createSet()->first();
        DB::table('spc_monitoring_evaluations')->insert([
            'baseline_id' => $draft->id,
            'service_id' => $this->service->id,
            'chart_type' => $draft->chart_type,
            'mode' => 'retrospective',
            'detected_at' => now(),
            'data_cutoff' => now(),
            'calculation_version' => 'test',
            'status' => 'evaluated',
            'context' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(DeletePhaseOneDraft::class)->delete($draft, $this->admin);
            $this->fail('Expected protected monitoring history to block deletion.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('control_chart_baselines', ['id' => $draft->id]);
            $this->assertDatabaseHas('spc_monitoring_evaluations', ['baseline_id' => $draft->id]);
        }
    }
}
