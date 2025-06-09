<?php

namespace App\Http\Controllers;

use App\Models\MatchTeam;
use App\Models\MatchPlayer;
use Illuminate\Http\Request;
use App\Models\EquipoPartido;
use App\Models\MatchTeamPlayer;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\PaymentController;

class MatchTeamController extends Controller
{

    protected $walletService;

  
    public function __construct( WalletService $walletService)    {
        $this->walletService = $walletService;  
    }

    public function getTeamsForMatch($matchId)
    {
        $teams = MatchTeam::with(['players.user'])
            ->where('equipo_partido_id', $matchId)
            ->get()
            ->map(function($team) {
                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'color' => $team->color,
                    'emoji' => $team->emoji,
                    'player_count' => $team->player_count,
                    'max_players' => $team->max_players,
                    'players' => $team->players->map(function($player) {
                        return [
                            'id' => $player->id,
                            'user' => [
                                'id' => $player->user->id,
                                'name' => $player->user->name,
                                'profile_image' => $player->user->profile_image
                            ],
                            'position' => $player->position
                        ];
                    })
                ];
            });

        return response()->json(['teams' => $teams]);
    }

    public function getMatchTeams(DailyMatch $match)
    {
        $teams = MatchTeam::where('equipo_partido_id', $match->id)
            ->with(['players' => function($query) {
                $query->join('users', 'match_players.player_id', '=', 'users.id')
                      ->select(
                          'match_players.*',
                          'users.name',
                          'users.profile_image',
                          'users.id as user_id'
                      );
            }])
            ->get();
    
        return response()->json([
            'teams' => $teams->map(function($team) {
                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'color' => $team->color,
                    'emoji' => $team->emoji,
                    'player_count' => $team->player_count,
                    'max_players' => $team->max_players,
                    'players' => $team->players->map(function($player) {
                        return [
                            'position' => $player->position,
                            'equipo_partido_id' => $player->equipo_partido_id,
                            'user' => [
                                'id' => $player->user_id,
                                'name' => $player->name,
                                'profile_image' => $player->profile_image
                            ]
                        ];
                    })
                ];
            })
        ]);
    }

 
 
}