<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBusinessRequest;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Services\BusinessService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class BusinessController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BusinessService $businessService)
    {
    }

    public function show(): JsonResponse
    {
        $business = Business::current();

        return $this->sendSuccess([
            'business' => new BusinessResource($business),
            'setup_state' => $this->businessService->setupState($business),
        ]);
    }

    public function update(UpdateBusinessRequest $request): JsonResponse
    {
        $business = $this->businessService->update($request->validated());

        return $this->sendSuccess(new BusinessResource($business), 'Business updated');
    }

    public function regenerateWidgetKey(): JsonResponse
    {
        $business = Business::current()->regenerateWidgetKey();

        return $this->sendSuccess(new BusinessResource($business), 'Widget key regenerated');
    }
}
