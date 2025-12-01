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

    /**
     * ⭐ NUEVO: Obtiene el historial de comidas de hoy
     */
    public function getTodayHistory(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $today = now()->format('Y-m-d');

            $logs = MealLog::where('user_id', $userId)
                ->where('date', $today)
                ->get();

            // Calcular totales del día
            $dailyTotals = [
                'calories' => 0,
                'protein' => 0,
                'carbs' => 0,
                'fats' => 0,
            ];

            $formattedLogs = $logs->map(function ($log) use (&$dailyTotals) {
                $mealTotals = [
                    'calories' => 0,
                    'protein' => 0,
                    'carbs' => 0,
                    'fats' => 0,
                ];

                // Las selections están guardadas como JSON en el campo 'selections'
                $selections = collect($log->selections)->map(function ($selection) use (&$mealTotals, &$dailyTotals) {
                    $calories = $selection['calories'] ?? 0;
                    $protein = $selection['protein'] ?? 0;
                    $carbs = $selection['carbohydrates'] ?? $selection['carbs'] ?? 0; // ⭐ Buscar ambos nombres
                    $fats = $selection['fats'] ?? 0;

                    $mealTotals['calories'] += $calories;
                    $mealTotals['protein'] += $protein;
                    $mealTotals['carbs'] += $carbs;
                    $mealTotals['fats'] += $fats;

                    // Acumular en totales diarios
                    $dailyTotals['calories'] += $calories;
                    $dailyTotals['protein'] += $protein;
                    $dailyTotals['carbs'] += $carbs;
                    $dailyTotals['fats'] += $fats;

                    return [
                        'name' => $selection['name'] ?? 'Sin nombre',
                        'portion' => $selection['portion'] ?? 'N/A',
                        'calories' => $calories,
                        'protein' => $protein,
                        'carbs' => $carbs,
                        'fats' => $fats,
                    ];
                });

                return [
                    'meal_type' => $log->meal_type,
                    'selections' => $selections,
                    'totals' => $mealTotals,
                    'logged_at' => $log->created_at,
                ];
            });

            return response()->json([
                'status' => 'success',
                'date' => $today,
                'daily_totals' => $dailyTotals, // ⭐ TOTALES DEL DÍA
                'meals' => $formattedLogs,
            ]);

        } catch (\Exception $e) {
            \Log::error('Error al obtener historial de hoy', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el historial de hoy',
                'debug' => $e->getMessage() // ⭐ Para debugging
            ], 500);
        }
    }
}
