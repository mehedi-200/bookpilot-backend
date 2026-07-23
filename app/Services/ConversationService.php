<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ConversationService
{
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Conversation::query()
            ->with('customer')
            ->withCount('bookings')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, function ($query, $q) {
                $phone = PhoneNumber::normalize($q);
                $query->where(function ($w) use ($q, $phone) {
                    $w->where('guest_name', 'like', "%{$q}%")
                        ->when($phone, fn ($p) => $p->orWhere('guest_phone', 'like', "%{$phone}%"))
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%"));
                });
            })
            ->latest('last_activity_at')
            ->paginate($filters['per_page'] ?? 10)
            ->through(function (Conversation $conversation) {
                $conversation->preview = $this->previewFor($conversation);

                return $conversation;
            });
    }

    public function withTranscript(Conversation $conversation): Conversation
    {
        return $conversation->load(['customer', 'messages', 'bookings.service'])
            ->loadCount('bookings');
    }

    public function tokensUsed(Conversation $conversation): int
    {
        return (int) $conversation->messages()->sum('input_tokens')
            + (int) $conversation->messages()->sum('output_tokens');
    }

    /** Last thing actually said — tool rows are noise in a list view. */
    private function previewFor(Conversation $conversation): ?string
    {
        // reorder(), not latest(): the relation already sorts ascending for the
        // transcript, and a second orderBy would never take effect.
        return $conversation->messages()
            ->whereIn('role', [Message::ROLE_USER, Message::ROLE_ASSISTANT])
            ->whereNotNull('content')
            ->reorder('id', 'desc')
            ->value('content');
    }
}
