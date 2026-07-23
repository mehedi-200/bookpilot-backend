<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\ClosedDate;
use App\Models\Service;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The single source of truth about free time. The dashboard slot picker, the
 * widget, and the agent's check_availability tool all read from here, so they
 * can never disagree — and the agent can never invent a slot.
 *
 * All wall-clock reasoning happens in the business timezone; everything stored
 * and compared in the database is UTC.
 */
class AvailabilityService
{
    /**
     * Free slots for a service on a date (Y-m-d in the business timezone),
     * grouped morning / afternoon / evening.
     */
    public function slots(Service $service, string $date): array
    {
        $day = $this->businessDay($date);

        if ($this->isClosedDate($day)) {
            return $this->result($service, $date, [], 'Closed on this date');
        }

        $hours = $this->hoursFor($day);

        if (! $hours) {
            return $this->result($service, $date, [], 'Closed on this day');
        }

        $open = $this->timeOn($day, $hours->open_time);
        $close = $this->timeOn($day, $hours->close_time);
        $duration = $service->duration_minutes;

        $taken = $this->blockingBookings($open, $close);
        $earliest = $this->earliestBookableMoment();

        $groups = ['morning' => [], 'afternoon' => [], 'evening' => []];

        for (
            $cursor = $open;
            $cursor->addMinutes($duration)->lessThanOrEqualTo($close);
            $cursor = $cursor->addMinutes($duration)
        ) {
            $end = $cursor->addMinutes($duration);

            if ($cursor->lessThan($earliest)) {
                continue; // past, or inside the lead-time buffer
            }

            if ($this->overlapsAny($taken, $cursor, $end)) {
                continue;
            }

            $groups[$this->groupFor($cursor)][] = [
                'start' => $cursor->utc()->toISOString(),
                'label' => $cursor->format('g:i A'),
            ];
        }

        return $this->result($service, $date, $groups);
    }

    /**
     * Is this exact start time bookable? Used when creating/rescheduling, so a
     * hand-crafted request can't land outside opening hours.
     *
     * @return string|null null when bookable, else the human reason it isn't
     */
    public function rejectionReason(Service $service, CarbonInterface $startsAt, string $source): ?string
    {
        $start = CarbonImmutable::instance($startsAt)->setTimezone($this->timezone());
        $end = $start->addMinutes($service->duration_minutes);

        if ($this->isClosedDate($start)) {
            return 'The business is closed on that date.';
        }

        $hours = $this->hoursFor($start);

        if (! $hours) {
            return 'The business is closed on that day.';
        }

        $open = $this->timeOn($start, $hours->open_time);
        $close = $this->timeOn($start, $hours->close_time);

        if ($start->lessThan($open) || $end->greaterThan($close)) {
            return 'That time is outside your working hours.';
        }

        // Widget/AI bookings must respect the notice period; staff booking by
        // hand only need a time that hasn't already passed.
        $floor = $source === Booking::SOURCE_WIDGET
            ? $this->earliestBookableMoment()
            : CarbonImmutable::now();

        if ($start->lessThan($floor)) {
            return $source === Booking::SOURCE_WIDGET
                ? 'That time is too soon — please pick a later slot.'
                : 'That time is in the past.';
        }

        return null;
    }

    /** Bookings that occupy time in the given window (pending + confirmed). */
    public function blockingBookings(CarbonInterface $from, CarbonInterface $to, ?int $ignoreBookingId = null)
    {
        return Booking::query()
            ->whereIn('status', Booking::BLOCKING_STATUSES)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->get(['id', 'starts_at', 'ends_at']);
    }

    public function timezone(): string
    {
        return Business::current()->timezone;
    }

    public function earliestBookableMoment(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes((int) config('bookpilot.lead_time_minutes'));
    }

    private function businessDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, $this->timezone())->startOfDay();
    }

    private function isClosedDate(CarbonInterface $moment): bool
    {
        return ClosedDate::whereDate('date', $moment->toDateString())->exists();
    }

    /** Working hours for that weekday, or null when the day is closed. */
    private function hoursFor(CarbonInterface $moment): ?WorkingHour
    {
        $hours = WorkingHour::where('day_of_week', (int) $moment->dayOfWeek)->first();

        if (! $hours || $hours->is_closed || ! $hours->open_time || ! $hours->close_time) {
            return null;
        }

        return $hours;
    }

    private function timeOn(CarbonInterface $day, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return CarbonImmutable::instance($day)
            ->setTimezone($this->timezone())
            ->setTime((int) $hour, (int) $minute);
    }

    private function overlapsAny($bookings, CarbonInterface $start, CarbonInterface $end): bool
    {
        foreach ($bookings as $booking) {
            if ($booking->starts_at->lessThan($end) && $booking->ends_at->greaterThan($start)) {
                return true;
            }
        }

        return false;
    }

    private function groupFor(CarbonInterface $moment): string
    {
        return match (true) {
            $moment->hour < 12 => 'morning',
            $moment->hour < 17 => 'afternoon',
            default => 'evening',
        };
    }

    private function result(Service $service, string $date, array $groups, ?string $reason = null): array
    {
        $groups = $groups ?: ['morning' => [], 'afternoon' => [], 'evening' => []];

        return [
            'date' => $date,
            'timezone' => $this->timezone(),
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'duration_minutes' => $service->duration_minutes,
            ],
            'slots' => $groups,
            'total' => array_sum(array_map('count', $groups)),
            'closed_reason' => $reason,
        ];
    }
}
