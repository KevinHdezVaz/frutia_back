<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarouselImage extends Model
{
    protected $table = 'carousel_images'; // Asegúrate de que coincida con el nombre de la tabla
    protected $fillable = ['image_url']; // Campos que se pueden llenar
    protected $visible = ['image_url']; // Solo devuelve image_url en las consultas
}