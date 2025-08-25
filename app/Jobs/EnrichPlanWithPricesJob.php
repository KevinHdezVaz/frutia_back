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

class EnrichPlanWithPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $mealPlanId;
    public $timeout = 240;
    public $tries = 2;

    public function __construct($mealPlanId)
    {
        $this->mealPlanId = $mealPlanId;
    }

    public function handle()
    {
        Log::info('Iniciando EnrichPlanWithPricesJob', ['mealPlanId' => $this->mealPlanId]);
        $mealPlan = MealPlan::find($this->mealPlanId);

        if (!$mealPlan) {
            Log::error('MealPlan no encontrado para enriquecer con precios.', ['mealPlanId' => $this->mealPlanId]);
            return;
        }

        $user = $mealPlan->user;
        if (!$user || !$user->profile) {
            Log::error('Usuario o perfil no encontrado para el MealPlan.', ['mealPlanId' => $this->mealPlanId]);
            return;
        }

        try {
            $enrichedPlanData = $this->enrichPlanWithPrices($mealPlan->plan_data, $user->profile);

            $mealPlan->plan_data = $enrichedPlanData;
            $mealPlan->save();

            Log::info('Plan enriquecido con precios con éxito.', ['mealPlanId' => $this->mealPlanId]);

        } catch (\Exception $e) {
            Log::error('Excepción en EnrichPlanWithPricesJob', [
                'mealPlanId' => $this->mealPlanId, 'exception' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function enrichPlanWithPrices(array $planData, $profile): array
    {
        $ingredients = $this->extractAllIngredientsFromPlan($planData);
        if (empty($ingredients)) {
            return $planData;
        }
    
        $country = $profile->pais ?? 'Mexico';
    
        $stores = match ($country) {
            'Peru' => ['Plaza Vea', 'Tottus', 'Wong'],
            'Argentina' => ['Coto', 'Carrefour', 'Dia'],
            'Chile' => ['Jumbo', 'Lider', 'Santa Isabel'],
            'Mexico' => ['Walmart', 'Soriana', 'Chedraui'],
            'Colombia' => ['Éxito', 'Jumbo', 'Carulla'],
            default => ['Walmart', 'Soriana', 'Chedraui'],
        };
        
        $currency = match ($country) {
            'Peru' => 'PEN (Soles Peruanos)',
            'Argentina' => 'ARS (Pesos Argentinos)',
            'Chile' => 'CLP (Pesos Chilenos)',
            'Mexico' => 'MXN (Pesos Mexicanos)',
            'Colombia' => 'COP (Pesos Colombianos)',
            default => 'USD (Dólares Estadounidenses)',
        };
        
        $prompt = "Actúa como un asistente de compras de {$country}. Estima los precios para los ingredientes en las tiendas: " . implode(', ', $stores) . ". La moneda debe ser {$currency}. Tu respuesta DEBE ser únicamente un objeto JSON válido. La clave es el nombre del ingrediente. El valor es un objeto con una clave 'prices' que es un array de objetos con 'store' y 'price'. Lista: " . implode(', ', $ingredients);
    
        $response = Http::withToken(env('OPENAI_API_KEY'))->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'response_format' => ['type' => 'json_object'],
        ]);
    
        if ($response->failed()) {
            Log::warning('La llamada a OpenAI para obtener precios falló.', ['status' => $response->status()]);
            return $planData;
        }
    
        $pricesMap = json_decode($response->json('choices.0.message.content'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('No se pudo decodificar el JSON de precios de OpenAI.');
            return $planData;
        }
    
        foreach ($planData['nutritionPlan']['meals'] as &$meal) {
            if (isset($meal['components'])) {
                foreach ($meal['components'] as &$category) {
                    // --- CORRECCIÓN 1: Verificar si la categoría es un array con opciones ---
                    // Esto evita el error cuando un componente es un string (ej: "Vegetales": "Ensalada LIBRE")
                    if (is_array($category) && isset($category['options'])) {
                        foreach ($category['options'] as &$option) {
                            $optionName = trim(preg_replace('/\s*\(.*?\)/', '', $option['name']));
                            foreach ($pricesMap as $priceKey => $priceValue) {
                                if (stripos($optionName, $priceKey) !== false || stripos($priceKey, $optionName) !== false) {
                                    if (is_array($priceValue) && isset($priceValue['prices'])) {
                                        $option['prices'] = $priceValue['prices'];
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        }
    
        return $planData;
    }

    private function extractAllIngredientsFromPlan(array $planData): array
    {
        $ingredients = [];
        if (!isset($planData['nutritionPlan']['meals'])) {
            return [];
        }
    
        foreach ($planData['nutritionPlan']['meals'] as $meal) {
            if (isset($meal['components']) && is_array($meal['components'])) {
                foreach ($meal['components'] as $category) {
                    // --- CORRECCIÓN 2: Aplicar la misma verificación aquí ---
                    if (is_array($category) && isset($category['options']) && is_array($category['options'])) {
                        foreach ($category['options'] as $option) {
                            if (isset($option['name'])) {
                                $cleanName = preg_replace('/\s*\(.*?\)/', '', $option['name']);
                                $keywords = preg_split('/ con | y | a la /', $cleanName);
                                foreach ($keywords as $keyword) {
                                    $ingredients[] = trim($keyword);
                                }
                            }
                        }
                    }
                }
            }
    
            if (isset($meal['suggested_recipes']) && is_array($meal['suggested_recipes'])) {
                foreach ($meal['suggested_recipes'] as $recipe) {
                    if (isset($recipe['extendedIngredients']) && is_array($recipe['extendedIngredients'])) {
                        foreach ($recipe['extendedIngredients'] as $ingredient) {
                            if (isset($ingredient['name'])) {
                                 $ingredients[] = trim($ingredient['name']);
                            }
                        }
                    }
                }
            }
        }
        
        return array_values(array_unique($ingredients));
    }
}
