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
            // Wired to real relations in Features 5 & 6.
            'bookings_count' => $this->bookings_count ?? 0,
            'bookings' => $this->whenLoaded('bookings'),
            'conversations' => $this->whenLoaded('conversations'),
        ];
    }
}
