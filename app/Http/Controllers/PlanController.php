<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\MealPlan;
use App\Models\FoodImage;
use Illuminate\Http\Request;
use App\Jobs\GenerateUserPlanJob;

class PlanController extends Controller
{
    /**
     * Inicia la generación del plan de alimentación de forma asíncrona.
     */
    public function generateAndStorePlan(Request $request)
    {
        $user = $request->user();

        if (!$user->load('profile')->profile) {
            return response()->json(['message' => 'El perfil del usuario no está completo para generar un plan.'], 400);
        }

        GenerateUserPlanJob::dispatch($user->id);

        return response()->json([
            'message' => 'Hemos recibido tu solicitud. Tu plan se está generando y te notificaremos cuando esté listo.'
        ], 202);
    }

    /**
     * Verifica si el plan de alimentación ya está listo.
     */
    public function getPlanStatus(Request $request)
    {
        $request->validate([
            'generation_request_time' => 'required|integer'
        ]);

        $user = $request->user();
        $requestTime = Carbon::createFromTimestamp($request->query('generation_request_time'));

        $latestPlan = MealPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->latest('created_at')
            ->first();

        if ($latestPlan && $latestPlan->created_at->isAfter($requestTime)) {
            return response()->json(['status' => 'ready']);
        }

        return response()->json(['status' => 'pending']);
    }

    /**
     * Obtiene el plan activo del usuario y lo procesa para el frontend.
     */
    public function getCurrentPlan(Request $request)
    {
        $user = $request->user()->load('profile');

        $mealPlan = MealPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->latest('created_at')
            ->first();

        $processedPlanData = null;
        if ($mealPlan && !empty($mealPlan->plan_data)) {
            // Aquí se procesa el plan para añadir las URLs de las imágenes
            $processedPlanData = $this->processPlanForFrontend($mealPlan->plan_data);
        }

        return response()->json([
            'message' => 'Datos de perfil y plan obtenidos con éxito.',
            'data' => [
                'user' => $user,
                'profile' => $user->profile,
                'active_plan' => $processedPlanData,
            ]
        ], 200);
    }

    /**
     * Procesa el array del plan para preparar las URLs de las imágenes para el frontend.
     * Esta función unifica toda la lógica de imágenes.
     *
     * @param array $planData El array del plan original.
     * @return array El array del plan procesado.
     */

     private function processPlanForFrontend(array $planData): array
    {
        // Pasamos el array por referencia para modificarlo directamente.
        array_walk_recursive($planData, function (&$value, $key) {
            // Caso 1: Procesa las imágenes nuevas generadas por DALL-E
            if ($key === 'image' && is_string($value) && !empty($value)) {
                // Crea la URL completa y la asigna a la misma clave 'image'
                $value = asset('storage/' . $value);
            }
            // Puedes añadir más lógica aquí si fuera necesario para otros campos.
        });

        // Para hacerle la vida más fácil al frontend, renombramos la clave "image" a "imageUrl".
        // Esta es una forma segura y eficiente de hacerlo en todo el JSON.
        $jsonString = json_encode($planData);
        $jsonString = str_replace('"image":', '"imageUrl":', $jsonString);

        return json_decode($jsonString, true);
    }
}
