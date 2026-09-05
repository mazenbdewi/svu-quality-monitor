<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class UserAdministrationService
{
    public function deactivate(User $actor, User $user): void
    {
        $this->guardSuperAdmin($actor, $user, false);
        $user->update(['is_active' => false]);
        app(AuditLogger::class)->log('user.deactivated', $user, 'User deactivated');
    }

    /** @param list<string> $roles */
    public function syncRoles(User $actor, User $user, array $roles): void
    {
        $isSuperAdmin = in_array('super_admin', $roles, true);
        $this->guardSuperAdmin($actor, $user, $isSuperAdmin);
        $user->syncRoles($roles);
        app(AuditLogger::class)->log('user.roles_updated', $user, 'User roles updated', [], ['roles' => $roles]);
    }

    private function guardSuperAdmin(User $actor, User $target, bool $keepsSuperAdmin): void
    {
        if ($target->hasRole('super_admin') && ! $actor->hasRole('super_admin')) {
            throw new AuthorizationException('Only a Super Admin may modify a Super Admin.');
        }
        if ($target->hasRole('super_admin') && (! $keepsSuperAdmin || ! $target->is_active) && $this->activeSuperAdmins() <= 1) {
            throw new \DomainException('The last active Super Admin cannot be changed or deactivated.');
        }
        if ($isNewSuperAdmin = $keepsSuperAdmin && ! $target->hasRole('super_admin')) {
            if (! $actor->hasRole('super_admin')) {
                throw new AuthorizationException('Only a Super Admin may grant Super Admin.');
            }
        }
    }

    private function activeSuperAdmins(): int
    {
        return User::role('super_admin')->where('is_active', true)->count();
    }
}
