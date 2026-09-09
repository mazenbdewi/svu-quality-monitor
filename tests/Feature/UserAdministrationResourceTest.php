<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\UserAdministrationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserAdministrationResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public static function accessRoles(): array
    {
        return [['administrator', 200], ['super_admin', 200], ['operator', 403], ['viewer', 403]];
    }

    #[DataProvider('accessRoles')]
    public function test_user_index_access(string $role, int $status): void
    {
        $this->actingAs($this->user($role))->get(UserResource::getUrl())->assertStatus($status);
    }

    public function test_create_hashes_password_and_assigns_selected_role(): void
    {
        $this->actingAs($this->user('administrator'));
        Livewire::test(CreateUser::class)->fillForm(['name' => 'New User', 'email' => 'new@example.test', 'password' => 'New-password-123!', 'role' => 'operator', 'is_active' => true])->call('create')->assertHasNoFormErrors();
        $created = User::where('email', 'new@example.test')->sole();
        $this->assertTrue(Hash::check('New-password-123!', $created->password));
        $this->assertTrue($created->hasRole('operator'));
        $this->assertStringNotContainsString('New-password-123!', $created->toJson());
    }

    public function test_create_requires_password_and_rejects_forged_super_admin_role(): void
    {
        $this->actingAs($this->user('administrator'));
        Livewire::test(CreateUser::class)->fillForm(['name' => 'New User', 'email' => 'new@example.test', 'password' => '', 'role' => 'viewer', 'is_active' => true])->call('create')->assertHasFormErrors(['password' => 'required']);
        Livewire::test(CreateUser::class)->fillForm(['name' => 'New User', 'email' => 'new@example.test', 'password' => 'New-password-123!', 'role' => 'super_admin', 'is_active' => true])->call('create')->assertHasFormErrors(['role']);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_service_rejects_super_admin_creation_server_side(): void
    {
        $actor = $this->user('administrator');
        $this->expectException(AuthorizationException::class);
        app(UserAdministrationService::class)->create($actor, ['name' => 'Denied', 'email' => 'denied@example.test', 'password' => 'Password-123!', 'role' => 'super_admin', 'is_active' => true]);
    }

    public function test_service_rejects_administrator_granting_super_admin_to_existing_user(): void
    {
        $actor = $this->user('administrator');
        $target = $this->user('operator');
        try {
            app(UserAdministrationService::class)->syncRoles($actor, $target, ['super_admin']);
            $this->fail('Granting Super Admin should be denied.');
        } catch (AuthorizationException) {
            $this->assertTrue($target->fresh()->hasRole('operator'));
            $this->assertFalse($target->fresh()->hasRole('super_admin'));
        }
    }

    public function test_administrator_cannot_deactivate_super_admin_through_service(): void
    {
        $actor = $this->user('administrator');
        $target = $this->user('super_admin');
        try {
            app(UserAdministrationService::class)->deactivate($actor, $target);
            $this->fail('Deactivating Super Admin should be denied.');
        } catch (AuthorizationException) {
            $this->assertTrue($target->fresh()->is_active);
        }
    }

    public function test_edit_password_is_empty_and_preserves_hash_until_replaced(): void
    {
        $this->actingAs($this->user('administrator'));
        $target = $this->user('viewer');
        $hash = $target->password;
        Livewire::test(EditUser::class, ['record' => $target->id])->assertFormSet(['password' => ''])->assertDontSee($hash)->fillForm(['name' => 'Renamed', 'password' => '', 'role' => 'operator'])->call('save')->assertHasNoFormErrors()->assertFormSet(['password' => '']);
        $this->assertSame($hash, $target->fresh()->password);
        $this->assertTrue($target->fresh()->hasRole('operator'));
        Livewire::test(EditUser::class, ['record' => $target->id])->fillForm(['password' => 'Replacement-123!', 'role' => 'administrator'])->call('save')->assertHasNoFormErrors()->assertFormSet(['password' => '']);
        $this->assertTrue(Hash::check('Replacement-123!', $target->fresh()->password));
        $this->assertTrue($target->fresh()->hasRole('administrator'));
    }

    public function test_activate_and_deactivate_actions_control_panel_access(): void
    {
        $actor = $this->user('administrator');
        $target = $this->user('viewer');
        $this->actingAs($actor);
        Livewire::test(ListUsers::class)->callAction(TestAction::make('deactivate')->table($target))->assertHasNoErrors();
        $this->assertFalse($target->fresh()->is_active);
        $this->actingAs($target->fresh())->get('/admin')->assertForbidden();
        $this->actingAs($actor);
        Livewire::test(ListUsers::class)->callAction(TestAction::make('activate')->table($target))->assertHasNoErrors();
        $this->assertTrue($target->fresh()->is_active);
        $this->actingAs($target->fresh())->get('/admin')->assertOk();
    }

    public function test_administrator_cannot_open_super_admin_edit_or_mutate_it_via_service(): void
    {
        $actor = $this->user('administrator');
        $target = $this->user('super_admin');
        $this->actingAs($actor)->get(UserResource::getUrl('edit', ['record' => $target]))->assertForbidden();
        Livewire::test(ListUsers::class)->assertActionHidden(TestAction::make('deactivate')->table($target));
        $this->expectException(AuthorizationException::class);
        app(UserAdministrationService::class)->update($actor, $target, ['name' => 'Denied']);
    }

    public static function protectedChanges(): array
    {
        return [['is_active', false], ['role', 'viewer']];
    }

    #[DataProvider('protectedChanges')]
    public function test_last_super_admin_remains_active_and_keeps_role(string $field, mixed $value): void
    {
        $target = $this->user('super_admin');
        $this->actingAs($target);
        Livewire::test(EditUser::class, ['record' => $target->id])->fillForm([$field => $value])->call('save')->assertHasFormErrors(['role']);
        $this->assertTrue($target->fresh()->is_active);
        $this->assertTrue($target->fresh()->hasRole('super_admin'));
    }

    public function test_last_super_admin_deactivate_action_is_safe(): void
    {
        $target = $this->user('super_admin');
        $this->actingAs($target);
        Livewire::test(ListUsers::class)->callAction(TestAction::make('deactivate')->table($target))->assertNotified();
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_two_super_admins_allow_safe_demotion_and_inactive_admin_can_be_reactivated(): void
    {
        $actor = $this->user('super_admin');
        $target = $this->user('super_admin');
        $service = app(UserAdministrationService::class);
        $service->deactivate($actor, $target);
        $this->assertFalse($target->fresh()->is_active);
        $service->activate($actor, $target);
        $this->assertTrue($target->fresh()->is_active);
        $service->syncRoles($actor, $target, ['viewer']);
        $this->assertTrue($target->fresh()->hasRole('viewer'));
        $this->assertTrue($actor->fresh()->hasRole('super_admin'));
    }

    public function test_super_admin_can_create_another_super_admin(): void
    {
        $this->actingAs($this->user('super_admin'));
        Livewire::test(CreateUser::class)->fillForm(['name' => 'New Admin', 'email' => 'root@example.test', 'password' => 'New-password-123!', 'role' => 'super_admin', 'is_active' => true])->call('create')->assertHasNoFormErrors();
        $this->assertTrue(User::where('email', 'root@example.test')->sole()->hasRole('super_admin'));
    }

    public function test_user_list_filters_and_no_delete_actions(): void
    {
        $actor = $this->user('super_admin');
        $active = $this->user('viewer');
        $inactive = User::factory()->inactive()->create();
        $inactive->assignRole('operator');
        $this->actingAs($actor);
        Livewire::test(ListUsers::class)->assertSee(__('administration.never'))
            ->assertActionDoesNotExist(TestAction::make('delete')->table($active))
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk())
            ->filterTable('is_active', true)->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$inactive])
            ->resetTableFilters()->filterTable('roles', $inactive->roles->first()->id)->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active]);
        $this->assertFalse(UserResource::canDelete($active));
        $this->assertFalse(UserResource::canDeleteAny());
    }
}
