<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class EquipoPartido extends Model
{
    protected $table = 'equipo_partidos';
    
    protected $fillable = [
        'name',
        'player_count',
        'max_players',
        'field_id',
        'partido_id',
        'schedule_date',
        'start_time',
        'end_time',
        'price',
        'status'
    ];

    protected $casts = [
        'schedule_date' => 'date',
        'start_time' => 'time',
        'end_time' => 'time',
        'price' => 'decimal:2'
    ];

    public function teams()
    {
        return $this->hasMany(MatchTeam::class, 'equipo_partido_id');
    }

    public function field()
    {
        return $this->belongsTo(Field::class);
    }

    // Devolver instancias de Carbon en lugar de cadenas
    public function getScheduleDateAttribute($value)
    {
        return $value ? Carbon::parse($value) : null;
    }

    public function getStartTimeAttribute($value)
    {
        return $value ? Carbon::parse($value) : null;
    }

    public function getEndTimeAttribute($value)
    {
        return $value ? Carbon::parse($value) : null;
    }

    // Métodos auxiliares para obtener cadenas si es necesario
    public function getScheduleDateStringAttribute()
    {
        return $this->schedule_date ? $this->schedule_date->toDateString() : null;
    }

    public function getStartTimeStringAttribute()
    {
        return $this->start_time ? $this->start_time->toTimeString() : null;
    }

    public function getEndTimeStringAttribute()
    {
        return $this->end_time ? $this->end_time->toTimeString() : null;
    }
}