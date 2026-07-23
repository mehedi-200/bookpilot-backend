<?php

namespace App\Http\Controllers;

use App\Http\Requests\RescheduleBookingRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingStatusRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BookingService $bookings)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $bookings = $this->bookings->paginate(
            $request->only(['status', 'service_id', 'source', 'from', 'to', 'q', 'per_page'])
        );

        return $this->sendSuccess(
            BookingResource::collection($bookings)->response()->getData(true)
        );
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $booking = $this->bookings->create($request->validated());

        return $this->sendSuccess(
            new BookingResource($booking->load(['customer', 'service'])),
            'Booking created',
            201
        );
    }

    public function show(Booking $booking): JsonResponse
    {
        return $this->sendSuccess(new BookingResource($booking->load(['customer', 'service'])));
    }

    public function updateStatus(UpdateBookingStatusRequest $request, Booking $booking): JsonResponse
    {
        $booking = $this->bookings->transition(
            $booking,
            $request->validated('status'),
            $request->validated('cancel_reason'),
        );

        return $this->sendSuccess(new BookingResource($booking), "Booking {$booking->status}");
    }

    public function reschedule(RescheduleBookingRequest $request, Booking $booking): JsonResponse
    {
        $booking = $this->bookings->reschedule($booking, $request->validated('starts_at'));

        return $this->sendSuccess(new BookingResource($booking), 'Booking rescheduled');
    }
}
