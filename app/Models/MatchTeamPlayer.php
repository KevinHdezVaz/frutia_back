<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchTeamPlayer extends Model
{
    protected $table = 'match_team_players';
    
    protected $fillable = [
        'match_team_id',
        'user_id',
        'position'
    ];

    public function team()
    {
        return $this->belongsTo(MatchTeam::class, 'match_team_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}