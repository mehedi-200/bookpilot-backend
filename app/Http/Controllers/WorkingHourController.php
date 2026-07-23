<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClosedDateRequest;
use App\Http\Requests\UpdateWorkingHoursRequest;
use App\Http\Resources\ClosedDateResource;
use App\Http\Resources\WorkingHourResource;
use App\Models\ClosedDate;
use App\Services\WorkingHourService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class WorkingHourController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WorkingHourService $workingHourService) {}

    public function index(): JsonResponse
    {
        return $this->sendSuccess([
            'days' => WorkingHourResource::collection($this->workingHourService->all()),
            'closed_dates' => ClosedDateResource::collection(
                ClosedDate::where('date', '>=', now()->toDateString())->orderBy('date')->get()
            ),
        ]);
    }

    public function update(UpdateWorkingHoursRequest $request): JsonResponse
    {
        $days = $this->workingHourService->updateAll($request->validated('days'));

        return $this->sendSuccess(
            ['days' => WorkingHourResource::collection($days)],
            'Working hours updated'
        );
    }

    public function storeClosedDate(StoreClosedDateRequest $request): JsonResponse
    {
        $closedDate = $this->workingHourService->addClosedDate($request->validated());

        return $this->sendSuccess(new ClosedDateResource($closedDate), 'Closed date added', 201);
    }

    public function destroyClosedDate(ClosedDate $closedDate): JsonResponse
    {
        $this->workingHourService->removeClosedDate($closedDate);

        return $this->sendSuccess(null, 'Closed date removed');
    }
}
