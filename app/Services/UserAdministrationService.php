<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserAdministrationService
{
    public function create(User $actor, array $data): User
    {
        Gate::forUser($actor)->authorize('users.create');

        return DB::transaction(function () use ($actor, $data): User {
            $roles = $this->validatedRoles($actor, [$data['role'] ?? '']);
            $user = User::query()->create(Arr::only($data, ['name', 'email', 'password', 'is_active']));
            $user->syncRoles($roles);
            app(AdministrativeAudit::class)->record($user->fresh(), [], 'created', $actor);

            return $user;
        });
    }

    public function update(User $actor, User $user, array $data): User
    {
        Gate::forUser($actor)->authorize('users.update');

        return DB::transaction(function () use ($actor, $user, $data): User {
            // Serialize administrative changes, including concurrent demotions.
            $users = User::query()->orderBy('id')->lockForUpdate()->get();
            $target = $users->find($user->id);
            abort_unless($target, 404);
            if ($target->hasRole('super_admin') && ! $actor->hasRole('super_admin')) {
                throw new AuthorizationException(__('administration.errors.super_admin'));
            }
            $roles = array_key_exists('roles', $data) ? $data['roles'] : (array_key_exists('role', $data) ? [$data['role']] : $target->getRoleNames()->all());
            $roles = $this->validatedRoles($actor, $roles);
            $active = (bool) ($data['is_active'] ?? $target->is_active);
            if ($target->is_active && $target->hasRole('super_admin') && (! $active || ! in_array('super_admin', $roles, true)) && User::role('super_admin')->where('is_active', true)->count() <= 1) {
                throw new \DomainException(__('administration.errors.last_super_admin'));
            }
            $before = app(AdministrativeAudit::class)->snapshot($target);
            $attributes = Arr::only($data, ['name', 'email', 'password', 'is_active']);
            if (blank($attributes['password'] ?? null)) {
                unset($attributes['password']);
            }
            $target->update($attributes);
            if (array_key_exists('role', $data) || array_key_exists('roles', $data)) {
                $target->syncRoles($roles);
            }

            app(AdministrativeAudit::class)->record($target->fresh(), $before, 'updated', $actor);

            return $target;
        });
    }

    public function activate(User $actor, User $user): void
    {
        $this->update($actor, $user, ['is_active' => true]);
    }

    public function deactivate(User $actor, User $user): void
    {
        $this->update($actor, $user, ['is_active' => false]);
    }

    /** @param list<string> $roles */
    public function syncRoles(User $actor, User $user, array $roles): void
    {
        $this->update($actor, $user, ['roles' => $roles]);
    }

    private function validatedRoles(User $actor, array $roles): array
    {
        if (in_array('super_admin', $roles, true) && ! $actor->hasRole('super_admin')) {
            throw new AuthorizationException(__('administration.errors.super_admin'));
        }
        Validator::make(['roles' => $roles], ['roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string', Rule::in(Role::query()->where('guard_name', 'web')->pluck('name')->all())]])->validate();

        return $roles;
    }
}
