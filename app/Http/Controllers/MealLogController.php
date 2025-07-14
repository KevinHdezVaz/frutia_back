<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\MealLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MealLogController extends Controller
{
    /**
     * Guarda o actualiza un registro de comida para un día específico.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'meal_type' => 'required|string|max:255',
            'selections' => 'required|array'
        ]);

        $user = $request->user();

        // Usamos updateOrCreate para evitar duplicados. Si ya existe un registro
        // para ese usuario, en esa fecha y para esa comida, lo actualiza. Si no, lo crea.
        $log = MealLog::updateOrCreate(
            [
                'user_id' => $user->id,
                'date' => Carbon::parse($validated['date'])->toDateString(),
                'meal_type' => $validated['meal_type'],
            ],
            [
                'selections' => $validated['selections']
            ]
        );

        return response()->json(['message' => 'Comida registrada con éxito.', 'data' => $log], 200);
    }

    /**
     * Devuelve el historial de comidas del usuario, paginado.
     */
    public function index(Request $request)
    {
        $logs = $request->user()
            ->mealLogs()
            ->latest('date') // Ordena por fecha, los más recientes primero
            ->paginate(30); // Devuelve de 30 en 30 para no sobrecargar

        return response()->json($logs);
    }
}