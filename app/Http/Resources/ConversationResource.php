<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'handoff_reason' => $this->handoff_reason,
            'contact_name' => $this->contactName(),
            'phone' => $this->phone(),
            'customer_id' => $this->customer_id,
            'started_at' => $this->created_at?->toISOString(),
            'last_activity_at' => $this->last_activity_at?->toISOString(),
            'messages_count' => $this->messages_count ?? null,
            'bookings_count' => $this->bookings_count ?? 0,
            // Both are set by ConversationService, not columns.
            'preview' => $this->preview ?? null,
            'tokens_used' => $this->tokens_used !== null ? (int) $this->tokens_used : null,
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
            'bookings' => BookingResource::collection($this->whenLoaded('bookings')),
        ];
    }
}
