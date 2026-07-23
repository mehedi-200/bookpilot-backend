<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ServiceCatalogService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Service::query()
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where('name', 'like', "%{$q}%"))
            ->when(
                array_key_exists('active', $filters) && $filters['active'] !== null && $filters['active'] !== '',
                fn ($query) => $query->where('active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN))
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 10);
    }

    public function create(array $data): Service
    {
        return Service::create($data);
    }

    public function update(Service $service, array $data): Service
    {
        $service->update($data);

        return $service;
    }

    public function delete(Service $service): void
    {
        // Soft delete — historical bookings keep the service name.
        $service->delete();
    }

    public function toggleActive(Service $service): Service
    {
        $service->update(['active' => ! $service->active]);

        return $service;
    }
}
