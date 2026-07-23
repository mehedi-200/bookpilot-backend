<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Services\AvailabilityService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AvailabilityService $availability) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'exists:services,id'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $service = Service::withTrashed()->findOrFail($validated['service_id']);

        return $this->sendSuccess($this->availability->slots($service, $validated['date']));
    }
}
