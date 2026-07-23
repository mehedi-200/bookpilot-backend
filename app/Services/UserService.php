<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        return User::query()
            ->when($filters['q'] ?? null, function ($query, $q) {
                $query->where(fn ($w) => $w
                    ->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%"));
            })
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 10);
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, User $actor, array $data): User
    {
        if ($user->is($actor) && ($data['role'] ?? $user->role) !== User::ROLE_ADMIN) {
            throw ValidationException::withMessages([
                'role' => 'You cannot remove your own admin role.',
            ]);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        return $user;
    }

    public function toggleActive(User $user, User $actor): User
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages([
                'active' => 'You cannot deactivate your own account.',
            ]);
        }

        $user->update(['active' => ! $user->active]);

        // Deactivation locks them out immediately, not at next login.
        if (! $user->active) {
            $user->tokens()->delete();
        }

        return $user;
    }

    public function updateProfile(User $user, array $data): User
    {
        if (empty($data['password'])) {
            unset($data['password']);
        }
        unset($data['current_password'], $data['password_confirmation']);

        $user->update($data);

        return $user;
    }
}
