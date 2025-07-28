<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\MealPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Jobs\EnrichPlanWithPricesJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateUserPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;
    public $timeout = 400; // Aumentamos por las múltiples llamadas a IA
    public $tries = 2;

    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    // En el archivo: GenerateUserPlanJob.php

public function handle()
{
    Log::info('Iniciando GenerateUserPlanJob', ['userId' => $this->userId]);
    $user = User::with('profile')->find($this->userId);

    if (!$user || !$user->profile) {
        Log::error('Usuario o perfil no encontrado.', ['userId' => $this->userId]);
        return;
    }

    try {
        // PASO 1: Generar plan base con IA
        Log::info('Paso 1: Generando plan nutricional.', ['userId' => $user->id]);
        $planData = $this->generateNutritionalPlan($user->profile);

        if ($planData === null) {
            Log::warning('La IA no generó un plan válido. Usando plan de respaldo.', ['userId' => $user->id]);
            $planData = $this->getBackupPlanData();
        }

        // PASO 2: Generar recetas detalladas con IA
        Log::info('Paso 2: Generando recetas detalladas con IA.', ['userId' => $user->id]);
        $planWithRecipes = $this->generateAndAssignAiRecipes($planData);
        
        // PASO 3: Guardado del plan (sin precios)
        Log::info('Almacenando plan base en la base de datos.', ['userId' => $user->id]);
        MealPlan::where('user_id', $user->id)->update(['is_active' => false]);
        $mealPlan = MealPlan::create([
            'user_id' => $user->id,
            'plan_data' => $planWithRecipes, // Guardamos el plan con recetas pero sin precios
            'is_active' => true,
        ]);

        // ▼▼▼ PASO 4: DESPACHAR EL NUEVO JOB PARA LOS PRECIOS ▼▼▼
        Log::info('Despachando job para enriquecer con precios.', ['mealPlanId' => $mealPlan->id]);
        EnrichPlanWithPricesJob::dispatch($mealPlan->id);

        Log::info('Plan base generado. El enriquecimiento de precios se ejecutará en segundo plano.', ['userId' => $user->id, 'mealPlanId' => $mealPlan->id]);

    } catch (\Exception $e) {
        Log::error('Excepción crítica en GenerateUserPlanJob', [
            'userId' => $this->userId, 'exception' => $e->getMessage()
        ]);
        throw $e;
    }
}

    /**
 * Genera TRES recetas distintas para un conjunto de componentes de comida.
 *
 * @param array $mealComponents
 * @return array|null
 */
