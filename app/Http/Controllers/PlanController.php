<?php
namespace App\Http\Controllers;

use App\Models\FoodImage;
use App\Models\MealPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function generateAndStorePlan(Request $request)
    {
        $user = $request->user()->load('profile');
        $profile = $user->profile;

        if (!$profile) {
            return response()->json(['message' => 'El perfil del usuario no está completo.'], 400);
        }

        try {
            // --- PASO 1: GENERAR EL PLAN NUTRICIONAL BASE ---
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

            // --- PASO 2: GENERAR RECETAS BASADAS EN EL PLAN ---
            Log::info('Paso 2: Generando recetas', ['userId' => $user->id]);
            $planWithRecipes = $this->generateRecipes($nutritionalPlan, $profile);
            Log::info('Plan con recetas generado', ['planWithRecipes' => $planWithRecipes]);

            // --- GUARDADO FINAL ---
            Log::info('Almacenando plan en la base de datos', ['userId' => $user->id]);
            MealPlan::where('user_id', $user->id)->update(['is_active' => false]);
            $mealPlan = MealPlan::create([
                'user_id' => $user->id,
                'plan_data' => $planWithRecipes, // Guardamos el plan con recetas
                'is_active' => true,
            ]);

            Log::info('Plan alimenticio generado y almacenado con éxito', ['userId' => $user->id, 'mealPlanId' => $mealPlan->id]);

            return response()->json(['message' => 'Plan alimenticio generado con éxito.', 'data' => $mealPlan], 201);

        } catch (\Exception $e) {
            Log::error('Excepción crítica en generateAndStorePlan', [
                'userId' => $user->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Ocurrió un error interno fatal.'], 500);
        }
    }

    /**
     * PASO 1: Llama a la IA para generar un plan nutricional validado, con reintentos.
     */
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
            
            // Validación de calorías
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
                return $planData; // Devuelve el plan válido
            }
            
            Log::warning("Intento nutricional #{$attempt} rechazado.", ['target' => $targetCalories, 'calculated' => $planCalories]);
        }
        return null; // Devuelve null si todos los intentos fallan
    }

    /**
     * PASO 2: Llama a la IA para generar recetas basadas en el plan.
     */
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

        Log::error("Falló la generación de recetas. Devolviendo plan sin recetas.", ['nutritionalPlan' => $nutritionalPlan]);
        return $nutritionalPlan;
    }

    /**
     * Construye el prompt para la generación de recetas.
     */
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

    /**
     * Construye el prompt para el plan nutricional.
     */
    private function buildNutritionalPrompt($profile): string
    {
        return "
        Actúa como un nutricionista de élite. Tu misión es crear un plan de nutrición en formato JSON, enfocado 100% en la precisión nutricional y calórica.

        **REGLA MATEMÁTICA CRÍTICA:** La suma de las calorías de `options[0]` de cada categoría DEBE coincidir con el `targetMacros.calories` total. Esta es tu única prioridad.
        **ESTRUCTURA:** Para cada categoría de comida, el array `options` debe contener 2-3 objetos como alternativas calóricamente equivalentes.

        **Datos del Usuario:**
        - Objetivo: {$profile->goal}, Edad: {$profile->age}, Sexo: {$profile->sex}, etc...

        **EJEMPLO DE ESTRUCTURA:**
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

    public function getCurrentPlan(Request $request)
    {
        $user = $request->user()->load('profile');

        $mealPlan = MealPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->latest('created_at')
            ->first();

        $processedPlanData = null;
        if ($mealPlan) {
            $processedPlanData = $mealPlan->plan_data;
            $this->processPlanImages($processedPlanData);
        }

        return response()->json([
            'message' => 'Datos de perfil y plan obtenidos con éxito.',
            'data' => [
                'user' => $user,
                'profile' => $user->profile,
                'active_plan' => $processedPlanData
            ]
        ], 200);
    }

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
     * Devuelve el plan de ejemplo como un array PHP.
     * Este es nuestro plan de respaldo 100% seguro.
     */
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
                                { \"name\": \"Avena con Proteína en Polvo\", \"calories\": 450, \"protein\": 35, \"carbs\": 55, \"fats\": 10, \"imageKeyword\": \"ingredient.oats\" },
                                { \"name\": \"Pan Integral con Huevos Revueltos\", \"calories\": 450, \"protein\": 28, \"carbs\": 48, \"fats\": 15, \"imageKeyword\": \"ingredient.whole_grain_bread\" }
                            ]
                        }
                    ],
                    \"Almuerzo\": [
                        {
                            \"title\": \"Proteína Principal\",
                            \"options\": [
                                { \"name\": \"Pechuga de Pollo (200g)\", \"calories\": 400, \"protein\": 70, \"carbs\": 0, \"fats\": 12, \"imageKeyword\": \"ingredient.chicken_breast\" }
                            ]
                        },
                        {
                            \"title\": \"Carbohidratos\",
                            \"options\": [
                                { \"name\": \"Arroz Blanco (200g)\", \"calories\": 260, \"protein\": 5, \"carbs\": 56, \"fats\": 1, \"imageKeyword\": \"ingredient.white_rice\" }
                            ]
                        },
                        {
                            \"title\": \"Grasas y Vegetales\",
                            \"options\": [
                                { \"name\": \"Ensalada con Palta (100g)\", \"calories\": 200, \"protein\": 2, \"carbs\": 9, \"fats\": 18, \"imageKeyword\": \"ingredient.avocado\" }
                            ]
                        }
                    ],
                    \"Cena\": [
                        {
                            \"title\": \"Proteína Ligera\",
                            \"options\": [
                                { \"name\": \"Filete de Pescado (220g)\", \"calories\": 350, \"protein\": 50, \"carbs\": 0, \"fats\": 15, \"imageKeyword\": \"ingredient.white_fish\" }
                            ]
                        },
                        {
                            \"title\": \"Carbohidratos Complejos\",
                            \"options\": [
                                { \"name\": \"Quinoa (150g)\", \"calories\": 180, \"protein\": 6, \"carbs\": 32, \"fats\": 3, \"imageKeyword\": \"ingredient.quinoa\" }
                            ]
                        },
                        {
                            \"title\": \"Vegetales\",
                            \"options\": [
                                { \"name\": \"Brócoli al Vapor (200g)\", \"calories\": 60, \"protein\": 5, \"carbs\": 12, \"fats\": 0, \"imageKeyword\": \"ingredient.broccoli\" }
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