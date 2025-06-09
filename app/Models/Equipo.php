<?php

namespace App\Models;

use App\Models\User;
use App\Models\ChatMensaje;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Equipo extends Model
{
    protected $fillable = [
        'nombre',
        'color_uniforme',
        'logo',
        'es_abierto',
        'plazas_disponibles'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'es_abierto' => 'boolean',
        'plazas_disponibles' => 'integer'
    ];


    
    public function miembros()
    {
        return $this->belongsToMany(User::class, 'equipo_usuarios')
                    ->withPivot(['rol', 'estado', 'posicion'])
                    ->withTimestamps();
    }

    public function torneos()
    {
        return $this->belongsToMany(Torneo::class, 'torneo_equipos')
                    ->withPivot(['estado'])
                    ->withTimestamps();
    }
 
    public function miembrosActivos()
{
    return $this->miembros()
                ->wherePivot('estado', 'activo');
}

 

public function cantidadMiembros()
{
    return $this->miembrosActivos()->count();
}

 public function mensajes()
{
    return $this->hasMany(ChatMensaje::class);
}

public function esCapitan($user)
{
    if (!$user) return false;
    
    return $this->miembros()
        ->where('user_id', $user->id)
        ->where('rol', 'capitan')
        ->where('estado', 'activo')
        ->exists();
}

  
}