<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Services\ServiceCatalogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ServiceCatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $services = $this->catalog->paginate($request->only(['q', 'active', 'per_page']));

        return $this->sendSuccess(
            ServiceResource::collection($services)->response()->getData(true)
        );
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $service = $this->catalog->create($request->validated());

        return $this->sendSuccess(new ServiceResource($service), 'Service created', 201);
    }

    public function update(UpdateServiceRequest $request, Service $service): JsonResponse
    {
        $service = $this->catalog->update($service, $request->validated());

        return $this->sendSuccess(new ServiceResource($service), 'Service updated');
    }

    public function destroy(Service $service): JsonResponse
    {
        $this->catalog->delete($service);

        return $this->sendSuccess(null, 'Service deleted');
    }

    public function toggleActive(Service $service): JsonResponse
    {
        $service = $this->catalog->toggleActive($service);

        return $this->sendSuccess(
            new ServiceResource($service),
            $service->active ? 'Service activated' : 'Service deactivated'
        );
    }
}
