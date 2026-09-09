<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AuditLoginVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_successful_filament_login_updates_timestamp_and_audits_only_the_authenticated_user(): void
    {
        $this->freezeTime();
        $user = User::factory()->create(['password' => 'synthetic-valid-login-password']);
        $user->assignRole('viewer');
        $this->assertNull($user->last_login_at);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'synthetic-valid-login-password'])
            ->call('authenticate')->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(now()->toDateTimeString(), $user->fresh()->last_login_at->toDateTimeString());
        $log = AuditLog::sole();
        $this->assertSame('user.logged_in', $log->event);
        $this->assertSame($user->id, $log->actor_id);
        $this->assertSame($user->id, $log->auditable_id);
        $serialized = $log->toJson(JSON_UNESCAPED_SLASHES).json_encode(DB::table('audit_logs')->get(), JSON_UNESCAPED_SLASHES);
        foreach (['synthetic-valid-login-password', $user->password, $user->remember_token] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_failed_filament_login_does_not_update_timestamp_or_create_success_audit(): void
    {
        $user = User::factory()->create(['password' => 'synthetic-valid-login-password']);
        $user->assignRole('viewer');

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'synthetic-wrong-password'])
            ->call('authenticate')->assertHasFormErrors(['email']);

        $this->assertGuest();
        $this->assertNull($user->fresh()->last_login_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
