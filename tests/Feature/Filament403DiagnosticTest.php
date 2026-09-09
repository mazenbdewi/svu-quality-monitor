<?php

namespace Tests\Feature;

use App\Filament\Pages\NotificationSettingsPage;
use App\Filament\Resources\MonitoredServices\MonitoredServiceResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Filament403DiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public static function requests(): array
    {
        return [
            'Viewer Dashboard' => ['viewer'],
            'Operator Services' => ['operator'],
            'Super Admin Users' => ['super_admin'],
        ];
    }

    #[DataProvider('requests')]
    public function test_real_request(string $role): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole($role);
        $this->withoutExceptionHandling();
        $this->actingAs($viewer);
        $url = match ($role) {
            'viewer' => '/admin',
            'operator' => MonitoredServiceResource::getUrl(),
            'super_admin' => UserResource::getUrl(),
        };
        try {
            $this->get($url)->assertOk();
        } catch (\Throwable $exception) {
            fwrite(STDERR, "\nDIAGNOSTIC {$role} {$url}\n".$exception::class."\n".$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
            throw $exception;
        }
    }

    public function test_restricted_pages_remain_forbidden(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole('viewer');
        $operator = User::factory()->create(['is_active' => true]);
        $operator->assignRole('operator');

        $this->actingAs($viewer)->get(UserResource::getUrl())->assertForbidden();
        $this->actingAs($operator)->get(NotificationSettingsPage::getUrl())->assertForbidden();
    }
}
