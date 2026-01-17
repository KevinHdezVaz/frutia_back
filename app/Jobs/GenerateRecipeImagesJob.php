<?php

namespace App\Jobs;

use App\Models\MealPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateRecipeImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $mealPlanId;
    public $timeout = 600;
    public $tries = 2;
    protected $locale;

    public function __construct($mealPlanId, $locale = 'en')
    {
        $this->mealPlanId = $mealPlanId;
        $this->locale = $locale;
    }

    public function handle()
    {
        App::setLocale($this->locale);

        Log::info('Iniciando GenerateRecipeImagesJob', [
            'mealPlanId' => $this->mealPlanId,
            'locale' => $this->locale
        ]);

        $mealPlan = MealPlan::find($this->mealPlanId);

        if (!$mealPlan) {
            Log::error('MealPlan no encontrado en GenerateRecipeImagesJob', ['mealPlanId' => $this->mealPlanId]);
            return;
        }

        $planData = $mealPlan->plan_data;
        $planModified = false;

        foreach ($planData['nutritionPlan']['meals'] as &$meal) {
            if (isset($meal['suggested_recipes']) && is_array($meal['suggested_recipes'])) {
                foreach ($meal['suggested_recipes'] as &$recipe) {

                    if (isset($recipe['name']) && empty($recipe['image'])) {
                        Log::info('Generando imagen para la receta.', ['recipe' => $recipe['name']]);

                        $imagePath = $this->generateAndStoreImage($recipe['name']);

                        if ($imagePath) {
                            $recipe['image'] = $imagePath;
                            $planModified = true;
                            Log::info('Imagen generada y asignada.', ['path' => $imagePath]);
                        }
                    }
                }
            }
        }

        if ($planModified) {
            $mealPlan->plan_data = $planData;
            $mealPlan->save();
            Log::info('Plan actualizado con nuevas imágenes.', ['mealPlanId' => $this->mealPlanId]);
        } else {
            Log::info('No se necesitaron generar nuevas imágenes para este plan.', ['mealPlanId' => $this->mealPlanId]);
        }
    }

    /**
     * Genera una imagen con GPT Image 1 Mini (más económico que DALL-E 3).
     * GPT Image models SIEMPRE devuelven base64, NO URLs.
     * Devuelve la ruta relativa de la imagen o null si falla.
     */
   private function generateAndStoreImage(string $recipeName): ?string
{
    $prompt = "Professional food photography, delicious dish of '{$recipeName}', served on a white plate, clean and bright background.";

    try {
        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->timeout(120)
            ->post('https://api.openai.com/v1/images/generations', [
                'model' => 'gpt-image-1-mini',
                'prompt' => $prompt,
                'n' => 1,
                'size' => '1024x1024',
                'quality' => 'medium',
            ]);

        if ($response->successful()) {
            // GPT Image devuelve base64 directamente
            $b64Json = $response->json('data.0.b64_json');
            
            if (!$b64Json) {
                Log::error('No se recibió imagen base64 de OpenAI', [
                    'response' => $response->json()
                ]);
                return null;
            }

            $imageData = base64_decode($b64Json);

            if (empty($imageData)) {
                Log::error('Error al decodificar imagen base64');
                return null;
            }

            $filename = 'recipe_images/' . uniqid('recipe_', true) . '.png';
            Storage::disk('public')->put($filename, $imageData);

            Log::info('Imagen guardada exitosamente', ['filename' => $filename]);
            return $filename;
        }

        Log::error('La llamada a OpenAI Image API falló', [
            'status' => $response->status(), 
            'body' => $response->body()
        ]);
        return null;

    } catch (\Exception $e) {
        Log::error('Excepción al generar imagen con OpenAI Image API', [
            'recipeName' => $recipeName, 
            'exception' => $e->getMessage()
        ]);
        return null;
    }
}
}