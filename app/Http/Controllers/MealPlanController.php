<?php

namespace App\Http\Controllers;

use App\Models\MealPlan;
use App\Models\User;
use App\Jobs\GenerateUserPlanJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MealPlanController extends Controller
{
    /**
     * Obtener el plan activo del usuario
     */
    public function getActivePlan(Request $request)
    {
        try {
            $user = Auth::user();

            $mealPlan = MealPlan::where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            if (!$mealPlan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se encontró un plan activo'
                ], 404);
            }

            // Incluir datos de validación si existen
            $planData = $mealPlan->plan_data;
            $planData['validation_status'] = $mealPlan->validation_data['is_valid'] ?? false;
            $planData['generation_method'] = $mealPlan->generation_method;

            return response()->json([
                'status' => 'success',
                'data' => $planData,
                'nutritional_data' => $mealPlan->nutritional_data,
                'personalization_data' => $mealPlan->personalization_data,
                'created_at' => $mealPlan->created_at,
                'updated_at' => $mealPlan->updated_at
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener plan activo', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el plan'
            ], 500);
        }
    }

    /**
     * Validar selección de comida en tiempo real
     */
    public function validateMealSelection(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'selections' => 'required|array',
                'meal_type' => 'required|string',
                'target_macros' => 'required|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $selections = $request->input('selections');
            $mealType = $request->input('meal_type');
            $targetMacros = $request->input('target_macros');

            $validation = $this->performMealValidation($selections, $mealType, $targetMacros);

            return response()->json([
                'status' => 'success',
                'valid' => $validation['is_valid'],
                'warnings' => $validation['warnings'],
                'suggestions' => $validation['suggestions'],
                'current_macros' => $validation['current_macros']
            ]);

        } catch (\Exception $e) {
            Log::error('Error en validación de selección', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al validar selección'
            ], 500);
        }
    }

    /**
     * Validar la selección de comidas
     */
    private function performMealValidation($selections, $mealType, $targetMacros): array
    {
        $warnings = [];
        $suggestions = [];
        $currentMacros = [
            'protein' => 0,
            'carbs' => 0,
            'fats' => 0,
            'calories' => 0
        ];

        // Contar apariciones de alimentos
        $foodCount = [];
        foreach ($selections as $selection) {
            $foodName = strtolower($selection['name'] ?? '');

            // Sumar macros
            $currentMacros['protein'] += $selection['protein'] ?? 0;
            $currentMacros['carbs'] += $selection['carbohydrates'] ?? 0;
            $currentMacros['fats'] += $selection['fats'] ?? 0;
            $currentMacros['calories'] += $selection['calories'] ?? 0;

            // Contar apariciones
            if (!isset($foodCount[$foodName])) {
                $foodCount[$foodName] = 0;
            }
            $foodCount[$foodName]++;

            // Verificar huevos
            if (str_contains($foodName, 'huevo')) {
                if ($foodCount[$foodName] > 1) {
                    $warnings[] = 'Los huevos ya fueron seleccionados en otra comida del día';
                    $suggestions[] = 'Considera usar pollo, atún o otra proteína';
                }
            }
        }

        // Verificar macros vs objetivo
        $proteinDiff = abs($currentMacros['protein'] - ($targetMacros['protein'] ?? 0));
        $carbsDiff = abs($currentMacros['carbs'] - ($targetMacros['carbs'] ?? 0));
        $fatsDiff = abs($currentMacros['fats'] - ($targetMacros['fats'] ?? 0));

        if ($proteinDiff > 10) {
            $warnings[] = sprintf('Proteína: %dg actual vs %dg objetivo (diferencia: %dg)',
                $currentMacros['protein'],
                $targetMacros['protein'],
                $proteinDiff
            );
        }

        if ($carbsDiff > 15) {
            $warnings[] = sprintf('Carbohidratos: %dg actual vs %dg objetivo (diferencia: %dg)',
                $currentMacros['carbs'],
                $targetMacros['carbs'],
                $carbsDiff
            );
        }

        if ($fatsDiff > 5) {
            $warnings[] = sprintf('Grasas: %dg actual vs %dg objetivo (diferencia: %dg)',
                $currentMacros['fats'],
                $targetMacros['fats'],
                $fatsDiff
            );
        }

        return [
            'is_valid' => empty($warnings),
            'warnings' => $warnings,
            'suggestions' => $suggestions,
            'current_macros' => $currentMacros
        ];
    }

    /**
     * Obtener estadísticas del plan
     */
    public function getPlanStatistics(Request $request)
    {
        try {
            $user = Auth::user();

            $activePlan = MealPlan::where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            if (!$activePlan) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No hay plan activo'
                ], 404);
            }

            $stats = [
                'generation_method' => $activePlan->generation_method,
                'validation_passed' => $activePlan->validation_data['is_valid'] ?? false,
                'total_macros' => $activePlan->validation_data['total_macros'] ?? null,
                'warnings_count' => count($activePlan->validation_data['warnings'] ?? []),
                'created_at' => $activePlan->created_at,
                'days_active' => $activePlan->created_at->diffInDays(now())
            ];

            return response()->json([
                'status' => 'success',
                'statistics' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener estadísticas', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener estadísticas'
            ], 500);
        }
    }

    /**
     * Regenerar plan con validación forzada
     */
    public function regeneratePlan(Request $request)
    {
        try {
            $user = Auth::user();

            // Verificar si puede regenerar (límite de 3 por día)
            $regenerationsToday = MealPlan::where('user_id', $user->id)
                ->whereDate('created_at', today())
                ->count();

            if ($regenerationsToday >= 3) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Has alcanzado el límite de regeneraciones por hoy'
                ], 429);
            }

            // ⭐ OBTENER LOCALE DEL HEADER
            $locale = $request->header('Accept-Language', 'es');

            Log::info('🔄 Regenerando plan', [
                'user_id' => $user->id,
                'locale' => $locale
            ]);

            // ⭐ DESPACHAR JOB CON LOCALE
            GenerateUserPlanJob::dispatch($user->id, $locale); // ⭐ AGREGAR LOCALE

            return response()->json([
                'status' => 'success',
                'message' => 'Plan en proceso de regeneración',
                'regenerations_remaining' => 3 - $regenerationsToday - 1,
                'locale' => $locale // ⭐ CONFIRMAR LOCALE
            ]);

        } catch (\Exception $e) {
            Log::error('Error al regenerar plan', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al regenerar el plan'
            ], 500);
        }
    }

