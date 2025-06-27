<?php

namespace App\Jobs;

use App\Models\MealPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateRecipeImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // 2 minutos de tiempo de espera por si DALL-E tarda
    protected $mealPlanId;
    protected $mealType; // ej: 'desayuno'
    protected $mealIndex; // ej: 0 o 1

    public function __construct($mealPlanId, $mealType, $mealIndex)
    {
        $this->mealPlanId = $mealPlanId;
        $this->mealType = $mealType;
        $this->mealIndex = $mealIndex;
    }

    public function handle(): void
{
    Log::info("[JOB_SINGLE] Iniciando procesamiento para mealType: {$this->mealType}, mealIndex: {$this->mealIndex}, mealPlanId: {$this->mealPlanId}");

    $mealPlan = MealPlan::find($this->mealPlanId);
    if (!$mealPlan) {
        Log::warning("[JOB_SINGLE] No se encontró MealPlan con ID: {$this->mealPlanId}");
        return;
    }

    $planData = $mealPlan->plan_data;
    Log::info("[JOB_SINGLE] plan_data cargado", ['plan_data_keys' => array_keys($planData['meal_plan'] ?? [])]);

    // Manejar la sección de alternativas de forma diferente debido a su estructura JSON
    if ($this->mealType === 'desayuno' || $this->mealType === 'almuerzo' || $this->mealType === 'cena' || $this->mealType === 'snacks') {
        $recipe = $planData['meal_plan'][$this->mealType][$this->mealIndex] ?? null;
        Log::info("[JOB_SINGLE] Buscando receta en meal_plan.{$this->mealType}[{$this->mealIndex}]", ['recipe' => $recipe]);
    } else {
        $recipe = $planData['meal_plan']['alternatives'][$this->mealType] ?? null;
        Log::info("[JOB_SINGLE] Buscando receta en meal_plan.alternatives.{$this->mealType}", ['recipe' => $recipe]);
    }

    if (!$recipe || empty($recipe['details']['image_prompt'])) {
        Log::warning("[JOB_SINGLE] No hay receta o image_prompt para {$this->mealType}[{$this->mealIndex}] en plan {$this->mealPlanId}", [
            'recipe_exists' => !empty($recipe),
            'image_prompt_exists' => isset($recipe['details']['image_prompt']),
            'image_prompt' => $recipe['details']['image_prompt'] ?? 'No disponible'
        ]);
        return;
    }

    $prompt = $recipe['details']['image_prompt'];
    Log::info("[JOB_SINGLE] Procesando '{$prompt}' para plan {$this->mealPlanId}");

    $imageUrl = $this->generateAndStoreImageForPrompt($prompt);

    if ($imageUrl) {
        Log::info("[JOB_SINGLE] URL generada: {$imageUrl}. Actualizando BD.");
        // Actualizar el JSON con la nueva URL de la imagen
        if ($this->mealType === 'desayuno' || $this->mealType === 'almuerzo' || $this->mealType === 'cena' || $this->mealType === 'snacks') {
            $planData['meal_plan'][$this->mealType][$this->mealIndex]['details']['image_url'] = $imageUrl;
        } else {
            $planData['meal_plan']['alternatives'][$this->mealType]['details']['image_url'] = $imageUrl;
        }

        try {
            // Actualización en la base de datos
            DB::table('meal_plans')
                ->where('id', $this->mealPlanId)
                ->update(['plan_data' => json_encode($planData)]);
            Log::info("[JOB_SINGLE] ¡Éxito! Plan {$this->mealPlanId} actualizado para '{$prompt}'.");
        } catch (\Exception $e) {
            Log::error("[JOB_SINGLE] Error al actualizar la base de datos para {$prompt}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    } else {
        Log::warning("[JOB_SINGLE] No se generó imageUrl para '{$prompt}' en plan {$this->mealPlanId}");
    }
}
    private function generateAndStoreImageForPrompt($prompt)
    {
        try {
            $response = Http::withToken(env('OPENAI_API_KEY'))
                ->timeout(90)
                ->post('https://api.openai.com/v1/images/generations', [
                    'prompt' => "foto de un delicioso {$prompt}",
                    'model'  => 'dall-e-2',
                    'size'   => '512x512',
                    'n'      => 1,
                    'response_format' => 'url',
                ]);

            if ($response->successful() && isset($response->json()['data'][0]['url'])) {
                $tempImageUrl = $response->json()['data'][0]['url'];
                $imageContents = file_get_contents($tempImageUrl);
                if ($imageContents === false) return null;

                $filename = 'meal_images/' . Str::uuid() . '.png';
                Storage::disk('public')->put($filename, $imageContents);
                return '/storage/' . $filename;
            }
            return null;
        } catch (\Exception $e) {
            Log::error("[JOB_SINGLE] Excepción en DALL-E: " . $e->getMessage());
            return null;
        }
    }
}