private function generateThreeRecipesForMeal(array $mealComponents): ?array
{
    $ingredientList = [];
    foreach ($mealComponents as $component) {
        if (isset($component['options'][0]['name'])) {
            $ingredientList[] = $component['options'][0]['name'];
        }
    }

    if (empty($ingredientList)) {
        return null;
    }

    $ingredientsString = implode(' y ', $ingredientList);
    
    $prompt = "Actúa como un chef experto y nutricionista. Tu misión es crear TRES recetas saludables y DELICIOSAS que sean notablemente DIFERENTES entre sí, usando estos ingredientes base: {$ingredientsString}.
    
    Tu respuesta DEBE ser únicamente un objeto JSON con una clave raíz 'recipes' que contiene un ARRAY de EXACTAMENTE TRES objetos de receta. La estructura de cada objeto de receta debe ser:
    {
      \"name\": \"Nombre Creativo y Único de la Receta\",
      \"instructions\": \"Paso 1:...\nPaso 2:...\nPaso 3:...\",
      \"readyInMinutes\": 30,
      \"servings\": 1,
      \"calories\": 550,
      \"protein\": 40,
      \"carbs\": 60,
      \"fats\": 25,
      \"extendedIngredients\": [
        {\"name\": \"ingrediente1\", \"original\": \"cantidad y unidad\"}
      ]
    }
    Asegúrate de que los TRES objetos de receta en el array sean distintos para dar variedad. La respuesta debe ser solo el JSON y en español.";

    $response = Http::withToken(env('OPENAI_API_KEY'))->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
        'model' => 'gpt-4o',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'response_format' => ['type' => 'json_object'],
        'temperature' => 0.7, // Aumentamos la temperatura para asegurar la variedad
    ]);

    if ($response->successful()) {
        $data = json_decode($response->json('choices.0.message.content'), true);
        
        if (json_last_error() === JSON_ERROR_NONE && isset($data['recipes']) && is_array($data['recipes']) && count($data['recipes']) === 3) {
            $processedRecipes = [];
            foreach ($data['recipes'] as $recipeData) {
                $recipeData['image'] = null;
                $recipeData['analyzedInstructions'] = [];
                $processedRecipes[] = $recipeData;
            }
            return $processedRecipes;
        } else {
             Log::warning("La IA no devolvió 3 recetas como se esperaba.", ['response' => $data]);
        }
    }

    Log::error("Fallo al generar 3 recetas detalladas con IA", ['status' => $response->status(), 'body' => $response->body()]);
    return null;
}

   
private function generateAndAssignAiRecipes(array $planData): array
{
    // Esta parte ya está bien, obtiene las comidas del plan (ej: Almuerzo, Cena)
    $mealsToSearch = array_keys($planData['nutritionPlan']['meals']);

    if (empty($mealsToSearch)) {
        return $planData;
    }

    foreach ($mealsToSearch as $mealName) {
        if (isset($planData['nutritionPlan']['meals'][$mealName])) {
            $mealComponents = $planData['nutritionPlan']['meals'][$mealName];
            
            // ▼▼▼ CAMBIO: Llama a la nueva función ▼▼▼
            $recipes = $this->generateThreeRecipesForMeal($mealComponents);
            
            if (!empty($recipes)) {
                $newMealObject = [
                    'components' => $mealComponents,
                    'suggested_recipes' => $recipes
                ];
                $planData['nutritionPlan']['meals'][$mealName] = $newMealObject;
                Log::info(count($recipes) . " recetas de IA generadas para {$mealName}.");
            }
        }
    }
    return $planData;
}


    
    
    private function generateNutritionalPlan($profile): ?array
    {
        $prompt = $this->buildNutritionalPrompt($profile);
        $response = Http::withToken(env('OPENAI_API_KEY'))->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.5,
        ]);

        if ($response->successful()) {
            return json_decode($response->json('choices.0.message.content'), true);
        }
        
        Log::error("Fallo en la llamada a OpenAI para generar plan", ['status' => $response->status(), 'body' => $response->body()]);
        return null;
    }
    
    private function buildNutritionalPrompt($profile): string
    {
        $allergies = !empty($profile->allergies) ? $profile->allergies : 'Ninguna declarada.';
        $disliked_foods = !empty($profile->disliked_foods) ? $profile->disliked_foods : 'Ninguno declarado.';
        $medical_condition = !empty($profile->medical_condition) ? $profile->medical_condition : 'Ninguna declarada.';
        $dietaryRestriction = '';
        $dietStyle = strtolower($profile->dietary_style ?? '');
        if ($dietStyle === 'vegano') { $dietaryRestriction = "- **REGLA DIETÉTICA ESTRICTA:** El plan DEBE ser 100% vegano..."; } 
        elseif ($dietStyle === 'vegetariano') { $dietaryRestriction = "- **REGLA DIETÉTICA ESTRICTA:** El plan DEBE ser 100% vegetariano..."; } 
        elseif ($dietStyle === 'keto / low carb') { $dietaryRestriction = "- **REGLA DIETÉTICA ESTRICTA:** El plan DEBE ser cetogénico..."; }
        $mealCountInstruction = "- El número de comidas debe ser flexible, pero basado en la preferencia del usuario: '{$profile->meal_count}'. Interpreta esto y estructura el plan en las comidas principales que correspondan (ej: 'Desayuno', 'Almuerzo', 'Cena') y si aplica, 'Snacks'.";

        return "
        Actúa como un nutricionista de élite. Tu misión es crear un plan de nutrición en formato JSON que respete TODAS las reglas.
        **REGLAS OBLIGATORIAS:**
        - El campo `targetMacros` DEBE incluir las calorías totales, y también los gramos de proteína, grasas y carbohidratos. 
        - El cálculo debe ser coherente: 1g de proteína = 4 kcal, 1g de carbohidrato = 4 kcal, 1g de grasa = 9 kcal. 
        - Asegúrate de que la suma calórica concuerde con los macronutrientes.
        - Para cada `option`, DEBES incluir una clave `portion` que estime una porción razonable en gramos, tazas, unidades, etc. (ej: \"150g\", \"1 taza\", \"1 scoop (30g)\").
        - El campo `portion` debe ser coherente con las calorías y macros del alimento.

        - Respeta las restricciones:
            {$dietaryRestriction}
            - Alergias (NO INCLUIR): {$allergies}
            - Alimentos que NO le gustan (EVITAR): {$disliked_foods}
            - Condición Médica: {$medical_condition}
            - {$mealCountInstruction} 

            
        - Tu respuesta debe ser **ÚNICAMENTE el objeto JSON**.
        
        **DATOS DEL USUARIO:**
        - Objetivo: {$profile->goal}
        - Edad: {$profile->age}, Sexo: {$profile->sex}
        - Nivel de Actividad: {$profile->activity_level}
        // ... (el resto de los datos del usuario)

        **EJEMPLO DE ESTRUCTURA JSON:**
        ```json
          {
        \"nutritionPlan\": {
            \"targetMacros\": { \"calories\": 1900, \"protein\": 150, ... },
            \"meals\": {
                \"Desayuno\": [
                    { 
                        \"title\": \"Proteínas y Carbohidratos\", 
                        \"options\": [ 
                            { 
                                \"name\": \"Pechuga de Pollo\",
                                \"portion\": \"150g\", 
                                \"calories\": 300, 
                                \"protein\": 50,
                                // ... otros campos
                            } 
                        ] 
                    }
                ]
            }
        }
    }
        ```
        ";
    }
    
    private function getBackupPlanData(): array
    {
        $backupJson = '{ "nutritionPlan": { "targetMacros": { "calories": 1900, "protein": 150, "carbs": 180, "fats": 65 }, "meals": { "Desayuno": [ { "title": "Carbohidratos y Proteína", "options": [ { "name": "Avena con Proteína en Polvo", "calories": 450, "protein": 35, "carbs": 55, "fats": 10, "imageKeyword": "ingredient.oats" } ] } ], "Almuerzo": [ { "title": "Proteína Principal", "options": [ { "name": "Pechuga de Pollo (200g)", "calories": 400, "protein": 70, "carbs": 0, "fats": 12, "imageKeyword": "ingredient.chicken_breast" } ] }, { "title": "Carbohidratos", "options": [ { "name": "Arroz Blanco (200g)", "calories": 260, "protein": 5, "carbs": 56, "fats": 1, "imageKeyword": "ingredient.white_rice" } ] } ], "Cena": [ { "title": "Proteína Ligera", "options": [ { "name": "Filete de Pescado (220g)", "calories": 350, "protein": 50, "carbs": 0, "fats": 15, "imageKeyword": "ingredient.white_fish" } ] } ] } } }';
        return json_decode($backupJson, true);
    }
}