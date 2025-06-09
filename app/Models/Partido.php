<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Partido extends Model
{
    protected $fillable = [
        'torneo_id',
        'equipo_local_id',
        'equipo_visitante_id',
        'cancha_id',
        'fecha_programada',
        'goles_local',
        'goles_visitante',
        'estado',
        'ronda',
        'grupo',
        'detalles_partido'
    ];

    protected $casts = [
        'fecha_programada' => 'datetime',
        'detalles_partido' => 'array'
    ];

    public function torneo()
    {
        return $this->belongsTo(Torneo::class);
    }

    public function equipoLocal()
    {
        return $this->belongsTo(Equipo::class, 'equipo_local_id');
    }

    public function equipoVisitante()
    {
        return $this->belongsTo(Equipo::class, 'equipo_visitante_id');
    }

    public function eventos()
    {
        return $this->hasMany(EventoPartido::class);
    }
}