<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealLog extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'meal_type',
        'selections',
    ];

    protected $casts = [
        'date' => 'date',
        'selections' => 'array', // ⭐ IMPORTANTE: Cast a array
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
