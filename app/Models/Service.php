<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'duration_minutes', 'price', 'active', 'sort_order'])]
class Service extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'price' => 'decimal:2',
            'duration_minutes' => 'integer',
        ];
    }
}
