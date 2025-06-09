<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductTienda extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'images',
        'colors',   // Lista de colores
        'sizes',    // Lista de tallas
        'units',    // Cantidad máxima de piezas (entero)
        'numbers',  // Número seleccionado por el usuario en el frontend (entero)
    ];

    protected $casts = [
        'images' => 'array',   // Lista de imágenes
        'colors' => 'array',   // Lista de colores
        'sizes' => 'array',    // Lista de tallas
        'units' => 'integer',  // Cantidad máxima de piezas
        'numbers' => 'integer', // Número seleccionado por el usuario
    ];
}