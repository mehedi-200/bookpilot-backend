<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'reference', 'customer_id', 'service_id', 'starts_at', 'ends_at',
    'status', 'source', 'conversation_id', 'notes', 'rescheduled_from',
    'cancelled_at', 'cancel_reason',
])]
class Booking extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_WIDGET = 'widget';
    public const SOURCE_MANUAL = 'manual';

    /**
     * The whole lifecycle in one place. Anything not listed is illegal.
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    /** Statuses that occupy a slot. */
    public const BLOCKING_STATUSES = [self::STATUS_PENDING, self::STATUS_CONFIRMED];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'source' => self::SOURCE_MANUAL,
        'sync_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'rescheduled_from' => 'datetime',
            'cancelled_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        // Soft-deleted customers still resolve, so history never shows a blank.
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function service(): BelongsTo
    {
        // Same for retired services.
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** The single valid next step the UI offers (null when terminal). */
    public function nextStatus(): ?string
    {
        return match ($this->status) {
            self::STATUS_PENDING => self::STATUS_CONFIRMED,
            self::STATUS_CONFIRMED => self::STATUS_COMPLETED,
            default => null,
        };
    }

    public function isCancellable(): bool
    {
        return $this->canTransitionTo(self::STATUS_CANCELLED);
    }
}
