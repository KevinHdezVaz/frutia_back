<?php

namespace App\Models;

use App\Models\User;
use App\Models\MatchTeam;
use App\Models\DailyMatch;
use Illuminate\Database\Eloquent\Model;

class MatchPlayer extends Model
{
    public $timestamps = true;
    
    protected $table = 'match_players';
    
    protected $fillable = [
        'match_id',
        'player_id',
        'equipo_partido_id',
        'position',
        'payment_status',
        'payment_id',
        'amount'
    ];

    // No necesitamos este cast ya que no tenemos el campo payment_details en esta tabla
    // protected $casts = [
    //     'payment_details' => 'array'
    // ];

    // Relaciones
    public function match()
    {
        return $this->belongsTo(DailyMatch::class, 'match_id');
    }

    // No necesitamos player() y user() ya que hacen lo mismo
    // Mantenemos solo user() que es más descriptivo
    public function user()
    {
        return $this->belongsTo(User::class, 'player_id');
    }

    public function team()
    {
        return $this->belongsTo(MatchTeam::class, 'equipo_partido_id');
    }

    // Esta relación parece incorrecta ya que equipo_partido_id se relaciona con MatchTeam
    // no con DailyMatch, así que la removemos
    // public function equipo_partido()
    // {
    //     return $this->belongsTo(DailyMatch::class, 'equipo_partido_id');
    // }
}