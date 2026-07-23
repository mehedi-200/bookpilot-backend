<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'phone', 'email', 'notes'])]
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class)->latest('starts_at');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class)->latest();
    }
}
