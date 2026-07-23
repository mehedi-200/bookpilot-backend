<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'token', 'customer_id', 'guest_name', 'guest_phone',
    'status', 'handoff_reason', 'last_activity_at',
])]
class Conversation extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ENDED = 'ended';
    public const STATUS_HANDED_OFF = 'handed_off';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return ['last_activity_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public static function start(): self
    {
        return static::create([
            'token' => Str::random(40),
            'last_activity_at' => now(),
        ]);
    }

    /** The phone this chat belongs to — the key for booking ownership checks. */
    public function phone(): ?string
    {
        return $this->customer?->phone ?? $this->guest_phone;
    }

    public function contactName(): string
    {
        return $this->customer?->name ?? $this->guest_name ?? 'Guest';
    }
}
