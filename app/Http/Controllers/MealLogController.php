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

        // ⭐ NORMALIZAR meal_type antes de guardar (siempre en español)
        $normalizedMealType = $this->normalizeMealTypeToSpanish($validated['meal_type']);

        $log = MealLog::updateOrCreate(
            [
                'user_id' => $user->id,
                'date' => Carbon::parse($validated['date'])->toDateString(),
                'meal_type' => $normalizedMealType, // ⭐ GUARDAR NORMALIZADO
            ],
            [
                'selections' => $validated['selections']
            ]
        );

        return response()->json(['message' => 'Comida registrada con éxito.', 'data' => $log], 200);
    }

    /**
     * ⭐ Normaliza meal_type a español para almacenamiento consistente
     */
    private function normalizeMealTypeToSpanish(string $mealType): string
    {
        $normalized = strtolower($mealType);
        
        $mapping = [
            'breakfast' => 'Desayuno',
            'desayuno' => 'Desayuno',
            'lunch' => 'Almuerzo',
            'almuerzo' => 'Almuerzo',
            'dinner' => 'Cena',
            'cena' => 'Cena',
            'snack am' => 'Snack AM',
            'morning snack' => 'Snack AM',
            'snack pm' => 'Snack PM',
            'afternoon snack' => 'Snack PM',
            'fruit snack' => 'Snack de frutas',
            'snack de frutas' => 'Snack de frutas',
            'shake' => 'Shake',
        ];

        return $mapping[$normalized] ?? $mealType;
    }

    /**
     * Devuelve el historial de comidas del usuario
     */
    public function index(Request $request)
    {
        $logs = $request->user()
            ->mealLogs()
            ->latest('date')
            ->paginate(30);

        // ⭐ NO traducir - devolver siempre en español
        return response()->json($logs);
    }

    /**
     * Obtiene el historial de comidas de hoy
     */
    public function getTodayHistory(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $today = now()->format('Y-m-d');

            $logs = MealLog::where('user_id', $userId)
                ->where('date', $today)
                ->get();

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

                $selections = collect($log->selections)->map(function ($selection) use (&$mealTotals, &$dailyTotals) {
                    $calories = $selection['calories'] ?? 0;
                    $protein = $selection['protein'] ?? 0;
                    $carbs = $selection['carbohydrates'] ?? $selection['carbs'] ?? 0;
                    $fats = $selection['fats'] ?? 0;

                    $mealTotals['calories'] += $calories;
                    $mealTotals['protein'] += $protein;
                    $mealTotals['carbs'] += $carbs;
                    $mealTotals['fats'] += $fats;

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
                    'meal_type' => $log->meal_type, // ⭐ NO TRADUCIR - devolver tal cual (español)
                    'selections' => $selections,
                    'totals' => $mealTotals,
                    'logged_at' => $log->created_at,
                ];
            });

            return response()->json([
                'status' => 'success',
                'date' => $today,
                'daily_totals' => $dailyTotals,
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
                'debug' => $e->getMessage()
            ], 500);
        }
    }
}