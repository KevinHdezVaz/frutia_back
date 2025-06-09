<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan_data',
        'is_active',
    ];

    // Laravel convierte automáticamente el campo JSON a un array/objeto
    protected $casts = [
        'plan_data' => 'array',
        'is_active' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}