<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    use HasFactory;

    /**
     * Los atributos que se pueden asignar de forma masiva.
     */
    protected $fillable = [
        'name',
        'description',
        'image_url',
        'calories',
        'prep_time_minutes',
        'ingredients',
        'instructions',
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos.
     * Esto hace que Laravel maneje automáticamente los campos JSON como arrays.
     */
    protected $casts = [
        'ingredients' => 'array',
        'instructions' => 'array',
    ];
}