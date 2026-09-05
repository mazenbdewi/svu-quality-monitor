<?php

namespace Tests\Feature;

use App\Models\MonitoredService;
use App\Models\ServiceIncident;
use App\Models\User;
use App\Services\IncidentAcknowledgementService;
use App\Services\UserAdministrationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_super_admin_cannot_be_deactivated_or_demoted(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('super_admin');
        $service = app(UserAdministrationService::class);
        $this->expectException(\DomainException::class);
        $service->deactivate($admin, $admin);
    }

    public function test_operator_acknowledges_once_without_resolving_incident(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $operator = User::factory()->create();
        $operator->assignRole('operator');
        $incident = ServiceIncident::query()->create(['monitored_service_id' => MonitoredService::factory()->create()->id, 'started_at' => now(), 'confirmed_at' => now(), 'status' => 'open', 'incident_type' => 'down', 'severity' => 'high']);
        $acknowledged = app(IncidentAcknowledgementService::class)->acknowledge($operator, $incident);
        $again = app(IncidentAcknowledgementService::class)->acknowledge($operator, $acknowledged);
        $this->assertSame('open', $again->status);
        $this->assertSame($operator->id, $again->acknowledged_by);
        $this->assertSame($acknowledged->acknowledged_at?->toIso8601String(), $again->acknowledged_at?->toIso8601String());
    }
}
