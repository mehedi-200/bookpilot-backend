<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'source' => $this->source,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'notes' => $this->notes,
            'rescheduled_from' => $this->rescheduled_from?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancel_reason' => $this->cancel_reason,

            // The UI never has to know the state machine — it just renders these.
            'next_status' => $this->nextStatus(),
            'is_cancellable' => $this->isCancellable(),

            'sync_status' => $this->sync_status,
            'garageflow_job_id' => $this->garageflow_job_id,
            'conversation_id' => $this->conversation_id,

            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'email' => $this->customer->email,
            ]),
            'service' => $this->whenLoaded('service', fn () => [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'duration_minutes' => $this->service->duration_minutes,
                'price' => $this->service->price,
            ]),
        ];
    }
}
