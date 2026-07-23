<?php

namespace App\Services\Agent\Tools;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Conversation;
use App\Services\BookingService;
use App\Support\PhoneNumber;
use Illuminate\Validation\ValidationException;

class CreateBookingTool implements AgentTool
{
    public function __construct(private readonly BookingService $bookings)
    {
    }

    public function name(): string
    {
        return 'create_booking';
    }

    public function definition(): array
    {
        return [
            'name' => $this->name(),
            'description' => 'Book an appointment. Only call this AFTER the customer has explicitly '
                .'confirmed the exact service, date and time, and only with a starts_at value that '
                .'check_availability returned. You must have their name and phone number first.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'service_id' => ['type' => 'integer', 'description' => 'Service to book.'],
                    'starts_at' => [
                        'type' => 'string',
                        'description' => 'Exact starts_at value from check_availability.',
                    ],
                    'customer_name' => ['type' => 'string', 'description' => 'Customer’s full name.'],
                    'customer_phone' => ['type' => 'string', 'description' => 'Customer’s phone number.'],
                    'notes' => ['type' => 'string', 'description' => 'Anything useful for the team.'],
                ],
                'required' => ['service_id', 'starts_at', 'customer_name', 'customer_phone'],
            ],
        ];
    }

    public function execute(array $input, Conversation $conversation): array
    {
        $business = Business::current();

        // The owner decides whether the agent may confirm outright.
        $status = $business->auto_confirm
            ? Booking::STATUS_CONFIRMED
            : Booking::STATUS_PENDING;

        try {
            $booking = $this->bookings->create([
                'service_id' => $input['service_id'] ?? null,
                'starts_at' => $input['starts_at'] ?? null,
                'customer_name' => $input['customer_name'] ?? null,
                'customer_phone' => $input['customer_phone'] ?? null,
                'notes' => $input['notes'] ?? null,
                'source' => Booking::SOURCE_WIDGET,
                'conversation_id' => $conversation->id,
                'status' => $status,
            ]);
        } catch (ValidationException $e) {
            return [
                'error' => collect($e->errors())->flatten()->first(),
                'hint' => 'Call check_availability again and offer the customer a time it returns.',
            ];
        } catch (\Throwable) {
            return ['error' => 'The booking could not be saved. Offer to pass this to a human.'];
        }

        // This chat now belongs to a known customer.
        $conversation->update([
            'customer_id' => $booking->customer_id,
            'guest_name' => $input['customer_name'] ?? $conversation->guest_name,
            'guest_phone' => PhoneNumber::normalize($input['customer_phone'] ?? null) ?? $conversation->guest_phone,
        ]);

        $when = $booking->starts_at
            ->setTimezone($business->timezone)
            ->format('l j F, g:i A');

        return [
            'booked' => true,
            'reference' => $booking->reference,
            'status' => $booking->status,
            'when' => $when,
            'service' => $booking->service->name,
            'message' => $status === Booking::STATUS_CONFIRMED
                ? 'The booking is confirmed. Give the customer the reference.'
                : 'The booking is requested and the business will confirm shortly. Tell the customer that, and give them the reference.',
        ];
    }
}
