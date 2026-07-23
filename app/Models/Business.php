<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'phone', 'email', 'address', 'timezone', 'auto_confirm'])]
class Business extends Model
{
    protected function casts(): array
    {
        return [
            'auto_confirm' => 'boolean',
            'widget_seen_at' => 'datetime',
        ];
    }

    // Single-business v1: the one and only row.
    public static function current(): self
    {
        return static::firstOrFail();
    }

    public static function generateWidgetKey(): string
    {
        return 'bp_live_'.Str::random(32);
    }

    public function regenerateWidgetKey(): self
    {
        $this->forceFill([
            'widget_key' => static::generateWidgetKey(),
            'widget_seen_at' => null,
        ])->save();

        return $this;
    }
}
