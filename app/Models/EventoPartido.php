<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class EventoPartido extends Model
{
    protected $table = 'eventos_partido';

    protected $fillable = [
        'partido_id',
        'user_id',
        'tipo_evento',
        'minuto',
        'descripcion'
    ];

    public function partido()
    {
        return $this->belongsTo(Partido::class);
    }

    public function jugador()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}