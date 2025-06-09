<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationEvent extends Model
{
    protected $fillable = [
        'equipo_partido_id',
        'event_type',
        'scheduled_at',
        'is_sent'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'is_sent' => 'boolean'
    ];

    public function equipoPartido()
    {
        return $this->belongsTo(DailyMatch::class, 'equipo_partido_id');
    }
}