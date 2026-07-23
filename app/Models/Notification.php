<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type', 'title', 'body', 'data', 'read_at'])]
class Notification extends Model
{
    // Deliberately few types — a badge that always has something on it
    // stops meaning anything.
    public const TYPE_AI_BOOKING = 'ai_booking';
    public const TYPE_BOOKING_CANCELLED = 'booking_cancelled';
    public const TYPE_HANDOFF = 'handoff';
    public const TYPE_SYNC_FAILED = 'sync_failed';

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
