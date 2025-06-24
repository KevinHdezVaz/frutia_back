<?php

namespace App\Http\Controllers;

use App\Models\MealPlan;
use App\Models\Ingredient;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Jobs\GenerateMealImages;
use App\Jobs\GenerateRecipeImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class PlanController extends Controller
{
    public function generateAndStorePlan(Request $request)
    {
        $user = $request->user()->load('profile');
        $profile = $user->profile;

        if (!$profile) {
            return response()->json(['message' => 'El perfil del usuario no está completo.'], 400);
        }

        $requiredFields = ['goal', 'dietary_style', 'activity_level'];
        $missingFields = array_filter($requiredFields, fn($field) => empty($profile->$field));
        if (!empty($missingFields)) {
            return response()->json([
                'message' => 'Faltan datos requeridos: ' . implode(', ', $missingFields)
            ], 400);
        }

        $dislikedFoods = $profile->disliked_foods ?? 'Ninguno';
        $allergies = $profile->allergies ?? 'Ninguna';
        $sport = is_array($profile->sport) ? implode(', ', $profile->sport) : ($profile->sport ?? 'Ninguno');
        $dietDifficulties = is_array($profile->diet_difficulties) ? implode(', ', $profile->diet_difficulties) : ($profile->diet_difficulties ?? 'Ninguna');
        $dietMotivations = is_array($profile->diet_motivations) ? implode(', ', $profile->diet_motivations) : ($profile->diet_motivations ?? 'Ninguna');
        $preferredName = $profile->preferred_name ?? $user->name ?? 'Usuario';
        $communicationStyle = $profile->communication_style ?? 'Motivadora';
        $breakfastTime = $profile->breakfast_time ?? '08:00';
        $lunchTime = $profile->lunch_time ?? '13:00';
        $dinnerTime = $profile->dinner_time ?? '20:00';
        $medicalCondition = $profile->medical_condition ?? 'Ninguna';
        $mealCount = $profile->meal_count ?? '3 comidas principales';
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
            'Mexico' => ['Walmart Mexico', 'Soriana', 'Chedraui'],
            'Chile' => ['Jumbo', 'Lider', 'Santa Isabel'],
            'Argentina' => ['Cota', 'Carrefour', 'Dia'],
            default => ['Store 1', 'Store 2', 'Store 3']
        };

        $prompt = "Actúa como un nutricionista experto llamado Frutía. Crea un plan de comidas personalizado para el siguiente perfil:
        - Nombre preferido: {$preferredName}
        - Objetivo: {$profile->goal}
        - Edad: {$profile->age} años
        - Sexo: {$profile->sex}
        - Peso: {$profile->weight} kg
        - Altura: {$profile->height} cm
        - Estilo de dieta: {$profile->dietary_style}. IMPORTANTE: Todas las recetas deben ser estrictamente compatibles con esta dieta, evitando ingredientes no permitidos (por ejemplo, granos, legumbres, o frutas altas en azúcar para Keto).
        - Nivel de actividad: {$profile->activity_level}
        - Deportes practicados: {$sport}
        - Frecuencia de entrenamiento: {$profile->training_frequency}
        - Número de comidas al día: {$mealCount}
        - Horarios de comidas: Desayuno ({$breakfastTime}), Almuerzo ({$lunchTime}), Cena ({$dinnerTime})
        - Presupuesto: {$profile->budget}
        - Frecuencia de comer fuera: {$profile->eats_out}
        - Alimentos que NO le gusta: {$dislikedFoods}
        - Alergias: {$allergies}
        - Condición médica: {$medicalCondition}
        - Estilo de comunicación preferido: {$communicationStyle}
        - Dificultades con la dieta: {$dietDifficulties}
        - Motivaciones para seguir la dieta: {$dietMotivations}
        - País del usuario: {$paisUsuario}. **Considera los precios, moneda ({$currency}) y la disponibilidad general de ingredientes en este país** al sugerir recetas. **Consulta los precios de los ingredientes en las siguientes tiendas: " . implode(', ', $stores) . ".**

        Escribe un 'summary_title' corto y motivador usando el nombre preferido ({$preferredName}) con un tono acorde al estilo de comunicación ({$communicationStyle}).

        En lugar de un solo 'summary_text', genera 3 o 4 textos separados (summary_text_1, summary_text_2, resumen_text_3, y opcionalmente summary_text_4) que expliquen por qué este plan es ideal para el usuario. Cada texto debe ser breve (2-3 oraciones), legible con espacios adecuados, y cubrir una parte específica del resumen:
        - summary_text_1: Describe el objetivo ({$profile->goal}), nivel de actividad ({$profile->activity_level}), deportes ({$sport}), y cómo el plan apoya estos aspectos.
        - summary_text_2: Explica cómo se considera la características personales (edad, sexo, peso, altura), preferencias (estilo de dieta, presupuesto, horarios), y restricciones (alimentos no deseados, alergias, condiciones médicas).
        - summary_text_3: Detalla cómo el plan aborda las dificultades ({$dietDifficulties}) y se alinea con las motivaciones ({$dietMotivations}).
        - summary_text_4 (opcional): Resalta beneficios adicionales (energía, digestión, bienestar) y un mensaje motivador para cerrar.

        Asegura un plan de comidas para {$mealCount}. Para cada comida (desayuno, almuerzo, cena, y snacks si {$mealCount} incluye snacks), genera 2 opciones variadas. Para cada opción, incluye:
        - Descripción breve
        - Calorías aproximadas
        - Tiempo de preparación (en minutos)
        - Lista de ingredientes con cantidades específicas (por ejemplo, '1 ajo', '500 g de pollo', '2 tazas de espinaca') respetando {$profile->dietary_style}, {$profile->budget}, {$dislikedFoods}, {$allergies}.
        - Para cada ingrediente, incluye los precios en {$currency} de las tres tiendas principales del país ({$stores[0]}, {$stores[1]}, {$stores[2]}). Si no hay datos exactos, estima precios realistas basados en el mercado de {$paisUsuario}.
        - Instrucciones paso a paso.

        Añade 5 recomendaciones específicas que aborden las dificultades ({$dietDifficulties}), las motivaciones ({$dietMotivations}), y la frecuencia de comer fuera ({$profile->eats_out}). Por ejemplo, sugiere opciones compatibles con {$profile->dietary_style} para comer fuera o estrategias para mantener la constancia.

        y finalmente agrega imágenes de cada receta en image_prompt, con una descripción simple solo el Nombre de la receta.

        IMPORTANTE: Devuelve la respuesta únicamente en formato JSON válido, sin texto introductorio ni explicaciones adicionales, con esta estructura exacta:
        {
            \"summary_title\": \"¡Hola {$preferredName}! Tu plan está listo para ayudarte a brillar.\",
            \"summary_text_1\": \"Este plan está diseñado para tu objetivo de {$profile->goal}, adaptado a tu {$profile->activity_level} nivel de actividad y deportes ({$sport}). Las recetas te ayudarán a alcanzar tus metas con energía y consistencia.\",
            \"summary_text_2\": \"Con {$profile->weight} kg, {$profile->height} cm, y {$profile->age} años, las recetas respetan tu {$profile->dietary_style}, presupuesto ({$profile->budget}), horarios ({$breakfastTime}, {$lunchTime}, {$dinnerTime}), {$dislikedFoods}, y {$allergies}}. Todo está personalizado para ti.\",
            \"summary_text_3\": \"Abordamos tus dificultades ({$dietDifficulties}) con estrategias prácticas y te mantenemos motivado con {$dietMotivations} para que sigas adelante.\",
            \"summary_text_4\": \"Este plan mejorará tu energía, digestión y bienestar general. ¡Estás a un paso de sentirte increíble!\",
            \"meal_plan\": {
                \"desayuno\": [
                    {
                        \"opcion\": \"[Nombre de la receta]\",
                        \"details\": {
                            \"description\": \"[Descripción breve de la receta]\",
                            \"image_prompt\": \"[Descripción simple para el generador de imágenes]\",
                            \"calories\": 0,
                            \"prep_time_minutes\": 0,
                            \"ingredients\": [
                                {
                                    \"item\": \"[Ingrediente 1]\",
                                    \"quantity\": \"[Cantidad, e.g., 1 unidad, 500 g]\",
                                    \"prices\": [
                                        {\"store\": \"{$stores[0]}\", \"price\": 0, \"currency\": \"{$currency}\"},
                                        {\"store\": \"{$stores[1]}\", \"price\": 0, \"currency\": \"{$currency}\"},
                                        {\"store\": \"{$stores[2]}\", \"price\": 0, \"currency\": \"{$currency}\"}
                                    ]
                                }
                            ],
                            \"instructions\": [\"[Paso 1]\", \"[Paso 2]\"]
                        }
                    }
                ],
                \"almuerzo\": [
                    {
                        \"opcion\": \"[Nombre de la receta]\",
                        \"details\": {
                            \"description\": \"[Descripción breve de la receta]\",
                            \"image_prompt\": \"[Descripción simple para el generador de imágenes]\",
                            \"calories\": 0,
                            \"prep_time_minutes\": 0,
                            \"ingredients\": [
                                {
                                    \"item\": \"[Ingrediente 1]\",
                                    \"quantity\": \"[Cantidad, e.g., 1 unidad, 500 g]\",
                                    \"prices\": [
                                        {\"store\": \"{$stores[0]}\", \"price\": 0, \"currency\": \"{$currency}\"},
                                        {\"store\": \"{$stores[1]}\", \"price\": 0, \"currency\": \"{$currency}\"},
                                        {\"store\": \"{$stores[2]}\", \"price\": 0, \"currency\": \"{$currency}\"}
                                    ]
                                }
                            ],
                            \"instructions\": [\"[Paso 1]\", \"[Paso 2]\"]
                        }
                    }
                ],
                \"cena\": [
                    {
                        \"opcion\": \"[Nombre de la receta]\",
                        \"details\": {
                            \"description\": \"[Descripción breve de la receta]\",
                            \"image_prompt\": \"[Descripción simple para el generador de imágenes]\",
                            \"calories\": 0,
                            \"prep_time_minutes\": 0,
                            \"ingredients\": [
                                {
                                    \"item\": \"[Ingrediente 1]\",
                                    \"quantity\": \"[Cantidad, e.g., 1 unidad, 500 g]\",
                                    \"prices\": [
                                        {\"store\": \"{$stores[0]}\", \"price\": 0, \"currency\": \"{$currency}\"},
                                        {\"store\": \"{$stores[1]}\", \"price\": 0, \"currency\": \"{$currency}\"},
                                        {\"store\": \"{$stores[2]}\", \"price\": 0, \"currency\": \"{$currency}\"}
                                    ]
                                }
                            ],
                            \"instructions\": [\"[Paso 1]\", \"[Paso 2]\"]
                        }
                    }
                ],
                \"snacks\": [
                    {
                        \"opcion\": \"[Nombre del snack]\",
                        \"details\": {
                            \"description\": \"[Descripción breve del snack]\",
                            \"image_prompt\": \"[Descripción simple para el generador de imágenes]\",
                            \"calories\": 0,
                            \"prep_time_minutes\": 0,
                            \"ingredients\": [
                                {
                                    \"item\": \"[Ingrediente 1]\",
                                    \"quantity\": \"[Cantidad, e.g., 1 unidad, 500 g]\",
                                    \"prices\": [
                                        {\"store\": \"{$stores[0]}\", \"price\": 0, \"currency\": \"{$currency}\"},
                                        {\"store\": \"{$stores[1]}\", \"price\": 0, \"currency\": \"{$currency}\"},
                                        {\"store\": \"{$stores[2]}\", \"price\": 0, \"currency\": \"{$currency}\"}
                                    ]
                                }
                            ],
                            \"instructions\": [\"[Paso 1]\", \"[Paso 2]\"]
                        }
                    }
                ]
            },
            \"recomendaciones\": [
                \"[Recomendación 1 personalizada]\",
                \"[Recomendación 2 personalizada]\",
                \"[Recomendación 3 personalizada]\",
                \"[Recomendación 4 personalizada]\",
                \"[Recomendación 5 personalizada]\"
            ]
        }";

        try {
            $response = Http::withToken(env('OPENAI_API_KEY'))
                ->timeout(120)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o',
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.7,
                ]);

            if ($response->failed()) {
                Log::error('Error en la respuesta de OpenAI', [
                    'user_id' => $user->id,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json(['message' => 'Error al contactar al servicio de IA.'], 502);
            }

            $planContent = $response->json('choices.0.message.content');
            $planData = json_decode($planContent, true);

            if (json_last_error() !== JSON_ERROR_NONE || !isset($planData['meal_plan'])) {
                Log::error('El JSON de OpenAI no es válido o no tiene la estructura esperada', [
                    'user_id' => $user->id,
                    'content' => $planContent,
                    'json_error' => json_last_error_msg()
                ]);
                return response()->json(['message' => 'Respuesta inesperada del servicio de IA.'], 502);
            }

            MealPlan::where('user_id', $user->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $mealPlan = MealPlan::create([
                'user_id' => $user->id,
                'plan_data' => $planData,
                'is_active' => true,
            ]);

            Log::info('Nuevo plan alimenticio creado, despachando job de imágenes', [
                'user_id' => $user->id,
                'meal_plan_id' => $mealPlan->id
            ]);

            
            $mealTypes = ['desayuno', 'almuerzo', 'cena', 'snacks'];
            foreach ($mealTypes as $type) {
                if (!empty($planData['meal_plan'][$type]) && is_array($planData['meal_plan'][$type])) {
                    foreach ($planData['meal_plan'][$type] as $index => $mealOption) {
                        // Despachamos un job para CADA receta, pasándole el ID del plan,
                        // el tipo de comida y su índice en el array.
                        GenerateRecipeImage::dispatch($mealPlan->id, $type, $index);
                    }
                }
            }
             // La respuesta al usuario no cambia
             return response()->json([
                'message' => 'Plan alimenticio generado con éxito. Las imágenes se están preparando.',
                'data' => $mealPlan
            ], 201);

        } catch (\Exception $e) {
            Log::error('Excepción en generateAndStorePlan', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Ocurrió un error interno al generar el plan.'], 500);
        }
    }

    public function getCurrentPlan(Request $request)
    {
        $user = $request->user();
        
        $mealPlan = MealPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->latest('created_at')
            ->first();

        if (!$mealPlan) {
            return response()->json(['message' => 'No se encontró un plan de alimentación activo.'], 404);
        }

        $enrichedPlanData = $this->enrichPlanWithImages($mealPlan->plan_data);

        return response()->json([
            'message' => 'Plan alimenticio obtenido con éxito',
            'data' => $enrichedPlanData
        ], 200);
    }

    public function getIngredientsList(Request $request)
    {
        $user = $request->user();

        $mealPlan = MealPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->latest('created_at')
            ->first();

        if (!$mealPlan) {
            return response()->json(['message' => 'No se encontró un plan activo.'], 404);
        }

        $planData = $mealPlan->plan_data;
        $allIngredients = [];

        $mealTypes = ['desayuno', 'almuerzo', 'cena', 'snacks'];
        foreach ($mealTypes as $type) {
            if (isset($planData['meal_plan'][$type]) && is_array($planData['meal_plan'][$type])) {
                foreach ($planData['meal_plan'][$type] as $mealOption) {
                    if (isset($mealOption['details']['ingredients']) && is_array($mealOption['details']['ingredients'])) {
                        foreach ($mealOption['details']['ingredients'] as $ingredient) {
                            if (isset($ingredient['item']) && isset($ingredient['quantity'])) {
                                $allIngredients[] = trim($ingredient['item'] . ' (' . $ingredient['quantity'] . ')');
                            }
                        }
                    }
                }
            }
        }

        $uniqueIngredients = array_values(array_unique($allIngredients));
        sort($uniqueIngredients);

        return response()->json([
            'message' => 'Lista de ingredientes obtenida con éxito',
            'data' => $uniqueIngredients
        ], 200);
    }

   // En PlanController.php

private function enrichPlanWithImages(array $planData): array
{
    if (!isset($planData['meal_plan'])) {
        return $planData;
    }

    foreach ($planData['meal_plan'] as &$meals) {
        if (is_array($meals)) {
            foreach ($meals as &$mealOption) {
                if (isset($mealOption['details']['ingredients']) && is_array($mealOption['details']['ingredients'])) {
                    foreach ($mealOption['details']['ingredients'] as &$ingredientData) {
                        if (isset($ingredientData['item'])) {
                            $itemName = trim($ingredientData['item']);
                            $imageUrl = null; // Inicializamos como null

                            // 1. Buscamos primero en nuestra base de datos
                            $dbIngredient = Ingredient::where('name', $itemName)->first();

                            // 2. Verificamos si encontramos un ingrediente Y si tiene una URL válida
                            if ($dbIngredient && !empty($dbIngredient->image_url)) {
                                // ¡ÉXITO! Usamos la URL cacheada de nuestra base de datos
                                Log::info("Usando URL de ingrediente cacheada para: " . $itemName);
                                $imageUrl = $dbIngredient->image_url;
                            } else {
                                // SI NO: No encontramos el ingrediente o no tiene URL, así que llamamos a la API
                                Log::info("No hay URL cacheada para: " . $itemName . ". Buscando en Unsplash.");
                                $imageUrl = $this->fetchImageFromUnsplash($itemName);

                                if ($imageUrl) {
                                    // Si Unsplash nos dio una URL, la guardamos en nuestra BD para la próxima vez.
                                    // updateOrCreate es perfecto: crea el ingrediente si no existe, o solo actualiza la URL si ya existía.
                                    Ingredient::updateOrCreate(
                                        ['name' => $itemName],
                                        ['image_url' => $imageUrl]
                                    );
                                }
                            }
                            
                            // 3. Añadimos la URL (de la BD o de Unsplash) al JSON que se enviará a la app
                            $ingredientData['image_url'] = $imageUrl;
                        }
                    }
                }
            }
        }
    }

    return $planData;
}

    private function fetchImageFromUnsplash(string $keyword): ?string
    {
 
         $accessKey = 'qytRuzMnw8P3BSPJinKJ6QdkCVaL8bFFnMJKh4Qz7-w';

        if (!$accessKey) {
            return null;
        }

        $translations = [
            'yogur natural' => 'plain yogurt',
            'carne de res' => 'beef',
            'pechuga de pollo' => 'chicken breast',
            'cebolla' => 'onion',
            'tortillas de maíz' => 'corn tortillas',
            'pollo' => 'chicken',
            'huevos' => 'eggs',
            'pan' => 'bread',
            'queso' => 'cheese',
            'lechuga' => 'lettuce',
            'tomate' => 'tomato',
            'aguacate' => 'avocado'
        ];

        $cleanKeyword = trim(preg_replace('/\([^)]*\)/', '', $keyword));
        $searchTerm = $translations[strtolower($cleanKeyword)] ?? strtolower($cleanKeyword);

        try {
            $response = Http::withHeaders(['Authorization' => 'Client-ID ' . $accessKey])
                ->get('https://api.unsplash.com/search/photos', [
                    'query' => $searchTerm,
                    'per_page' => 1,
                    'orientation' => 'squarish'
                ]);

            if ($response->successful()) {
                $results = $response->json('results');
                return $results[0]['urls']['small'] ?? null;
            } else {
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Error al buscar en Unsplash', [
                'keyword' => $keyword,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}