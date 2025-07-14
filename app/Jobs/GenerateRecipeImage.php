<?php

namespace App\Jobs;

use App\Models\MealPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateRecipeImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    protected $mealPlanId;
    protected $recipeId;
    protected $mealType;

    public function __construct($mealPlanId, $recipeId, $mealType)
    {
        $this->mealPlanId = $mealPlanId;
        $this->recipeId = $recipeId;
        $this->mealType = $mealType;
    }

    public function handle(): void
    {
        Log::info("[JOB_RECIPE_IMAGE] Iniciando generación de imagen para recipeId: {$this->recipeId}", [
            'mealPlanId' => $this->mealPlanId,
            'mealType' => $this->mealType
        ]);

        $mealPlan = MealPlan::find($this->mealPlanId);
        if (!$mealPlan) {
            Log::warning("[JOB_RECIPE_IMAGE] No se encontró MealPlan con ID: {$this->mealPlanId}");
            return;
        }

        $planData = $mealPlan->plan_data;
        $recipe = $this->findRecipe($planData);

        if (!$recipe) {
            Log::warning("[JOB_RECIPE_IMAGE] No se encontró receta con ID: {$this->recipeId}");
            return;
        }

        // Generar prompt si no existe
        if (empty($recipe['imagePrompt'])) {
            $recipe['imagePrompt'] = $this->generateImagePrompt($recipe);
        }

        $imageUrl = $this->generateAndStoreImage($recipe['imagePrompt']);

        if ($imageUrl) {
            $this->updatePlanData($mealPlan, $planData, $imageUrl);
            Log::info("[JOB_RECIPE_IMAGE] Imagen generada y plan actualizado: {$imageUrl}");
        }
    }

    private function findRecipe($planData)
    {
        foreach ($planData['recipes']['inspirationRecipes'] as &$recipe) {
            if ($recipe['id'] === $this->recipeId && $recipe['mealType'] === $this->mealType) {
                return $recipe;
            }
        }
        return null;
    }

    private function generateImagePrompt($recipe)
    {
        $mealTypeMap = [
            'Desayuno' => 'fotografía de desayuno',
            'Almuerzo' => 'fotografía de almuerzo',
            'Cena' => 'fotografía de cena'
        ];

        $style = "fotografía profesional de comida, estilo minimalista, fondo claro, iluminación natural, alta calidad";

        return "{$mealTypeMap[$this->mealType]} {$recipe['title']}, {$recipe['description']}, {$style}";
    }

    private function generateAndStoreImage($prompt)
    {
        try {
            $response = Http::withToken(env('OPENAI_API_KEY'))
                ->timeout(90)
                ->post('https://api.openai.com/v1/images/generations', [
                    'prompt' => $prompt,
                    'model' => 'dall-e-3',
                    'size' => '1024x1024',
                    'quality' => 'standard',
                    'n' => 1,
                    'response_format' => 'url'
                ]);

            if ($response->successful() && $imageUrl = $response->json('data.0.url')) {
                $imageContents = file_get_contents($imageUrl);
                $filename = 'recipe_images/' . Str::uuid() . '.png';
                Storage::disk('public')->put($filename, $imageContents);
                return Storage::url($filename);
            }
            return null;
        } catch (\Exception $e) {
            Log::error("[JOB_RECIPE_IMAGE] Error al generar imagen: " . $e->getMessage());
            return null;
        }
    }

    private function updatePlanData($mealPlan, $planData, $imageUrl)
    {
        foreach ($planData['recipes']['inspirationRecipes'] as &$recipe) {
            if ($recipe['id'] === $this->recipeId && $recipe['mealType'] === $this->mealType) {
                $recipe['imageUrl'] = $imageUrl;
                break;
            }
        }

        $mealPlan->plan_data = $planData;
        $mealPlan->save();
    }
}