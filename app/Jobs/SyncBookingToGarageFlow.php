<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\GarageFlow\GarageFlowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncBookingToGarageFlow implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $bookingId) {}

    /** 1 min, then 5, then 15 — a brief GarageFlow outage sorts itself out. */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(GarageFlowService $garageflow): void
    {
        $booking = Booking::find($this->bookingId);

        if (! $booking || $booking->sync_status === 'synced') {
            return;
        }

        $garageflow->sync($booking);
    }

    public function failed(\Throwable $e): void
    {
        $booking = Booking::find($this->bookingId);

        if ($booking) {
            app(GarageFlowService::class)->markFailed($booking, $e);
        }
    }
}
