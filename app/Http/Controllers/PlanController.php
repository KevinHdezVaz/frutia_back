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

        // Carga el perfil para asegurar que existe antes de encolar el job.
        if (!$user->load('profile')->profile) {
            return response()->json(['message' => 'El perfil del usuario no está completo para generar un plan.'], 400);
        }

        // Despacha el job para que se ejecute en segundo plano.
        GenerateUserPlanJob::dispatch($user->id);

        // Devuelve una respuesta inmediata al usuario.
        return response()->json([
            'message' => 'Hemos recibido tu solicitud. Tu plan se está generando y te notificaremos cuando esté listo.'
        ], 202); // 202 Accepted
    }


    public function getPlanStatus(Request $request)
{
    // Validamos que el frontend nos envíe el tiempo en que se solicitó la generación
    $request->validate([
        'generation_request_time' => 'required|integer'
    ]);

    $user = $request->user();
    // Convertimos el timestamp de segundos (enviado por Flutter) a un objeto Carbon
    $requestTime = Carbon::createFromTimestamp($request->query('generation_request_time'));

    // Buscamos el plan activo más reciente del usuario
    $latestPlan = MealPlan::where('user_id', $user->id)
        ->where('is_active', true)
        ->latest('created_at')
        ->first();

    // La "FLAG": ¿Existe un plan Y fue creado DESPUÉS de que lo pedimos?
    if ($latestPlan && $latestPlan->created_at->isAfter($requestTime)) {
        return response()->json(['status' => 'ready']);
    }

    // Si no, el plan todavía está pendiente
    return response()->json(['status' => 'pending']);
}



    /**
     * Obtiene el plan de alimentación activo actual del usuario.
     */
    public function getCurrentPlan(Request $request)
    {
        $user = $request->user()->load('profile');

        $mealPlan = MealPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->latest('created_at')
            ->first();

        $processedPlanData = null;
        if ($mealPlan) {
            // Procesa las imágenes para el frontend.
            $processedPlanData = $mealPlan->plan_data;
            $this->processPlanImages($processedPlanData);
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
     * Reemplaza los keywords de imagen con URLs completas para el frontend.
     * Esta lógica pertenece aquí, ya que es para la presentación de datos.
     */
    private function processPlanImages(array &$planData)
    {
        $keywords = [];
        array_walk_recursive($planData, function ($value, $key) use (&$keywords) {
            if ($key === 'imageKeyword') {
                $keywords[] = $value;
            }
        });

        if (empty($keywords)) return;

        $baseUrl = asset('storage');
        $imagesMap = FoodImage::whereIn('keyword', array_unique($keywords))
            ->pluck('file_path', 'keyword')
            ->all();
        $defaultImageUrl = $baseUrl . '/ingredient_images/default.jpg';

        $this->replaceKeywordsRecursive($planData, $imagesMap, $baseUrl, $defaultImageUrl);
    }

    /**
     * Función recursiva auxiliar para reemplazar keywords de imágenes.
     */
    private function replaceKeywordsRecursive(array &$data, array $imagesMap, string $baseUrl, string $defaultImageUrl)
    {
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $this->replaceKeywordsRecursive($value, $imagesMap, $baseUrl, $defaultImageUrl);
            } elseif ($key === 'imageKeyword' && is_string($value)) {
                $filePath = $imagesMap[$value] ?? null;
                $data['imageUrl'] = $filePath ? $baseUrl . '/' . $filePath : $defaultImageUrl;
                unset($data['imageKeyword']);
            }
        }
    }
}