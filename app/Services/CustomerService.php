<?php

namespace App\Services;

use App\Exceptions\DuplicatePhoneException;
use App\Models\Customer;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CustomerService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Customer::query()
            ->when($filters['q'] ?? null, function ($query, $q) {
                $normalized = PhoneNumber::normalize($q);
                $query->where(fn ($w) => $w
                    ->where('name', 'like', "%{$q}%")
                    ->when($normalized, fn ($p) => $p->orWhere('phone', 'like', "%{$normalized}%")));
            })
            ->latest()
            ->paginate($filters['per_page'] ?? 10);
    }

    public function create(array $data): Customer
    {
        $data['phone'] = PhoneNumber::normalize($data['phone']);

        $existing = Customer::withTrashed()->where('phone', $data['phone'])->first();

        if ($existing?->trashed()) {
            // A deleted customer coming back: restore, refresh their info.
            $existing->restore();
            $existing->update($data);

            return $existing;
        }

        if ($existing) {
            throw new DuplicatePhoneException($existing);
        }

        return Customer::create($data);
    }

    public function update(Customer $customer, array $data): Customer
    {
        $data['phone'] = PhoneNumber::normalize($data['phone']);

        $existing = Customer::where('phone', $data['phone'])
            ->whereKeyNot($customer->id)
            ->first();

        if ($existing) {
            throw new DuplicatePhoneException($existing);
        }

        $customer->update($data);

        return $customer;
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }

    public function findByPhone(?string $phone): ?Customer
    {
        $normalized = PhoneNumber::normalize($phone);

        return $normalized ? Customer::where('phone', $normalized)->first() : null;
    }

    /**
     * The shared identity contract — manual bookings and the AI agent's
     * create_booking tool both resolve customers through this method.
     */
    public function findOrCreateByPhone(string $name, string $phone, ?string $email = null): Customer
    {
        $normalized = PhoneNumber::normalize($phone);

        $customer = Customer::withTrashed()->where('phone', $normalized)->first();

        if ($customer) {
            if ($customer->trashed()) {
                $customer->restore();
            }

            return $customer;
        }

        return Customer::create([
            'name' => $name,
            'phone' => $normalized,
            'email' => $email,
        ]);
    }
}
