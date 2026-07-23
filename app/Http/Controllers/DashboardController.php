<?php

namespace App\Http\Controllers;

use App\Http\Resources\BookingResource;
use App\Services\DashboardService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function index(): JsonResponse
    {
        $summary = $this->dashboard->summary();

        return $this->sendSuccess([
            ...$summary,
            'today' => BookingResource::collection($summary['today']),
            'upcoming' => BookingResource::collection($summary['upcoming']),
        ]);
    }
}
