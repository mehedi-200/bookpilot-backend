<?php

namespace App\Services\Agent\Tools;

use App\Models\Business;
use App\Models\Conversation;
use App\Services\BookingService;
use Illuminate\Validation\ValidationException;

class RescheduleBookingTool implements AgentTool
{
    use FindsOwnBooking;

    public function __construct(private readonly BookingService $bookings) {}

    public function name(): string
    {
        return 'reschedule_booking';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Move an existing booking to a different time. Only with a starts_at '
                .'value returned by check_availability, and only after the customer confirms the new time.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'reference' => ['type' => 'string', 'description' => 'Booking reference, e.g. BP-2026-0042.'],
                    'starts_at' => [
                        'type' => 'string',
                        'description' => 'Exact starts_at value from check_availability.',
                    ],
                ],
                'required' => ['reference', 'starts_at'],
            ],
        ];
    }

    public function execute(array $input, Conversation $conversation): array
    {
        $booking = $this->findOwnBooking($input['reference'] ?? '', $conversation);

        if (is_array($booking)) {
            return $booking; // the error payload
        }

        try {
            $booking = $this->bookings->reschedule($booking, $input['starts_at'] ?? '');
        } catch (ValidationException $e) {
            return ['error' => collect($e->errors())->flatten()->first()];
        }

        return [
            'rescheduled' => true,
            'reference' => $booking->reference,
            'when' => $booking->starts_at
                ->setTimezone(Business::current()->timezone)
                ->format('l j F, g:i A'),
        ];
    }
}
