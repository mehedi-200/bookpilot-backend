<?php

namespace App\Services\Agent\Tools;

use App\Models\Booking;
use App\Models\Conversation;
use App\Services\BookingService;
use Illuminate\Validation\ValidationException;

class CancelBookingTool implements AgentTool
{
    use FindsOwnBooking;

    public function __construct(private readonly BookingService $bookings) {}

    public function name(): string
    {
        return 'cancel_booking';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Cancel an existing booking. Confirm with the customer before calling this — '
                .'it cannot be undone.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'reference' => ['type' => 'string', 'description' => 'Booking reference, e.g. BP-2026-0042.'],
                    'reason' => ['type' => 'string', 'description' => 'Why they are cancelling, if they said.'],
                ],
                'required' => ['reference'],
            ],
        ];
    }

    public function execute(array $input, Conversation $conversation): array
    {
        $booking = $this->findOwnBooking($input['reference'] ?? '', $conversation);

        if (is_array($booking)) {
            return $booking;
        }

        try {
            $booking = $this->bookings->transition(
                $booking,
                Booking::STATUS_CANCELLED,
                $input['reason'] ?? 'Cancelled by the customer in chat',
            );
        } catch (ValidationException $e) {
            return ['error' => collect($e->errors())->flatten()->first()];
        }

        return ['cancelled' => true, 'reference' => $booking->reference];
    }
}
