<?php

namespace Tests\Feature;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use App\Models\MonitoredService;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditLogResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function login(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function entry(array $attributes = []): AuditLog
    {
        $entry = (new AuditLog)->forceFill(array_merge(['actor_id' => auth()->id(), 'event' => 'user.logged_in', 'auditable_type' => User::class, 'auditable_id' => auth()->id(), 'description' => 'Safe audit description', 'ip_address' => '192.0.2.1', 'user_agent' => 'AuditTestBrowser/1.0'], $attributes));
        $entry->save();

        return $entry;
    }

    public static function accessRoles(): array
    {
        return [['viewer', 200], ['administrator', 200], ['super_admin', 200], ['operator', 403]];
    }

    #[DataProvider('accessRoles')]
    public function test_index_and_detail_use_audit_view(string $role, int $status): void
    {
        $this->login($role);
        $entry = $this->entry();
        $this->get(AuditLogResource::getUrl())->assertStatus($status);
        $this->get(AuditLogResource::getUrl('view', ['record' => $entry]))->assertStatus($status);
    }

    public function test_even_super_admin_has_no_mutation_routes_or_actions(): void
    {
        $this->login('super_admin');
        $entry = $this->entry();
        $this->assertSame(['index', 'view'], array_keys(AuditLogResource::getPages()));
        $this->assertFalse(AuditLogResource::canCreate());
        $this->assertFalse(AuditLogResource::canEdit($entry));
        $this->assertFalse(AuditLogResource::canDelete($entry));
        $this->assertFalse(AuditLogResource::canDeleteAny());
        $this->get(AuditLogResource::getUrl().'/create')->assertNotFound();
        $this->get(AuditLogResource::getUrl().'/'.$entry->id.'/edit')->assertNotFound();
        Livewire::test(ListAuditLogs::class)->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist(TestAction::make('edit')->table($entry))
            ->assertActionDoesNotExist(TestAction::make('delete')->table($entry))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk())
            ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $entry->id])
            ->call('callMountedAction');
        Livewire::test(ViewAuditLog::class, ['record' => $entry->id])->assertActionDoesNotExist('edit')->assertActionDoesNotExist('delete');
        $this->assertModelExists($entry);
    }

    public function test_actor_event_resource_and_date_filters(): void
    {
        $actor = $this->login('viewer');
        $other = User::factory()->create();
        $first = $this->entry(['created_at' => '2026-09-01 00:00:00']);
        $second = $this->entry(['actor_id' => $other->id, 'event' => 'incident.acknowledged', 'auditable_type' => MonitoredService::class, 'created_at' => '2026-09-03 23:59:59']);
        $third = $this->entry(['created_at' => '2026-09-04 00:00:00']);
        $component = Livewire::test(ListAuditLogs::class)->assertCanSeeTableRecords([$third, $second, $first], inOrder: true);
        $component->filterTable('actor', $other->id)->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first, $third]);
        $component->resetTableFilters()->filterTable('event', 'user.logged_in')->assertCanSeeTableRecords([$first, $third])->assertCanNotSeeTableRecords([$second]);
        $component->resetTableFilters()->filterTable('auditable_type', MonitoredService::class)->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first, $third]);
        $component->resetTableFilters()->filterTable('created_at', ['from' => '2026-09-01', 'to' => '2026-09-03'])->assertCanSeeTableRecords([$first, $second])->assertCanNotSeeTableRecords([$third]);
    }

    public function test_safe_details_and_localized_events_in_both_languages(): void
    {
        $this->login('viewer');
        $logger = app(AuditLogger::class);
        $entry = $this->entry(['before' => $logger->sanitize(['name' => 'Before name', 'password' => '[redacted]']), 'after' => $logger->sanitize(['name' => 'After name']), 'context' => $logger->sanitize(['source' => 'Admin UI'])]);
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(ViewAuditLog::class, ['record' => $entry->id])
                ->assertSee(__('administration.audit.before'))->assertSee(__('administration.audit.after'))
                ->assertSee(AuditLogResource::eventLabel('user.logged_in'))
                ->assertSee(__('monitoring.audit.sensitive_changed'))->assertSee('Before name')->assertSee('After name')->assertSee('Admin UI')
                ->assertSee('192.0.2.1')->assertSee('AuditTestBrowser/1.0');
            Livewire::test(ListAuditLogs::class)->assertSee(AuditLogResource::eventLabel('user.logged_in'))->assertDontSee('AuditTestBrowser/1.0');
        }
        $this->assertSame('unknown.event', AuditLogResource::eventLabel('unknown.event'));
        $this->assertSame('-', AuditLogResource::safeJson(null));
        $empty = $this->entry();
        Livewire::test(ViewAuditLog::class, ['record' => $empty->id])->assertSuccessful();
    }
}
