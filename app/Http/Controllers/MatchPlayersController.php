<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Order;
use App\Models\Equipo;
use App\Models\UserBono;
use App\Models\MatchTeam;
use App\Models\DailyMatch;
use App\Models\DeviceToken;
use Illuminate\Http\Request;
use App\Models\EquipoPartido;
use App\Models\MatchTeamPlayer;
use App\Services\WalletService;
use App\Models\Notification; // Para registrar notificaciones
use App\Models\NotificationEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MatchPlayersController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function getTeams($matchId)
    {
        try {
            \Log::info('Obteniendo equipos para partido: ' . $matchId);
    
            $equipos = MatchTeam::where('equipo_partido_id', $matchId)
                ->with(['players' => function($query) {
                    $query->join('users', 'match_team_players.user_id', '=', 'users.id')
                        ->select('match_team_players.*', 'users.name', 'users.profile_image');
                }])
                ->get()
                ->map(function($equipo) {
                    \Log::info('Equipo: ' . $equipo->name . ', Jugadores: ' . $equipo->players->count());
                    
                    return [
                        'id' => $equipo->id,
                        'name' => $equipo->name,
                        'player_count' => $equipo->player_count,
                        'color' => $equipo->color,
                        'emoji' => $equipo->emoji,
                        'max_players' => $equipo->max_players,
                        'players' => $equipo->players->map(function($player) {
                            \Log::info('Jugador encontrado:', [
                                'id' => $player->id,
                                'position' => $player->position,
                                'user' => [
                                    'id' => $player->user_id,
                                    'name' => $player->name,
                                    'profile_image' => $player->profile_image
                                ]
                            ]);
                            
                            return [
                                'id' => $player->id,
                                'position' => $player->position,
                                'equipo_partido_id' => $player->match_team_id,
                                'user' => [
                                    'id' => $player->user_id,
                                    'name' => $player->name,
                                    'profile_image' => $player->profile_image
                                ]
                            ];
                        })
                    ];
                });
    
            return response()->json(['equipos' => $equipos]);
    
        } catch (\Exception $e) {
            \Log::error('Error al obtener equipos: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return response()->json([
                'message' => 'Error al obtener equipos: ' . $e->getMessage()
            ], 500);
        }
    }

    public function registerPredefinedTeam(Request $request)
    {
        try {
            \Log::info('Registrando equipo predefinido para partido', ['request' => $request->all()]);
    
            $validated = $request->validate([
                'match_id' => 'required|exists:equipo_partidos,id',
                'predefined_team_id' => 'required|exists:equipos,id',
                'target_team_id' => 'required|exists:match_teams,id'
            ]);
    
            $match = EquipoPartido::findOrFail($validated['match_id']);
            if ($match->status !== 'open') {
                return response()->json(['message' => 'El partido no está disponible'], 400);
            }
    
            $predefinedTeam = Equipo::findOrFail($validated['predefined_team_id']);
            $user = auth()->user();
    
            if (!$predefinedTeam->esCapitan($user)) {
                return response()->json(['message' => 'Solo el capitán puede inscribir al equipo'], 403);
            }
    
            $activeMembers = $predefinedTeam->miembrosActivos()->get();
            if ($activeMembers->isEmpty()) {
                return response()->json(['message' => 'El equipo no tiene miembros activos'], 400);
            }
    
            return DB::transaction(function () use ($match, $predefinedTeam, $activeMembers, $validated) {
                $targetTeam = MatchTeam::where('id', $validated['target_team_id'])
                    ->where('equipo_partido_id', $match->id)
                    ->where('player_count', 0)
                    ->first();
    
                if (!$targetTeam) {
                    return response()->json(['message' => 'El equipo seleccionado no está disponible'], 400);
                }
    
                if ($activeMembers->count() > $targetTeam->max_players) {
                    return response()->json(['message' => 'El equipo excede el límite de jugadores del partido'], 400);
                }
    
                foreach ($activeMembers as $member) {
                    MatchTeamPlayer::create([
                        'match_team_id' => $targetTeam->id,
                        'user_id' => $member->id,
                        'position' => $member->pivot->posicion ?? 'Sin posición',
                    ]);
                }
    
                $targetTeam->player_count = $activeMembers->count();
                $targetTeam->name = $predefinedTeam->nombre;
                $targetTeam->color = $predefinedTeam->color_uniforme;
                $targetTeam->status = 'pending_positions';
                $targetTeam->save();
    
                return response()->json([
                    'message' => 'Equipo predefinido registrado, asigna posiciones',
                    'match_team_id' => $targetTeam->id,
                ]);
            });
    
        } catch (\Exception $e) {
            Log::error('Error al inscribir equipo predefinido: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json(['message' => 'Error al inscribir equipo: ' . $e->getMessage()], 500);
        }
    }

    public function cancelMatch(Request $request, $matchId)
    {
        try {
            $match = EquipoPartido::findOrFail($matchId);
            $user = auth()->user();

            if (!$match->canBeCancelledBy($user)) {
                return response()->json(['message' => 'No tienes permisos para cancelar este partido'], 403);
            }

            $teams = MatchTeam::where('equipo_partido_id', $matchId)->get();
            $playerIds = DeviceToken::whereHas('user', fn($q) => $q->whereHas('matchTeamPlayers.team', fn($q) => $q->where('equipo_partido_id', $matchId)))
                ->pluck('player_id')
                ->toArray();

            return DB::transaction(function () use ($match, $teams, $playerIds, $user) {
                $match->update(['status' => 'cancelled']);

                foreach ($teams as $team) {
                    foreach ($team->players as $player) {
                        $this->walletService->refundMatch(
                            $player->user,
                            $match->price,
                            "Reembolso por cancelación de partido #{$match->id}"
                        );
                    }
                }

                if (!empty($playerIds)) {
                    $message = "El partido '{$match->name}' ha sido cancelado por {$user->name}. Se ha reembolsado el monto a tu monedero.";
                    $title = "Partido Cancelado";

                    $notificationController = app(NotificationController::class);
                    $response = $notificationController->sendOneSignalNotification(
                        $playerIds,
                        $message,
                        $title,
                        ['type' => 'match_cancelled', 'match_id' => $match->id]
                    );

                    Notification::create([
                        'title' => $title,
                        'message' => $message,
                        'player_ids' => json_encode($playerIds),
                        'read' => false,
                    ]);

                    \Log::info("Notificación enviada por cancelación de partido", [
                        'match_id' => $match->id,
                        'response' => $response
                    ]);
                }

                return response()->json(['message' => 'Partido cancelado y reembolsos realizados']);
            });
        } catch (\Exception $e) {
            \Log::error('Error al cancelar partido: ' . $e->getMessage());
            return response()->json(['message' => 'Error al cancelar partido: ' . $e->getMessage()], 500);
        }
    }

    public function finalizeTeamRegistration(Request $request, $teamId)
    {
        try {
            $team = MatchTeam::findOrFail($teamId);
            $match = EquipoPartido::findOrFail($team->equipo_partido_id);
            $user = auth()->user();

            $predefinedTeam = Equipo::where('nombre', $team->name)->first();
            if (!$predefinedTeam || !$predefinedTeam->esCapitan($user)) {
                return response()->json(['message' => 'Solo el capitán puede finalizar la inscripción'], 403);
            }

            $playersWithoutPosition = $team->players()->where('position', 'Sin posición')->count();
            if ($playersWithoutPosition > 0) {
                return response()->json(['message' => 'Todos los jugadores deben tener una posición asignada'], 400);
            }

            return DB::transaction(function () use ($team, $match, $user) {
                $team->status = 'completed';
                $team->save();

                $allTeams = MatchTeam::where('equipo_partido_id', $match->id)->get();
                $allTeamsFull = $allTeams->every(fn($t) => $t->player_count >= $t->max_players);

                if ($allTeamsFull) {
                    $match->status = 'full';
                    $match->save();

                    $matchStartTime = Carbon::createFromFormat('Y-m-d H:i:s', $match->schedule_date . ' ' . $match->start_time);
                    NotificationEvent::create([
                        'equipo_partido_id' => $match->id,
                        'event_type' => 'match_start',
                        'scheduled_at' => $matchStartTime,
                        'message' => 'Tu partido está por comenzar'
                    ]);

                    $matchEndTime = Carbon::createFromFormat('Y-m-d H:i:s', $match->schedule_date . ' ' . $match->end_time);
                    NotificationEvent::create([
                        'equipo_partido_id' => $match->id,
                        'event_type' => 'match_rating',
                        'scheduled_at' => $matchEndTime,
                        'message' => '¡El partido ha terminado! Califica a tus compañeros'
                    ]);

                    $playerIds = DeviceToken::whereHas('user', fn($q) => $q->whereHas('matchTeamPlayers.team', fn($q) => $q->where('equipo_partido_id', $match->id)))
                        ->pluck('player_id')
                        ->toArray();

                    if (!empty($playerIds)) {
                        $message = "¡El partido '{$match->name}' está completo! Nos vemos en la cancha.";
                        $title = "Partido Completo";

                        $notificationController = app(NotificationController::class);
                        $response = $notificationController->sendOneSignalNotification(
                            $playerIds,
                            $message,
                            $title,
                            ['type' => 'match_full', 'match_id' => $match->id]
                        );

                        Notification::create([
                            'title' => $title,
                            'message' => $message,
                            'player_ids' => json_encode($playerIds),
                            'read' => false,
                        ]);

                        \Log::info("Notificación enviada por partido completo", [
                            'match_id' => $match->id,
                            'response' => $response
                        ]);
                    }
                } else {
                    $playerIds = DeviceToken::whereNotNull('user_id')
                        ->where('user_id', '!=', $user->id)
                        ->pluck('player_id')
                        ->toArray();

                    if (!empty($playerIds)) {
                        $message = "El equipo '{$team->name}' se ha inscrito en el partido '{$match->name}'.";
                        $title = "Nuevo Equipo Inscrito";

                        $notificationController = app(NotificationController::class);
                        $response = $notificationController->sendOneSignalNotification(
                            $playerIds,
                            $message,
                            $title,
                            ['type' => 'team_registered', 'match_id' => $match->id, 'team_id' => $team->id]
                        );

                        Notification::create([
                            'title' => $title,
                            'message' => $message,
                            'player_ids' => json_encode($playerIds),
                            'read' => false,
                        ]);

                        \Log::info("Notificación enviada por inscripción de equipo", [
                            'match_id' => $match->id,
                            'team_id' => $team->id,
                            'response' => $response
                        ]);
                    }
                }

                return response()->json(['message' => 'Equipo inscrito exitosamente']);
            });
        } catch (\Exception $e) {
            Log::error('Error al finalizar inscripción de equipo: ' . $e->getMessage());
            return response()->json(['message' => 'Error al finalizar inscripción: ' . $e->getMessage()], 500);
        }
    }

    public function isTeamCaptain(Request $request, $teamId)
    {
        try {
            $team = MatchTeam::with('players.user')->findOrFail($teamId);
            $userId = auth()->id();
    
            if ($team->name != "Equipo 1" && $team->name != "Equipo 2") {
                $predefinedTeam = Equipo::where('nombre', $team->name)->first();
                if ($predefinedTeam) {
                    $isCaptain = $predefinedTeam->miembros()
                        ->where('user_id', $userId)
                        ->where('rol', 'capitan')
                        ->exists();
                    
                    return response()->json(['is_captain' => $isCaptain]);
                }
            }
            
            return response()->json(['is_captain' => false]);
        } catch (\Exception $e) {
            Log::error('Error checking captain status: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function leaveTeam(Request $request, $teamId)
    {
        try {
            return DB::transaction(function () use ($request, $teamId) {
                $user = auth()->user();
                $player = MatchTeamPlayer::where('match_team_id', $teamId)
                    ->where('user_id', $user->id)
                    ->first();

                if (!$player) {
                    return response()->json(['error' => 'No estás en este equipo'], 404);
                }

                $team = MatchTeam::findOrFail($teamId);
                $match = EquipoPartido::findOrFail($team->equipo_partido_id);

                $order = Order::where('user_id', $user->id)
                    ->where('type', 'match')
                    ->where('reference_id', $match->id)
                    ->where('status', 'completed')
                    ->first();

                $price = $order ? floatval($order->total) : floatval($match->price ?? 0);
                $refunded = false;
                $refundedAmount = 0;

                if ($order && $price > 0) {
                    $this->walletService->refundLeaveMatch(
                        $user,
                        $price,
                        "Reembolso por abandonar equipo en partido #{$match->id}"
                    );
                    $refunded = true;
                    $refundedAmount = $price;
                    $order->update(['status' => 'refunded']);
                }

                $player->delete();
                $team->decrement('player_count');

                $allTeams = MatchTeam::where('equipo_partido_id', $match->id)->get();
                $allTeamsFull = $allTeams->every(fn($t) => $t->player_count >= $t->max_players);

                if ($match->status === 'full' && !$allTeamsFull) {
                    $match->update(['status' => 'open']);
                }

                $playerIds = DeviceToken::whereHas('user', fn($q) => $q->whereHas('matchTeamPlayers.team', fn($q) => $q->where('id', $teamId)))
                    ->where('user_id', '!=', $user->id)
                    ->pluck('player_id')
                    ->toArray();

                if (!empty($playerIds)) {
                    $message = "{$user->name} ha abandonado el equipo '{$team->name}' en el partido '{$match->name}'.";
                    $title = "Jugador Abandonó Equipo";

                    $notificationController = app(NotificationController::class);
                    $response = $notificationController->sendOneSignalNotification(
                        $playerIds,
                        $message,
                        $title,
                        ['type' => 'player_left_team', 'match_id' => $match->id, 'team_id' => $teamId]
                    );

                    Notification::create([
                        'title' => $title,
                        'message' => $message,
                        'player_ids' => json_encode($playerIds),
                        'read' => false,
                    ]);

                    \Log::info("Notificación enviada por abandono de equipo", [
                        'match_id' => $match->id,
                        'team_id' => $teamId,
                        'response' => $response
                    ]);
                }

                \Log::info('Jugador abandonó equipo exitosamente', [
                    'user_id' => $user->id,
                    'team_id' => $teamId,
                    'match_id' => $match->id,
                    'refunded' => $refunded,
                    'refunded_amount' => $refundedAmount,
                ]);

                return response()->json([
                    'message' => 'Has abandonado el equipo exitosamente' . ($refunded ? ' y se reembolsó al monedero' : ''),
                    'refunded' => $refunded,
                    'refunded_amount' => $refundedAmount > 0 ? $refundedAmount : null,
                ]);
            });
        } catch (\Exception $e) {
            \Log::error('Error al abandonar equipo: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return response()->json(['error' => 'Error al abandonar el equipo: ' . $e->getMessage()], 500);
        }
    }

    public function leaveTeamAsGroup(Request $request, $teamId)
    {
        try {
            return DB::transaction(function () use ($request, $teamId) {
                $team = MatchTeam::findOrFail($teamId);
                $match = EquipoPartido::findOrFail($team->equipo_partido_id);

                $captainResponse = $this->isTeamCaptain($request, $teamId);
                $isCaptain = $captainResponse->getData()->is_captain ?? false;

                if (!$isCaptain) {
                    throw new \Exception('Solo el capitán puede retirar al equipo completo');
                }

                $playerIds = DeviceToken::whereHas('user', fn($q) => $q->whereHas('matchTeamPlayers.team', fn($q) => $q->where('equipo_partido_id', $match->id)))
                    ->where('user_id', '!=', auth()->id())
                    ->pluck('player_id')
                    ->toArray();

                $team->players()->delete();
                $team->update([
                    'player_count' => 0,
                    'name' => "Equipo " . ($team->id % 2 == 0 ? "2" : "1"),
                    'color' => 'Negro',
                    'emoji' => '⚫'
                ]);

                if ($match->status === 'full') {
                    $match->update(['status' => 'open']);
                }

                if (!empty($playerIds)) {
                    $message = "El equipo '{$team->name}' se ha retirado del partido '{$match->name}'.";
                    $title = "Equipo Retirado";

                    $notificationController = app(NotificationController::class);
                    $response = $notificationController->sendOneSignalNotification(
                        $playerIds,
                        $message,
                        $title,
                        ['type' => 'team_left_match', 'match_id' => $match->id, 'team_id' => $teamId]
                    );

                    Notification::create([
                        'title' => $title,
                        'message' => $message,
                        'player_ids' => json_encode($playerIds),
                        'read' => false,
                    ]);

                    \Log::info("Notificación enviada por retiro de equipo completo", [
                        'match_id' => $match->id,
                        'team_id' => $teamId,
                        'response' => $response
                    ]);
                }

                return response()->json(['message' => 'Equipo retirado exitosamente']);
            });
        } catch (\Exception $e) {
            Log::error('Error leaving team as group: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updatePlayerPosition(Request $request, $teamId, $playerId)
    {
        try {
            \Log::info('Actualizando posición del jugador', [
                'team_id' => $teamId,
                'player_id' => $playerId,
                'position' => $request->position
            ]);

            $validated = $request->validate([
                'position' => 'required|string',
            ]);

            $player = MatchTeamPlayer::where('match_team_id', $teamId)
                ->where('id', $playerId)
                ->firstOrFail();

            $player->update([
                'position' => $validated['position']
            ]);

            return response()->json([
                'message' => 'Posición actualizada exitosamente'
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al actualizar posición: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return response()->json([
                'message' => 'Error al actualizar posición: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getPredefinedTeams(Request $request)
    {
        $user = auth()->user();
        $teams = Equipo::whereHas('miembros', function ($query) use ($user) {
            $query->where('user_id', $user->id)->where('rol', 'capitan')->where('estado', 'activo');
        })->with('miembrosActivos')->get();
        return response()->json($teams);
    }

    public function joinTeam(Request $request)
    {
        try {
            \Log::info('Iniciando proceso de unión al equipo', ['request_data' => $request->all()]);

            $validated = $request->validate([
                'match_id' => 'required|exists:equipo_partidos,id',
                'equipo_partido_id' => 'required|exists:match_teams,id',
                'position' => 'required|string',
                'use_wallet' => 'boolean',
                'use_bono_id' => 'nullable|exists:user_bonos,id',
            ]);

            $existingPlayer = MatchTeamPlayer::whereHas('team', fn($query) => $query->where('equipo_partido_id', $validated['match_id']))
                ->where('user_id', auth()->id())
                ->first();

            if ($existingPlayer) {
                return response()->json(['message' => 'Ya estás registrado en este partido'], 422);
            }

            $team = MatchTeam::findOrFail($validated['equipo_partido_id']);
            if ($team->player_count >= $team->max_players) {
                return response()->json(['message' => 'El equipo está lleno'], 422);
            }

            $match = EquipoPartido::findOrFail($validated['match_id']);
            $price = floatval($match->price ?? 0);
            $useWallet = $request->input('use_wallet', false);
            $useBonoId = $request->input('use_bono_id', null);
            $amountToPay = $price;
            $paymentMethod = null;

            return DB::transaction(function() use ($validated, $team, $match, $price, $useWallet, $useBonoId, &$paymentMethod) {
                $paymentId = null;

                if ($price > 0) {
                    if ($useBonoId) {
                        $userBono = UserBono::where('id', $useBonoId)
                            ->where('user_id', auth()->id())
                            ->where('estado', 'activo')
                            ->where('fecha_vencimiento', '>=', now())
                            ->first();

                        if (!$userBono || ($userBono->usos_disponibles !== null && $userBono->usos_disponibles <= 0)) {
                            throw new \Exception('El bono especificado no es válido o no tiene usos disponibles');
                        }

                        if ($userBono->usos_disponibles !== null) {
                            $userBono->decrement('usos_disponibles');
                        }
                        $paymentMethod = 'bono';
                        $paymentId = $userBono->id;
                        $amountToPay = 0;
                    } elseif ($useWallet) {
                        $wallet = auth()->user()->wallet;
                        if (!$wallet || $wallet->balance < $price) {
                            throw new \Exception('Saldo insuficiente en el monedero');
                        }

                        $this->walletService->withdraw(
                            auth()->user(),
                            $price,
                            "Pago para unirse al equipo {$team->name} en partido #{$match->id}"
                        );
                        $paymentMethod = 'wallet';
                        $amountToPay = 0;
                    } else {
                        throw new \Exception('El pago con MercadoPago no está implementado aún');
                    }
                }

                $player = MatchTeamPlayer::create([
                    'match_team_id' => $validated['equipo_partido_id'],
                    'user_id' => auth()->id(),
                    'position' => $validated['position'],
                    'payment_id' => $paymentId,
                ]);

                $team->increment('player_count');

                $user = auth()->user();
                $matchCount = MatchTeamPlayer::where('user_id', $user->id)->count();
                if ($matchCount == 1 && $user->referred_by) {
                    $referrer = User::find($user->referred_by);
                    if ($referrer) {
                        $this->walletService->deposit($user, 350, "Bonificación por primer partido con referido de #{$referrer->id}");
                        $this->walletService->deposit($referrer, 350, "Bonificación por primer partido de referido #{$user->id}");
                    }
                }

                $allTeams = MatchTeam::where('equipo_partido_id', $match->id)->get();
                $allTeamsFull = $allTeams->every(fn($t) => $t->player_count >= $t->max_players);

                if ($allTeamsFull) {
                    $match->update(['status' => 'full']);
                    $matchStartTime = Carbon::createFromFormat('Y-m-d H:i:s', $match->schedule_date . ' ' . $match->start_time);
                    NotificationEvent::create([
                        'equipo_partido_id' => $match->id,
                        'event_type' => 'match_start',
                        'scheduled_at' => $matchStartTime,
                        'message' => 'Tu partido está por comenzar'
                    ]);

                    $matchEndTime = Carbon::createFromFormat('Y-m-d H:i:s', $match->schedule_date . ' ' . $match->end_time);
                    NotificationEvent::create([
                        'equipo_partido_id' => $match->id,
                        'event_type' => 'match_rating',
                        'scheduled_at' => $matchEndTime,
                        'message' => '¡El partido ha terminado! Califica a tus compañeros'
                    ]);

                    $playerIds = DeviceToken::whereHas('user', fn($q) => $q->whereHas('matchTeamPlayers.team', fn($q) => $q->where('equipo_partido_id', $match->id)))
                        ->pluck('player_id')
                        ->toArray();

                    if (!empty($playerIds)) {
                        $message = "¡El partido '{$match->name}' está completo! Nos vemos en la cancha.";
                        $title = "Partido Completo";

                        $notificationController = app(NotificationController::class);
                        $response = $notificationController->sendOneSignalNotification(
                            $playerIds,
                            $message,
                            $title,
                            ['type' => 'match_full', 'match_id' => $match->id]
                        );

                        Notification::create([
                            'title' => $title,
                            'message' => $message,
                            'player_ids' => json_encode($playerIds),
                            'read' => false,
                        ]);

                        \Log::info("Notificación enviada por partido completo", [
                            'match_id' => $match->id,
                            'response' => $response
                        ]);
                    }
                } else {
                    $playerIds = DeviceToken::whereNotNull('user_id')
                        ->where('user_id', '!=', $user->id)
                        ->pluck('player_id')
                        ->toArray();

                    if (!empty($playerIds)) {
                        $message = "{$user->name} se ha unido al equipo '{$team->name}' en el partido '{$match->name}'.";
                        $title = "Nuevo Jugador en Partido";

                        $notificationController = app(NotificationController::class);
                        $response = $notificationController->sendOneSignalNotification(
                            $playerIds,
                            $message,
                            $title,
                            ['type' => 'player_joined_team', 'match_id' => $match->id, 'team_id' => $team->id]
                        );

                        Notification::create([
                            'title' => $title,
                            'message' => $message,
                            'player_ids' => json_encode($playerIds),
                            'read' => false,
                        ]);

                        \Log::info("Notificación enviada por unión de jugador", [
                            'match_id' => $match->id,
                            'team_id' => $team->id,
                            'response' => $response
                        ]);
                    }
                }

                return response()->json([
                    'message' => 'Te has unido al equipo exitosamente',
                    'payment_method' => $paymentMethod,
                    'amount_paid' => $price,
                ]);
            });
        } catch (\Exception $e) {
            \Log::error('Error al unirse al equipo: ' . $e->getMessage());
            \Log::error($e->getTraceAsString());
            return response()->json([
                'message' => 'Error al unirse al equipo: ' . $e->getMessage()
            ], 500);
        }
    }
}