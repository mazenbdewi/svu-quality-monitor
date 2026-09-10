<?php

namespace Tests\Feature;

use App\Filament\Resources\MonitoredServices\Pages\EditMonitoredService;
use App\Filament\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Filament\Support\DashboardPresentation;
use App\Filament\Widgets\ExecutiveDashboardWidget;
use App\Models\MonitoredService;
use App\Models\NotificationDelivery;
use App\Models\ServiceCheck;
use App\Models\SlaMetric;
use App\Models\User;
use App\Services\NotificationDeliveryRetryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class UxPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attention_orders_existing_signals_and_omits_healthy_services_without_queries(): void
    {
        $services = collect(['healthy', 'critical', 'recovering', 'down', 'pending_failure'])->map(function ($status) {
            $service = new MonitoredService(['name' => $status]);
            $service->setAttribute('executive_status', $status);
            $service->setRelation('executiveSlaMetric', null);

            return $service;
        });
        $services->first()->setRelation('executiveSlaMetric', new SlaMetric(['status' => 'breached']));
        $risk = new MonitoredService(['name' => 'Risk']);
        $risk->setAttribute('executive_status', 'healthy');
        $risk->setRelation('executiveSlaMetric', new SlaMetric(['status' => 'at_risk']));
        $services->push($risk);
        $check = new ServiceCheck(['metadata' => ['days_remaining' => 12]]);
        $check->setRelation('monitoredService', $services->first());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $items = DashboardPresentation::attention(['services' => $services, 'ssl' => ['expiring' => collect([$check])], 'spc' => ['services' => collect(['SPC service'])]]);
        $this->assertSame(['down', 'pending_failure', 'recovering', 'breached', 'at_risk', 'ssl_expiring', 'critical', 'out_of_control'], $items->pluck('status')->all());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_budget_excess_and_long_durations_remain_visible(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $html = view('components.sla-budget', ['value' => 132, 'status' => 'breached'])->render();
            $this->assertStringContainsString(__('ux.budget_used', ['percent' => '132.0']), $html);
            $this->assertStringContainsString('aria-valuenow="100"', $html);
            $this->assertStringContainsString('aria-valuetext=', $html);
        }
        $this->assertSame('49:01:01', DashboardPresentation::duration(176461));
    }

    public function test_viewer_attention_renders_localized_states_without_edit_links_or_network_checks(): void
    {
        Http::preventStrayRequests();
        $this->seed(RolesAndPermissionsSeeder::class);
        $viewer = User::factory()->create()->assignRole('viewer');
        $service = MonitoredService::factory()->create(['name' => 'Needs attention', 'operational_state' => 'down']);
        $service->serviceChecks()->create(['checked_at' => now(), 'is_success' => false, 'is_slow' => false]);
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::actingAs($viewer)->test(ExecutiveDashboardWidget::class)
                ->assertSee('Needs attention')
                ->assertSee(__('monitoring.service_status.statuses.down'))
                ->assertDontSee('/monitored-services/'.$service->id.'/edit')
                ->assertDontSee('monitoring.sla.statuses.down');
        }
    }

    public function test_sectioned_service_form_preserves_advanced_api_configuration_on_save(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole('administrator');
        $configuration = ['method' => 'POST', 'timeout_seconds' => 17, 'headers' => ['X-Review' => 'retained'], 'body' => '{"test":true}', 'json_path' => 'status', 'json_expected_value' => 'ok'];
        $service = MonitoredService::factory()->create(['check_type' => 'api', 'check_config' => $configuration, 'failure_confirmation_count' => 4, 'recovery_confirmation_count' => 3]);
        Livewire::actingAs($user)->test(EditMonitoredService::class, ['record' => $service->id])->call('save')->assertHasNoFormErrors();
        $saved = $service->fresh()->check_config;
        ksort($configuration);
        ksort($saved);
        $this->assertSame($configuration, $saved);
        $this->assertSame(4, $service->fresh()->failure_confirmation_count);
        $this->assertSame(3, $service->fresh()->recovery_confirmation_count);
    }

    public function test_retry_failure_shows_contextual_feedback_without_internal_exception_text(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole('administrator');
        $delivery = NotificationDelivery::query()->create(['event_type' => 'email_test', 'channel' => 'email', 'status' => 'failed', 'deduplication_key' => 'ux-retry-failure']);
        $this->mock(NotificationDeliveryRetryService::class)->shouldReceive('retry')->once()->andThrow(new \RuntimeException('Internal transport details'));
        Livewire::actingAs($user)->test(ListNotificationDeliveries::class)
            ->callAction(TestAction::make('retry')->table($delivery))
            ->assertNotified(__('ux.retry_failed'))
            ->assertDontSee('Internal transport details');
    }

    public function test_ux_translation_keys_match_in_both_languages(): void
    {
        $ar = array_keys(Arr::dot(require lang_path('ar/ux.php')));
        $en = array_keys(Arr::dot(require lang_path('en/ux.php')));
        $this->assertSame($ar, $en);
    }
}
