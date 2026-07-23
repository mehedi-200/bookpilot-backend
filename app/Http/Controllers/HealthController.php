<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    use ApiResponse;

    public function ping(): JsonResponse
    {
        return $this->sendSuccess(['pong' => true], 'BookPilot API is up');
    }
}