public function getTodayHistory(Request $request)
{
    $userId = $request->user()->id;
    $today = now()->format('Y-m-d');

    $logs = MealLog::where('user_id', $userId)
        ->where('date', $today)
        ->with('selections') // Eager loading de selecciones
        ->get();

    // Formatear respuesta con detalles nutricionales
    $formattedLogs = $logs->map(function ($log) {
        $totalCalories = 0;
        $totalProtein = 0;
        $totalCarbs = 0;
        $totalFats = 0;

        $selections = $log->selections->map(function ($selection) use (&$totalCalories, &$totalProtein, &$totalCarbs, &$totalFats) {
            $totalCalories += $selection->calories;
            $totalProtein += $selection->protein;
            $totalCarbs += $selection->carbohydrates;
            $totalFats += $selection->fats;

            return [
                'name' => $selection->name,
                'portion' => $selection->portion,
                'calories' => $selection->calories,
                'protein' => $selection->protein,
                'carbs' => $selection->carbohydrates,
                'fats' => $selection->fats,
            ];
        });

        return [
            'meal_type' => $log->meal_type,
            'selections' => $selections,
            'totals' => [
                'calories' => $totalCalories,
                'protein' => $totalProtein,
                'carbs' => $totalCarbs,
                'fats' => $totalFats,
            ],
        ];
    });

    return response()->json([
        'status' => 'success',
        'data' => $formattedLogs,
    ]);
}
    /**
     * Obtener historial de planes
     */
    public function getPlanHistory(Request $request)
    {
        try {
            $user = Auth::user();

            $plans = MealPlan::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(['id', 'generation_method', 'is_active', 'created_at', 'validation_data']);

            $history = $plans->map(function($plan) {
                return [
                    'id' => $plan->id,
                    'generation_method' => $plan->generation_method,
                    'is_active' => $plan->is_active,
                    'created_at' => $plan->created_at,
                    'validation_passed' => $plan->validation_data['is_valid'] ?? false,
                    'had_warnings' => !empty($plan->validation_data['warnings'] ?? [])
                ];
            });

            return response()->json([
                'status' => 'success',
                'history' => $history
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener historial', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener historial'
            ], 500);
        }
    }
}
