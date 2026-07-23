<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Customer;
use App\Support\PhoneNumber;

/**
 * One box, three kinds of answer. Phone numbers match regardless of how
 * they're typed, so "0171 234" finds a customer stored as 01712345678.
 */
class SearchService
{
    private const LIMIT = 5;

    private const MIN_LENGTH = 2;

    public function search(string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return ['bookings' => [], 'customers' => [], 'conversations' => [], 'total' => 0];
        }

        $phone = PhoneNumber::normalize($term);

        $results = [
            'bookings' => $this->bookings($term, $phone),
            'customers' => $this->customers($term, $phone),
            'conversations' => $this->conversations($term, $phone),
        ];

        $results['total'] = array_sum(array_map('count', $results));

        return $results;
    }

    private function bookings(string $term, ?string $phone): array
    {
        return Booking::with(['customer', 'service'])
            ->where(function ($query) use ($term, $phone) {
                $query->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($c) => $c
                        ->where('name', 'like', "%{$term}%")
                        ->when($phone, fn ($p) => $p->orWhere('phone', 'like', "%{$phone}%")));
            })
            ->orderByDesc('starts_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Booking $booking) => [
                'id' => $booking->id,
                'reference' => $booking->reference,
                'title' => $booking->customer?->name ?? 'Unknown customer',
                'subtitle' => trim(($booking->service?->name ?? '').' · '.$booking->starts_at->format('D j M, g:i A'), ' ·'),
                'status' => $booking->status,
            ])
            ->all();
    }

    private function customers(string $term, ?string $phone): array
    {
        return Customer::withCount('bookings')
            ->where('name', 'like', "%{$term}%")
            ->when($phone, fn ($q) => $q->orWhere('phone', 'like', "%{$phone}%"))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'title' => $customer->name,
                'subtitle' => $customer->phone,
                'bookings_count' => $customer->bookings_count,
            ])
            ->all();
    }

    private function conversations(string $term, ?string $phone): array
    {
        return Conversation::with('customer')
            ->where(function ($query) use ($term, $phone) {
                $query->where('guest_name', 'like', "%{$term}%")
                    ->when($phone, fn ($q) => $q->orWhere('guest_phone', 'like', "%{$phone}%"))
                    ->orWhereHas('customer', fn ($c) => $c
                        ->where('name', 'like', "%{$term}%")
                        ->when($phone, fn ($p) => $p->orWhere('phone', 'like', "%{$phone}%")));
            })
            ->latest('last_activity_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'title' => $conversation->contactName(),
                'subtitle' => $conversation->last_activity_at?->diffForHumans() ?? 'no activity',
                'status' => $conversation->status,
            ])
            ->all();
    }
}
