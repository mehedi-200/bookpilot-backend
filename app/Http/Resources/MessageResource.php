<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'tool_calls' => $this->tool_calls,
            'tool_name' => $this->tool_name,
            'tool_result' => $this->tool_result,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
