<?php

namespace App\Http\Controllers;

use App\Models\EquipoPartido;
use App\Models\MatchPlayer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Http\Resources\EquipoPartidoResource;

class EquipoPartidoController extends Controller
{
    public function index(Request $request)
    {
        $query = EquipoPartido::with(['field', 'players'])
            ->where('schedule_date', '>=', now()->format('Y-m-d'))
            ->where('status', 'open');

        if ($request->date) {
            $query->whereDate('schedule_date', $request->date);
        }

        if ($request->field_id) {
            $query->where('field_id', $request->field_id);
        }

        $matches = $query->orderByRaw('
            CASE 
                WHEN player_count = max_players THEN 1 
                ELSE 0 
            END ASC,
            schedule_date ASC,
            start_time ASC
        ')->get();

        return EquipoPartidoResource::collection($matches);
    }

    public function store(Request $request)
    {
        $request->validate([
            'field_id' => 'required|exists:fields,id',
            'schedule_date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'max_players' => 'required|integer|min:5|max:11',
            'price' => 'required|numeric|min:0',
            'name' => 'required|string'
        ]);

        $match = EquipoPartido::create([
            'name' => $request->name,
            'field_id' => $request->field_id,
            'schedule_date' => $request->schedule_date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'max_players' => $request->max_players,
            'price' => $request->price,
            'status' => 'open',
            'player_count' => 0
        ]);

        return new EquipoPartidoResource($match);
    }

    public function join(Request $request, EquipoPartido $equipoPartido)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'position' => 'required|string'
        ]);

        if ($equipoPartido->player_count >= $equipoPartido->max_players) {
            return response()->json([
                'message' => 'Este equipo está lleno'
            ], 400);
        }

        // Verificar si el jugador ya está en el equipo
        $existingPlayer = MatchPlayer::where('equipo_partido_id', $equipoPartido->id)
            ->where('player_id', $request->user_id)
            ->exists();

        if ($existingPlayer) {
            return response()->json([
                'message' => 'Ya estás inscrito en este equipo'
            ], 400);
        }

        // Crear el jugador y actualizar el contador
        MatchPlayer::create([
            'equipo_partido_id' => $equipoPartido->id,
            'player_id' => $request->user_id,
            'position' => $request->position
        ]);

        $equipoPartido->increment('player_count');

        return response()->json([
            'message' => 'Te has unido al equipo exitosamente'
        ]);
    }

    public function generateDailyMatches()
    {
        $tomorrow = Carbon::tomorrow();
        
        // Horarios predefinidos
        $schedules = [
            ['start' => '08:00', 'end' => '09:00'],
            ['start' => '16:00', 'end' => '17:00'],
            ['start' => '18:00', 'end' => '19:00'],
            // Añade más horarios según necesites
        ];

        foreach ($schedules as $schedule) {
            EquipoPartido::create([
                'name' => "Equipo " . $schedule['start'],
                'field_id' => 1, // Ajusta según tus campos
                'schedule_date' => $tomorrow,
                'start_time' => $schedule['start'],
                'end_time' => $schedule['end'],
                'max_players' => 7,
                'price' => 50.00,
                'status' => 'open',
                'player_count' => 0
            ]);
        }
    }
}