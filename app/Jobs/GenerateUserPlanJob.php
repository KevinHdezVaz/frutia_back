<?php
namespace App\Jobs;

use App\Models\FoodImage;
use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GenerateUserPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;

    public function __construct($userId)
    {
        $this->userId = $userId;
        Log::info('Iniciando GenerateUserPlanJob', ['userId' => $userId]);
    }

    public function handle()
    {
        $user = User::with('profile')->find($this->userId);
        if (!$user || !$user->profile) {
            Log::error('Usuario o perfil no encontrado', ['userId' => $this->userId]);
            return;
        }

        $profile = $user->profile;

        try {
            // Step 1: Generate the nutritional plan
            Log::info('Paso 1: Generando plan nutricional', ['userId' => $user->id]);
            $nutritionalPlan = $this->generateNutritionalPlan($profile, $user);
            if ($nutritionalPlan === null) {
                Log::warning('Plan nutricional no generado, usando plan de respaldo', ['userId' => $user->id]);
                $paisUsuario = $profile->pais ?? 'Peru';
                $currency = match ($paisUsuario) {
                    'Peru' => 'PEN',
                    'Mexico' => 'MXN',
                    'Chile' => 'CLP',
                    'Argentina' => 'ARS',
                    default => 'USD'
                };
                $stores = match ($paisUsuario) {
                    'Peru' => ['Plaza Vea', 'Tottus', 'Metro'],
                    'Mexico' => ['Walmart', 'Soriana', 'Chedraui'],
                    'Chile' => ['Jumbo', 'Lider', 'Santa Isabel'],
                    'Argentina' => ['Coto', 'Carrefour', 'Dia'],
                    default => ['Store 1', 'Store 2', 'Store 3']
                };
                $nutritionalPlan = $this->getBackupPlanData($stores, $currency);
            }
            Log::info('Plan nutricional generado', ['nutritionalPlan' => $nutritionalPlan]);

            // Step 2: Generate recipes based on the plan
            Log::info('Paso 2: Generando recetas', ['userId' => $user->id]);
            $planWithRecipes = $this->generateRecipes($nutritionalPlan, $profile);
            Log::info('Plan con recetas generado', ['planWithRecipes' => $planWithRecipes]);

            // Step 3: Enrich the plan with prices
            Log::info('Paso 3: Enriqueciendo plan con precios', ['userId' => $user->id]);
            $fullPlanData = $this->enrichPlanWithPrices($planWithRecipes, $profile);
            Log::info('Plan completo con precios', ['fullPlanData' => $fullPlanData]);

            // Final storage
            Log::info('Almacenando plan en la base de datos', ['userId' => $user->id]);
            MealPlan::where('user_id', $user->id)->update(['is_active' => false]);
            $mealPlan = MealPlan::create([
                'user_id' => $user->id,
                'plan_data' => $fullPlanData,
                'is_active' => true,
            ]);

            Log::info('Plan alimenticio generado y almacenado con éxito', ['userId' => $user->id, 'mealPlanId' => $mealPlan->id]);

            // Optionally, notify the user (e.g., via email or notification)
            // Example: Notification::send($user, new PlanGeneratedNotification($mealPlan));

        } catch (\Exception $e) {
            Log::error('Excepción crítica en GenerateUserPlanJob', ['userId' => $this->userId, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    private function generateNutritionalPlan($profile, $user): ?array
    {
        $prompt = $this->buildNutritionalPrompt($profile);
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            Log::info("Intento de plan nutricional #{$attempt} para user_id: {$user->id}");

            $response = Http::withToken(env('OPENAI_API_KEY'))->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.4,
                'seed' => rand(1, 1000),
            ]);

            if ($response->failed()) {
                Log::error("Fallo en la llamada a la API para plan nutricional", ['status' => $response->status(), 'body' => $response->body()]);
                continue;
            }
            $planData = json_decode($response->json('choices.0.message.content'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("Error al decodificar JSON del plan nutricional", ['error' => json_last_error_msg()]);
                continue;
            }

            // Validate calories
            $targetCalories = $planData['nutritionPlan']['targetMacros']['calories'] ?? 0;
            $planCalories = 0;
            if (isset($planData['nutritionPlan']['meals']) && is_array($planData['nutritionPlan']['meals'])) {
                foreach ($planData['nutritionPlan']['meals'] as $meal) {
                    if (is_array($meal)) {
                        foreach ($meal as $category) {
                            if (isset($category['options'][0]['calories'])) {
                                $planCalories += $category['options'][0]['calories'];
                            }
                        }
                    }
                }
            }

            if ($targetCalories > 0 && (abs($targetCalories - $planCalories) / $targetCalories) <= 0.12) {
                Log::info("Plan nutricional aceptado en el intento #{$attempt}.", ['planData' => $planData]);
                return $planData;
            }

            Log::warning("Intento nutricional #{$attempt} rechazado.", ['target' => $targetCalories, 'calculated' => $planCalories]);
        }
        return null;
    }

    private function generateRecipes(array $nutritionalPlan, $profile): array
    {
        $prompt = $this->buildRecipePrompt($nutritionalPlan);
        Log::info("Iniciando generación de recetas para user_id: {$profile->user_id}", ['prompt' => $prompt]);

        $response = Http::withToken(env('OPENAI_API_KEY'))->timeout(180)->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.6,
        ]);

        if ($response->successful()) {
            $recipeData = json_decode($response->json('choices.0.message.content'), true);
            Log::info('Respuesta de la API para recetas:', ['recipeData' => $recipeData]);
            if (isset($recipeData['recipes']) && is_array($recipeData['recipes']) && count($recipeData['recipes']) === 9) {
                $nutritionalPlan['recipes'] = $recipeData['recipes'];
                Log::info("Recetas generadas y fusionadas exitosamente.", ['recipes' => $recipeData['recipes']]);
                return $nutritionalPlan;
            } else {
                Log::error("La respuesta de la API no contiene un array 'recipes' válido o no tiene 9 recetas.", ['recipeData' => $recipeData]);
            }
        } else {
            Log::error("Falló la llamada a la API para recetas.", ['status' => $response->status(), 'body' => $response->body()]);
        }

        Log::error("Falló la generación de recetas. Se devolverá el plan sin recetas.");
        return $nutritionalPlan;
    }

    private function enrichPlanWithPrices(array $planWithRecipes, $profile): array
    {
        $paisUsuario = $profile->pais ?? 'Peru';
        $currency = match ($paisUsuario) { 'Peru' => 'PEN', 'Mexico' => 'MXN', 'Chile' => 'CLP', 'Argentina' => 'ARS', default => 'USD' };
        $stores = match ($paisUsuario) { 'Peru' => ['Plaza Vea', 'Tottus', 'Metro'], 'Mexico' => ['Walmart', 'Soriana', 'Chedraui'], 'Chile' => ['Jumbo', 'Lider', 'Santa Isabel'], 'Argentina' => ['Coto', 'Carrefour', 'Dia'], default => ['Store 1', 'Store 2', 'Store 3'] };

        $prompt = $this->buildPriceEnrichmentPrompt($planWithRecipes, $paisUsuario, $stores, $currency);
        
        Log::info("Iniciando enriquecimiento de precios para user_id: {$profile->user_id}");
        $response = Http::withToken(env('OPENAI_API_KEY'))->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => $prompt]],
            'response_format' => ['type' => 'json_object'], 'temperature' => 0.2,
        ]);

        if ($response->successful()) {
            $enrichedData = json_decode($response->json('choices.0.message.content'), true);
            
            if (isset($enrichedData['nutritionPlan'])) {
                $finalPlan = $planWithRecipes;
                $finalPlan['nutritionPlan'] = $enrichedData['nutritionPlan'];
                
                if (isset($enrichedData['recipes'])) {
                    $finalPlan['recipes'] = $enrichedData['recipes'];
                }

                Log::info("Plan enriquecido y fusionado con precios exitosamente.");
                return $finalPlan;
            }
        }
        
        Log::error("Falló el enriquecimiento de precios. Se devolverá el plan sin precios estimados.");
        return $planWithRecipes;
    }

   
    private function buildPriceEnrichmentPrompt(array $plan, string $pais, array $stores, string $currency): string
    {
        $planJson = json_encode($plan, JSON_PRETTY_PRINT);

        return "
        Actúa como un asistente de compras experto para el mercado de {$pais}. Tu única tarea es tomar el siguiente JSON y agregarle un array `prices` a cada objeto dentro de los arrays `options`.

        **Contexto de Mercado:**
        - País: {$pais}
        - Moneda: {$currency}
        - Tiendas: {$stores[0]}, {$stores[1]}, {$stores[2]}

        **INSTRUCCIONES:**
        1. Para cada alimento en `options`, añade un array `prices`.
        2. Dentro de `prices`, crea un objeto para cada una de las tres tiendas.
        3. Cada objeto de precio debe tener TRES claves OBLIGATORIAS: `store` (string), `price` (un número estimado) y `currency` (el string \"{$currency}\").
        4. No alteres ninguna otra parte del JSON. Devuelve el JSON completo y modificado.

        **JSON de Entrada:**
        ```json
        {$planJson}
        ```
        ";
    }

    private function buildRecipePrompt(array $plan): string
    {
        $planJson = json_encode($plan['nutritionPlan']['meals'], JSON_PRETTY_PRINT);
        return "
        Actúa como un chef creativo y nutricionista. Tu tarea es crear recetas inspiradoras basadas en el siguiente plan de comidas.

        **PLAN DE COMIDAS BASE (Ingredientes disponibles):**
        ```json
        {$planJson}
        ```

        **INSTRUCCIONES:**
        1. Crea exactamente 9 recetas: 3 para 'Desayuno', 3 para 'Almuerzo' y 3 para 'Cena'.
        2. Cada receta debe usar una combinación lógica de los alimentos listados en el plan de comidas base.
        3. El campo `planComponents` debe listar los nombres de los ingredientes del plan que se usan en la receta.
        4. Calcula las calorías totales de la receta sumando las calorías de sus `planComponents`.
        5. Cada receta debe seguir esta estructura:
           ```json
           {
               \"mealType\": \"Desayuno|Almuerzo|Cena\",
               \"name\": \"Nombre de la receta\",
               \"planComponents\": [\"ingrediente1\", \"ingrediente2\", ...],
               \"calories\": 123,
               \"instructions\": \"Instrucciones detalladas para preparar la receta\"
           }
           ```
        6. Devuelve únicamente un objeto JSON con una sola clave raíz: `\"recipes\"`, que contenga un array de 9 objetos de receta.
        7. No incluyas ninguna otra información fuera del objeto JSON solicitado.

        **Ejemplo de respuesta esperada:**
        ```json
        {
            \"recipes\": [
                {
                    \"mealType\": \"Desayuno\",
                    \"name\": \"Avena con Frutas\",
                    \"planComponents\": [\"Avena con Proteína\", \"Frutas\"],
                    \"calories\": 450,
                    \"instructions\": \"Mezclar avena con agua, calentar y agregar frutas frescas.\"
                },
                {
                    \"mealType\": \"Almuerzo\",
                    \"name\": \"Ensalada de Pollo\",
                    \"planComponents\": [\"Pechuga de Pollo\", \"Ensalada con Palta\"],
                    \"calories\": 600,
                    \"instructions\": \"Asar la pechuga de pollo y mezclar con ensalada de palta y vegetales.\"
                },
                ...
            ]
        }
        ```
        ";
    }

    private function buildNutritionalPrompt($profile): string
    {
        return "
        Actúa como un nutricionista de élite. Tu misión es crear un plan de nutrición en formato JSON, enfocado 100% en la precisión nutricional y calórica. Ignora los precios por ahora.
        **REGLA MATEMÁTICA CRÍTICA:** La suma de las calorías de `options[0]` de cada categoría DEBE coincidir con el `targetMacros.calories` total. Esta es tu única prioridad.
        **ESTRUCTURA:** Para cada categoría de comida, el array `options` debe contener 2-3 objetos como alternativas calóricamente equivalentes.

        **Datos del Usuario:**
        - Objetivo: {$profile->goal}, Edad: {$profile->age}, Sexo: {$profile->sex}, etc...

        **EJEMPLO DE ESTRUCTURA (SIN PRECIOS):**
        ```json
        {
        \"nutritionPlan\": {
            \"targetMacros\": { \"calories\": 1900, \"protein\": 150, \"carbs\": 180, \"fats\": 65 },
            \"meals\": {
            \"Desayuno\": [
                { \"title\": \"Carbohidratos y Proteína\", \"options\": [ { \"name\": \"Avena con Proteína\", \"calories\": 450, \"protein\": 35, \"carbs\": 55, \"fats\": 10, \"imageKeyword\": \"ingredient.oats\" } ] }
            ],
            \"Almuerzo\": [
                { \"title\": \"Proteína Principal\", \"options\": [ { \"name\": \"Pechuga de Pollo (200g)\", \"calories\": 400, \"protein\": 70, \"carbs\": 0, \"fats\": 12, \"imageKeyword\": \"ingredient.chicken_breast\" } ] },
                { \"title\": \"Carbohidratos\", \"options\": [ { \"name\": \"Arroz Blanco (200g)\", \"calories\": 260, \"protein\": 5, \"carbs\": 56, \"fats\": 1, \"imageKeyword\": \"ingredient.white_rice\" } ] },
                { \"title\": \"Grasas y Vegetales\", \"options\": [ { \"name\": \"Ensalada con Palta (100g)\", \"calories\": 200, \"protein\": 2, \"carbs\": 9, \"fats\": 18, \"imageKeyword\": \"ingredient.avocado\" } ] }
            ],
            \"Cena\": [
                { \"title\": \"Proteína Ligera\", \"options\": [ { \"name\": \"Filete de Pescado (220g)\", \"calories\": 350, \"protein\": 50, \"carbs\": 0, \"fats\": 15, \"imageKeyword\": \"ingredient.white_fish\" } ] },
                { \"title\": \"Carbohidratos Complejos\", \"options\": [ { \"name\": \"Quinoa (150g)\", \"calories\": 180, \"protein\": 6, \"carbs\": 32, \"fats\": 3, \"imageKeyword\": \"ingredient.quinoa\" } ] },
                { \"title\": \"Vegetales\", \"options\": [ { \"name\": \"Brócoli al Vapor (200g)\", \"calories\": 60, \"protein\": 5, \"carbs\": 12, \"fats\": 0, \"imageKeyword\": \"ingredient.broccoli\" } ] }
            ]
            }
        },
        \"recipes\": []
        }
        ```
        ";
    }

    private function getBackupPlanData(array $stores, string $currency): array
    {
        $backupJson = "
        {
        \"nutritionPlan\": {
            \"targetMacros\": { \"calories\": 1900, \"protein\": 150, \"carbs\": 180, \"fats\": 65 },
            \"meals\": {
            \"Desayuno\": [
                {
                \"title\": \"Carbohidratos y Proteína\",
                \"options\": [
                    { \"name\": \"Avena con Proteína en Polvo\", \"calories\": 450, \"protein\": 35, \"carbs\": 55, \"fats\": 10, \"imageKeyword\": \"ingredient.oats\", \"prices\": [ { \"store\": \"{$stores[0]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[1]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[2]}\", \"price\": 0, \"currency\": \"{$currency}\" } ] },
                    { \"name\": \"Pan Integral con Huevos Revueltos\", \"calories\": 450, \"protein\": 28, \"carbs\": 48, \"fats\": 15, \"imageKeyword\": \"ingredient.whole_grain_bread\", \"prices\": [ { \"store\": \"{$stores[0]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[1]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[2]}\", \"price\": 0, \"currency\": \"{$currency}\" } ] }
                ]
                }
            ],
            \"Almuerzo\": [
                {
                \"title\": \"Proteína Principal\",
                \"options\": [
                    { \"name\": \"Pechuga de Pollo (200g)\", \"calories\": 400, \"protein\": 70, \"carbs\": 0, \"fats\": 12, \"imageKeyword\": \"ingredient.chicken_breast\", \"prices\": [ { \"store\": \"{$stores[0]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[1]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[2]}\", \"price\": 0, \"currency\": \"{$currency}\" } ] }
                ]
                },
                {
                \"title\": \"Carbohidratos\",
                \"options\": [
                    { \"name\": \"Arroz Blanco (200g)\", \"calories\": 260, \"protein\": 5, \"carbs\": 56, \"fats\": 1, \"imageKeyword\": \"ingredient.white_rice\", \"prices\": [ { \"store\": \"{$stores[0]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[1]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[2]}\", \"price\": 0, \"currency\": \"{$currency}\" } ] }
                ]
                },
                {
                \"title\": \"Grasas y Vegetales\",
                \"options\": [
                    { \"name\": \"Ensalada con Palta (100g) y aceite\", \"calories\": 200, \"protein\": 2, \"carbs\": 9, \"fats\": 18, \"imageKeyword\": \"ingredient.avocado\", \"prices\": [ { \"store\": \"{$stores[0]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[1]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[2]}\", \"price\": 0, \"currency\": \"{$currency}\" } ] }
                ]
                }
            ],
            \"Cena\": [
                {
                \"title\": \"Proteína Ligera\",
                \"options\": [
                    { \"name\": \"Filete de Pescado (220g)\", \"calories\": 350, \"protein\": 50, \"carbs\": 0, \"fats\": 15, \"imageKeyword\": \"ingredient.white_fish\", \"prices\": [ { \"store\": \"{$stores[0]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[1]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[2]}\", \"price\": 0, \"currency\": \"{$currency}\" } ] }
                ]
                },
                {
                \"title\": \"Carbohidratos Complejos\",
                \"options\": [
                    { \"name\": \"Quinoa (150g)\", \"calories\": 180, \"protein\": 6, \"carbs\": 32, \"fats\": 3, \"imageKeyword\": \"ingredient.quinoa\", \"prices\": [ { \"store\": \"{$stores[0]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[1]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[2]}\", \"price\": 0, \"currency\": \"{$currency}\" } ] }
                ]
                },
                {
                \"title\": \"Vegetales\",
                \"options\": [
                    { \"name\": \"Brócoli al Vapor (200g)\", \"calories\": 60, \"protein\": 5, \"carbs\": 12, \"fats\": 0, \"imageKeyword\": \"ingredient.broccoli\", \"prices\": [ { \"store\": \"{$stores[0]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[1]}\", \"price\": 0, \"currency\": \"{$currency}\" }, { \"store\": \"{$stores[2]}\", \"price\": 0, \"currency\": \"{$currency}\" } ] }
                ]
                }
            ]
            }
        },
        \"recipes\": []
        }
        ";
        return json_decode($backupJson, true);
    }
}