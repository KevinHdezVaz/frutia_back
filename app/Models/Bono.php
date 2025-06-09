<?php

namespace App\Models;

use App\Models\User;
use App\Models\UserBono;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Bono extends Model
{
    use HasFactory;

    protected $table = 'bonos';

    protected $fillable = [
        'tipo',
        'titulo',
        'descripcion',
        'precio',
        'duracion_dias',
        'caracteristicas',
        'is_active',
        'image_path',
    ];

    protected $casts = [
        'caracteristicas' => 'array',
        'precio' => 'float',
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_bonos')
            ->withPivot('fecha_compra', 'fecha_vencimiento', 'estado', 'codigo_referencia', 'usos_disponibles', 'usos_totales')
            ->withTimestamps();
    }

    public function userBonos()
    {
        return $this->hasMany(UserBono::class);
    }
}