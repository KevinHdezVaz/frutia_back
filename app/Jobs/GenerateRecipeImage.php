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
        $mealPlan = MealPlan::find($this->mealPlanId);
        if (!$mealPlan) {
            Log::warning("[JOB_SINGLE] No se encontró MealPlan con ID: {$this->mealPlanId}");
            return;
        }

        $planData = $mealPlan->plan_data;
        
        $recipe = $planData['meal_plan'][$this->mealType][$this->mealIndex] ?? null;

        if (!$recipe || empty($recipe['details']['image_prompt'])) {
            Log::info("[JOB_SINGLE] No hay prompt para {$this->mealType}[{$this->mealIndex}] en plan {$this->mealPlanId}");
            return;
        }

        $prompt = $recipe['details']['image_prompt'];
        Log::info("[JOB_SINGLE] Procesando '{$prompt}' para plan {$this->mealPlanId}");

        $imageUrl = $this->generateAndStoreImageForPrompt($prompt);

        if ($imageUrl) {
            Log::info("[JOB_SINGLE] URL generada: {$imageUrl}. Actualizando BD.");
            
            // Actualizamos el JSON con la nueva URL
            $planData['meal_plan'][$this->mealType][$this->mealIndex]['details']['image_url'] = $imageUrl;

            // Actualización directa a la BD
            DB::table('meal_plans')
              ->where('id', $this->mealPlanId)
              ->update(['plan_data' => json_encode($planData)]);
              
            Log::info("[JOB_SINGLE] ¡Éxito! Plan {$this->mealPlanId} actualizado para '{$prompt}'.");
        }
    }

    private function generateAndStoreImageForPrompt($prompt)
    {
        try {
            $response = Http::withToken(env('OPENAI_API_KEY'))
                ->timeout(90)
                ->post('https://api.openai.com/v1/images/generations', [
                    'prompt' => "photo of a delicious {$prompt}",
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