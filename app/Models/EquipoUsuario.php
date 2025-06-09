<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipoUsuario extends Model
{
    protected $table = 'equipo_usuarios';

    protected $fillable = [
        'equipo_id',
        'user_id',
        'rol',
        'estado'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function equipo()
    {
        return $this->belongsTo(Equipo::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}