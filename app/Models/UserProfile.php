<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    use HasFactory;

    // Array $fillable completo con todos los campos, incluyendo los nuevos
    protected $fillable = [
        'user_id', 'height', 'weight', 'age', 'sex', 'goal', 'activity_level', 'sport',
        'training_frequency', 'meal_count', 'breakfast_time', 'lunch_time', 'dinner_time',
        'dietary_style', 'budget', 'cooking_habit', 'eats_out', 'disliked_foods',
        'has_allergies', 'allergies', 'has_medical_condition', 'medical_condition',
        'communication_style', 'motivation_style', 'preferred_name', 'things_to_avoid',
        'name', 'plan_setup_complete', 'diet_difficulties', 'diet_motivations'
    ];

    // Convertir campos JSON a arrays automáticamente
    protected $casts = [
        'has_allergies' => 'boolean',
        'has_medical_condition' => 'boolean',
        'plan_setup_complete' => 'boolean',
        'sport' => 'array',
        'diet_difficulties' => 'array',
        'diet_motivations' => 'array'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}