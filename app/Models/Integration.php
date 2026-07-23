<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider', 'base_url', 'api_token', 'default_mechanic_id',
    'default_mechanic_name', 'enabled', 'last_ok_at', 'last_error',
])]
#[Hidden(['api_token'])]
class Integration extends Model
{
    public const GARAGEFLOW = 'garageflow';

    protected $attributes = [
        'enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            // Never sits in the database in plaintext.
            'api_token' => 'encrypted',
            'enabled' => 'boolean',
            'last_ok_at' => 'datetime',
        ];
    }

    public static function garageflow(): self
    {
        return static::firstOrCreate(['provider' => self::GARAGEFLOW]);
    }

    public function isReady(): bool
    {
        return $this->enabled
            && filled($this->base_url)
            && filled($this->api_token)
            && filled($this->default_mechanic_id);
    }

    public function maskedToken(): ?string
    {
        return $this->api_token
            ? str_repeat('•', 8).substr($this->api_token, -4)
            : null;
    }
}
