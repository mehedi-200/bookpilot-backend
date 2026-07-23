<?php

namespace App\Services;

use App\Jobs\SyncBookingToGarageFlow;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\Service;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly CustomerService $customers,
        private readonly NotificationService $notifications,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        return Booking::query()
            ->with(['customer', 'service'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['service_id'] ?? null, fn ($q, $id) => $q->where('service_id', $id))
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('starts_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('starts_at', '<=', $to.' 23:59:59'))
            ->when($filters['q'] ?? null, function ($query, $q) {
                $phone = PhoneNumber::normalize($q);
                $query->where(function ($w) use ($q, $phone) {
                    $w->where('reference', 'like', "%{$q}%")
                        ->orWhereHas('customer', function ($c) use ($q, $phone) {
                            $c->where('name', 'like', "%{$q}%")
                                ->when($phone, fn ($p) => $p->orWhere('phone', 'like', "%{$phone}%"));
                        });
                });
            })
            ->orderByDesc('starts_at')
            ->paginate($filters['per_page'] ?? 10);
    }

    /**
     * Create a booking. The slot is re-checked under a row lock inside the
     * transaction, so two people racing for the same time cannot both win.
     */
    public function create(array $data): Booking
    {
        $service = Service::findOrFail($data['service_id']);
        $source = $data['source'] ?? Booking::SOURCE_MANUAL;
        $start = CarbonImmutable::parse($data['starts_at']);

        $this->assertBookable($service, $start, $source);

        $booking = DB::transaction(function () use ($data, $service, $start, $source) {
            $customer = $this->resolveCustomer($data);
            $end = $start->addMinutes($service->duration_minutes);

            $this->assertSlotFree($start, $end);

            return Booking::create([
                'reference' => $this->nextReference(),
                'customer_id' => $customer->id,
                'service_id' => $service->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'status' => $data['status'] ?? Booking::STATUS_PENDING,
                'source' => $source,
                'conversation_id' => $data['conversation_id'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        });

        // Only the AI booking on its own is news; staff know what they typed in.
        if ($source === Booking::SOURCE_WIDGET) {
            $this->notifications->aiBooking($booking);
        }

        return $booking;
    }

    /**
     * The one place a booking changes status. Illegal steps are impossible.
     */
    public function transition(Booking $booking, string $status, ?string $reason = null): Booking
    {
        if (! $booking->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'status' => "A {$booking->status} booking cannot be marked as {$status}.",
            ]);
        }

        $booking->status = $status;

        if ($status === Booking::STATUS_CANCELLED) {
            $booking->cancelled_at = now();
            $booking->cancel_reason = $reason;
        }

        $booking->save();

        // Confirming is the trigger for GarageFlow. Queued on purpose: the
        // customer's confirmation must never wait on someone else's API.
        if ($status === Booking::STATUS_CONFIRMED && Integration::garageflow()->isReady()) {
            $booking->forceFill(['sync_status' => 'pending'])->save();
            SyncBookingToGarageFlow::dispatch($booking->id);
        }

        if ($status === Booking::STATUS_CANCELLED) {
            $this->notifications->bookingCancelled($booking);
        }

        return $booking->fresh(['customer', 'service']);
    }

    public function reschedule(Booking $booking, string $startsAt): Booking
    {
        if (! in_array($booking->status, Booking::BLOCKING_STATUSES, true)) {
            throw ValidationException::withMessages([
                'starts_at' => "A {$booking->status} booking cannot be rescheduled.",
            ]);
        }

        $service = $booking->service;
        $start = CarbonImmutable::parse($startsAt);

        $this->assertBookable($service, $start, $booking->source);

        return DB::transaction(function () use ($booking, $service, $start) {
            $end = $start->addMinutes($service->duration_minutes);

            $this->assertSlotFree($start, $end, $booking->id);

            $booking->update([
                'rescheduled_from' => $booking->starts_at,
                'starts_at' => $start,
                'ends_at' => $end,
            ]);

            return $booking->fresh(['customer', 'service']);
        });
    }

    private function assertBookable(Service $service, CarbonInterface $start, string $source): void
    {
        $reason = $this->availability->rejectionReason($service, $start, $source);

        if ($reason !== null) {
            throw ValidationException::withMessages(['starts_at' => $reason]);
        }
    }

    private function assertSlotFree(CarbonInterface $start, CarbonInterface $end, ?int $ignoreId = null): void
    {
        $taken = Booking::query()
            ->whereIn('status', Booking::BLOCKING_STATUSES)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->lockForUpdate()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'starts_at' => 'That slot has just been taken — please pick another time.',
            ]);
        }
    }

    private function resolveCustomer(array $data): Customer
    {
        if (! empty($data['customer_id'])) {
            return Customer::findOrFail($data['customer_id']);
        }

        // Same identity contract the AI agent uses — one customer per phone.
        return $this->customers->findOrCreateByPhone(
            $data['customer_name'],
            $data['customer_phone'],
            $data['customer_email'] ?? null,
        );
    }

    /** BP-YYYY-NNNN, sequential within the year. */
    private function nextReference(): string
    {
        $prefix = 'BP-'.now()->year.'-';

        $last = Booking::where('reference', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
