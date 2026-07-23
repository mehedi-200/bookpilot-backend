<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class NotificationService
{
    /** Who hears about what. Admins get everything; staff get the daily work. */
    private const AUDIENCE = [
        Notification::TYPE_AI_BOOKING => [User::ROLE_ADMIN, User::ROLE_STAFF],
        Notification::TYPE_HANDOFF => [User::ROLE_ADMIN, User::ROLE_STAFF],
        Notification::TYPE_BOOKING_CANCELLED => [User::ROLE_ADMIN],
        Notification::TYPE_SYNC_FAILED => [User::ROLE_ADMIN],
    ];

    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        return Notification::where('user_id', $user->id)
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);
    }

    public function unreadCount(User $user): int
    {
        return Notification::where('user_id', $user->id)->unread()->count();
    }

    public function markRead(Notification $notification): Notification
    {
        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return $notification;
    }

    public function markAllRead(User $user): int
    {
        return Notification::where('user_id', $user->id)->unread()->update(['read_at' => now()]);
    }

    /* ── Triggers ─────────────────────────────────────────────────────── */

    public function aiBooking(Booking $booking): void
    {
        $booking->loadMissing(['customer', 'service']);

        $this->dispatch(
            Notification::TYPE_AI_BOOKING,
            'New booking from your AI',
            sprintf(
                '%s booked %s for %s',
                $booking->customer?->name ?? 'A customer',
                $booking->service?->name ?? 'a service',
                $booking->starts_at->format('D j M, g:i A'),
            ),
            ['booking_id' => $booking->id],
        );
    }

    public function bookingCancelled(Booking $booking): void
    {
        $booking->loadMissing(['customer']);

        $this->dispatch(
            Notification::TYPE_BOOKING_CANCELLED,
            'Booking cancelled',
            sprintf(
                '%s (%s) was cancelled',
                $booking->reference,
                $booking->customer?->name ?? 'unknown customer',
            ),
            ['booking_id' => $booking->id],
        );
    }

    public function handoff(Conversation $conversation): void
    {
        $this->dispatch(
            Notification::TYPE_HANDOFF,
            'A chat needs a human',
            $conversation->handoff_reason ?: "{$conversation->contactName()} is waiting for someone",
            ['conversation_id' => $conversation->id],
        );
    }

    public function syncFailed(Booking $booking): void
    {
        // Retries would otherwise fire this three times for one problem.
        $alreadyKnown = Notification::where('type', Notification::TYPE_SYNC_FAILED)
            ->unread()
            ->where('data->booking_id', $booking->id)
            ->exists();

        if ($alreadyKnown) {
            return;
        }

        $this->dispatch(
            Notification::TYPE_SYNC_FAILED,
            'Booking didn’t reach GarageFlow',
            "{$booking->reference} could not be synced. Open it to retry.",
            ['booking_id' => $booking->id],
        );
    }

    private function dispatch(string $type, string $title, ?string $body, array $data): void
    {
        $this->recipients($type)->each(fn (User $user) => Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]));
    }

    private function recipients(string $type): Collection
    {
        return User::where('active', true)
            ->whereIn('role', self::AUDIENCE[$type] ?? [User::ROLE_ADMIN])
            ->get();
    }
}
