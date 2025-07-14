<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealLog extends Model
{
    use HasFactory;
    protected $fillable = ['user_id', 'date', 'meal_type', 'selections'];
    protected $casts = ['selections' => 'array']; // Convierte el JSON a array automáticamente
}