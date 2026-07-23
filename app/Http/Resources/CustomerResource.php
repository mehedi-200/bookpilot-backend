<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'bookings_count' => $this->bookings_count ?? 0,
            'bookings' => BookingResource::collection($this->whenLoaded('bookings')),
            // Wired in Feature 6.
            'conversations' => $this->whenLoaded('conversations'),
        ];
    }
}
