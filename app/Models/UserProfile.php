<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    use HasFactory;

    // Array $fillable completo con todos los campos
    protected $fillable = [
        'user_id', 'height', 'weight', 'age', 'sex', 'goal', 'activity_level', 'sport',
        'training_frequency', 'meal_count', 'breakfast_time', 'lunch_time', 'dinner_time',
        'dietary_style', 'budget', 'cooking_habit', 'eats_out', 'disliked_foods',
        'allergies', 'medical_condition', 'communication_style', 'motivation_style',
        'preferred_name', 'things_to_avoid', 'name', 'has_medical_condition', 'has_allergies'
      ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}