<?php

namespace Tests\Feature;

use App\Filament\Pages\NotificationSettingsPage;
use App\Jobs\DeliverNotificationJob;
use App\Models\MonitoredService;
use App\Models\NotificationDelivery;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Monitoring\CheckResult;
use App\Services\NotificationDeliveryRetryService;
use App\Services\NotificationDispatcher;
use App\Services\ServiceCheckRunner;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_notification_settings_page_renders_for_an_authenticated_user(): void
    {
        config(['app.env' => 'local']);

        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('administrator');
        $this->actingAs($user)
            ->get(NotificationSettingsPage::getUrl())
            ->assertOk();
    }

    public function test_invalid_manual_notification_test_shows_an_error_notification_without_a_server_error(): void
    {
        config(['app.env' => 'local']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('administrator');
        Livewire::actingAs($user)
            ->test(NotificationSettingsPage::class)
            ->callAction(TestAction::make('testEmail')->schemaComponent(true, 'form'))
            ->assertNotified();
    }

    public function test_confirmed_and_resolved_incidents_create_one_delivery_per_enabled_channel(): void
    {
        Queue::fake();
        NotificationSetting::current()->update(['telegram_enabled' => true, 'telegram_bot_token' => 'secret-token', 'telegram_chat_id' => '1', 'email_enabled' => true, 'email_recipients' => ['ops@example.test']]);
        $service = MonitoredService::factory()->create();
        $this->check($service, false);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->check($service, false);
        $this->assertDatabaseCount('notification_deliveries', 2);
        $this->check($service, false);
        $this->assertDatabaseCount('notification_deliveries', 2);
        $this->check($service, true);
        $this->assertDatabaseCount('notification_deliveries', 2);
        $this->check($service, true);
        $this->assertDatabaseCount('notification_deliveries', 4);
    }

    public function test_disabled_service_never_creates_deliveries_and_token_is_encrypted(): void
    {
        Queue::fake();
        $setting = NotificationSetting::current();
        $setting->update(['telegram_enabled' => true, 'telegram_bot_token' => 'top-secret', 'telegram_chat_id' => '1']);
        $this->assertNotSame('top-secret', $setting->fresh()->getRawOriginal('telegram_bot_token'));
        $service = MonitoredService::factory()->create(['notifications_enabled' => false]);
        $this->check($service, false);
        $this->check($service, false);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_manual_channel_tests_are_queued_without_incidents_or_secret_leakage(): void
    {
        Queue::fake();
        NotificationSetting::current()->update(['telegram_enabled' => true, 'telegram_bot_token' => 'hidden-token', 'telegram_chat_id' => '1', 'email_enabled' => true, 'email_recipients' => ['ops@example.test']]);
        $telegram = app(NotificationDispatcher::class)->test('telegram');
        $email = app(NotificationDispatcher::class)->test('email');

        $this->assertSame('telegram_test', $telegram->event_type);
        $this->assertSame('email_test', $email->event_type);
        $this->assertNotSame('hidden-token', json_encode($telegram->toArray()));
        $this->assertDatabaseCount('service_incidents', 0);
        $this->assertNotSame($telegram->deduplication_key, app(NotificationDispatcher::class)->test('telegram')->deduplication_key);
    }

    public function test_failed_delivery_retries_the_same_record_and_sent_record_is_not_resent(): void
    {
        Queue::fake();
        $delivery = NotificationDelivery::query()->create(['event_type' => 'telegram_test', 'channel' => 'telegram', 'status' => 'failed', 'attempt_number' => 2, 'deduplication_key' => 'test:telegram:retry']);
        $this->assertTrue(app(NotificationDeliveryRetryService::class)->retry($delivery));
        $this->assertSame('pending', $delivery->fresh()->status);

        NotificationSetting::current()->update(['telegram_enabled' => true, 'telegram_bot_token' => 'token', 'telegram_chat_id' => '1']);
        Http::fake();
        (new DeliverNotificationJob($delivery->id))->handle();
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertGreaterThan(2, $delivery->fresh()->attempt_number);
        $this->assertFalse(app(NotificationDeliveryRetryService::class)->retry($delivery->fresh()));
    }

    private function check(MonitoredService $service, bool $success): void
    {
        app(ServiceCheckRunner::class)->persist($service, new CheckResult($success, now(), statusCode: $success ? 200 : 500, responseTimeMs: 10, errorType: $success ? null : 'timeout'));
    }
}
