<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'timezone' => $this->timezone,
            'auto_confirm' => $this->auto_confirm,
            // Widget key is admin-only — staff never see it.
            'widget_key' => $this->when($request->user()?->isAdmin(), $this->widget_key),
            'widget_seen_at' => $this->widget_seen_at?->toISOString(),
        ];
    }
}
