<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\MealPlan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateRecipeImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $mealPlan;
    protected $mealType; // 'desayuno', 'almuerzo', o 'cena'
    protected $optionIndex; // El índice de la opción en el array

    public function __construct(MealPlan $mealPlan, string $mealType, int $optionIndex)
    {
        $this->mealPlan = $mealPlan;
        $this->mealType = $mealType;
        $this->optionIndex = $optionIndex;
    }

    public function handle(): void
    {
        $planData = $this->mealPlan->plan_data;
        $optionText = $planData[$this->mealType][$this->optionIndex]['opcion'];

        if (empty($optionText)) return;

        $imagePrompt = "Imagen de comida realista de '{$optionText}', vista superior, plato blanco simple, fondo liso neutro (gris claro o beige), iluminación natural, estilo minimalista. Evitar elementos decorativos complejos.";

        // 2. Llamamos a la API de OpenAI con configuración económica
        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->timeout(30) // Timeout reducido
            ->post('https://api.openai.com/v1/images/generations', [
                'model' => 'dall-e-3',
                'prompt' => $imagePrompt,
                'n' => 1,
                'size' => '256x256', // Tamaño reducido para ahorro
                'quality' => 'standard', // No usar 'hd' que es más caro
            ]);

        if ($response->failed()) return;

        $imageUrl = $response->json()['data'][0]['url'];

        // 3. Descargamos la imagen y la guardamos en nuestro almacenamiento
        $imageContents = file_get_contents($imageUrl);
        $fileName = 'plans/' . Str::random(40) . '.jpg';
        Storage::disk('public')->put($fileName, $imageContents);
        $publicUrl = Storage::disk('public')->url($fileName);

        // 4. Actualizamos el JSON en la base de datos con la nueva URL de la imagen
        $planData[$this->mealType][$this->optionIndex]['details']['image_url'] = $publicUrl;
        $this->mealPlan->plan_data = $planData;
        $this->mealPlan->save();
    }
}