<?php

namespace App\Services\Agent\Tools;

use App\Models\Booking;
use App\Models\Conversation;
use App\Support\PhoneNumber;

trait FindsOwnBooking
{
    /**
     * Resolve a booking the person in THIS chat is allowed to touch.
     * Without this, anyone who guessed a reference could cancel someone
     * else's appointment through the public widget.
     *
     * @return Booking|array the booking, or an error payload for the agent
     */
    protected function findOwnBooking(string $reference, Conversation $conversation): Booking|array
    {
        $phone = $conversation->phone();

        if (! $phone) {
            return ['error' => 'Ask the customer for the phone number the booking was made with first.'];
        }

        $booking = Booking::with('customer')
            ->where('reference', trim(strtoupper($reference)))
            ->first();

        if (! $booking || PhoneNumber::normalize($booking->customer?->phone) !== PhoneNumber::normalize($phone)) {
            // Same message either way — never confirm that a reference exists.
            return ['error' => 'No booking with that reference was found for this phone number.'];
        }

        return $booking;
    }
}
