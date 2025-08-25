<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\MealPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Jobs\EnrichPlanWithPricesJob;
use App\Jobs\GenerateRecipeImagesJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateUserPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;
    public $timeout = 400;
    public $tries = 2;

    public function __construct($userId)
    {
        $this->userId = $userId;
    }

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
            // MODIFICADO: Ahora 'generateNutritionalPlan' hace los cálculos internamente
            $planData = $this->generateNutritionalPlan($user->profile);

            if ($planData === null) {
                Log::warning('La IA no generó un plan válido. Usando plan de respaldo.', ['userId' => $user->id]);
                $planData = $this->getBackupPlanData();
            }

            // PASO 2: Generar recetas detalladas con IA
            Log::info('Paso 2: Generando recetas detalladas con IA.', ['userId' => $user->id]);
            $planWithRecipes = $this->generateAndAssignAiRecipes($planData, $user->profile);
            
            // PASO 3: Guardado del plan (sin precios)
            Log::info('Almacenando plan base en la base de datos.', ['userId' => $user->id]);
            MealPlan::where('user_id', $user->id)->update(['is_active' => false]);
            $mealPlan = MealPlan::create([
                'user_id' => $user->id,
                'plan_data' => $planWithRecipes,
                'is_active' => true,
            ]); 

            // PASO 4: Despachar los jobs de enriquecimiento
            Log::info('Despachando job para enriquecer con precios.', ['mealPlanId' => $mealPlan->id]);
            EnrichPlanWithPricesJob::dispatch($mealPlan->id);

            Log::info('Despachando job para generar imágenes.', ['mealPlanId' => $mealPlan->id]);
          //  GenerateRecipeImagesJob::dispatch($mealPlan->id)->onQueue('images');

            Log::info('Plan base generado. El enriquecimiento se ejecutará en segundo plano.', ['userId' => $user->id, 'mealPlanId' => $mealPlan->id]);

        } catch (\Exception $e) {
            Log::error('Excepción crítica en GenerateUserPlanJob', [
                'userId' => $this->userId, 'exception' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function generateAndAssignAiRecipes(array $planData, $profile): array
    {
        $mealsToSearch = array_keys($planData['nutritionPlan']['meals']);

        if (empty($mealsToSearch)) {
            return $planData;
        }

        foreach ($mealsToSearch as $mealName) {
            if (isset($planData['nutritionPlan']['meals'][$mealName])) {
                $mealComponents = $planData['nutritionPlan']['meals'][$mealName];
                
                $recipes = $this->generateThreeRecipesForMeal($mealComponents, $profile);
                
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

    
    private function generateThreeRecipesForMeal(array $mealComponents, $profile): ?array
    {
        // 1. Separar ingredientes por categoría
        $proteinOptions = [];
        $carbOptions = [];
        $fatOptions = [];

        // MODIFICADO: Estructura de componentes puede variar, asegurar compatibilidad
        $components = $mealComponents['components'] ?? $mealComponents;

        foreach ($components as $category => $component) {
            if (isset($component['options'])) {
                $optionsList = array_map(fn($opt) => $opt['name'], $component['options']);
                if (stripos($category, 'Proteína') !== false) {
                    $proteinOptions = array_merge($proteinOptions, $optionsList);
                } elseif (stripos($category, 'Carbohidrato') !== false) {
                    $carbOptions = array_merge($carbOptions, $optionsList);
                } elseif (stripos($category, 'Grasa') !== false) {
                    $fatOptions = array_merge($fatOptions, $optionsList);
                }
            }
        }

        if (empty($proteinOptions) || empty($carbOptions)) {
            Log::warning('No hay suficientes componentes para generar recetas (faltan proteínas o carbohidratos).');
            return null;
        }

        // 2. Crear strings para el prompt
        $proteinString = implode(', ', array_unique($proteinOptions));
        $carbString = implode(', ', array_unique($carbOptions));
        $fatString = !empty($fatOptions) ? implode(', ', array_unique($fatOptions)) : 'ninguna específica';

        // 3. Restricciones dietéticas y de presupuesto
        $dietaryRestriction = '';
        $dietStyle = strtolower($profile->dietary_style ?? 'omnívoro');
        
        if ($dietStyle === 'vegano') {
            $dietaryRestriction = "REGLA DIETÉTICA: La receta DEBE ser 100% vegana.";
        } elseif ($dietStyle === 'vegetariano') {
            $dietaryRestriction = "REGLA DIETÉTICA: La receta DEBE ser 100% vegetariana.";
        }

        $rawBudget = $profile->budget ?? 'Alto';
        $budgetLevel = str_contains(strtolower($rawBudget), 'bajo') ? 'bajo' : 'alto';
        $budgetInstructionForRecipe = '';
        if ($budgetLevel === 'bajo') {
            $budgetInstructionForRecipe = "REGLA DE PRESUPUESTO: La receta DEBE usar únicamente ingredientes económicos como Carne molida, Bonito, Huevo, Lentejas, Atún, Arroz, Fideos, Papa, Camote, Maní, Plátano. NO USAR Salmón, Lomo de res, Almendras, etc.";
        } else {
            $budgetInstructionForRecipe = "REGLA DE PRESUPUESTO: La receta puede usar ingredientes de mayor costo como Salmón, Lomo de res, Almendras, Quinua y Frutos rojos.";
        }

        // 4. Construir el prompt
        $prompt = "
        Actúa como un chef experto y nutricionista. Tu misión es crear TRES recetas saludables y DELICIOSAS que sean notablemente DIFERENTES entre sí.

        **REGLAS FUNDAMENTALES:**
        1.  Cada receta que crees DEBE ser una combinación de **SOLO UN** ingrediente de la lista de Proteínas, **SOLO UN** ingrediente de la lista de Carbohidratos y **SOLO UN** ingrediente (si aplica) de la lista de Grasas. No puedes mezclar dos proteínas o dos carbohidratos en la misma receta.
        2.  **REGLA DE CANTIDADES (CRÍTICA E INELUDIBLE):** En el array `extendedIngredients`, para CUALQUIER ingrediente que no sea una fruta fresca, verdura de hoja, aceite o condimento simple (sal, pimienta), el campo `original` DEBE especificar si la cantidad es en **crudo, seco o cocido**. Es un error grave omitir esto. Aplica esta regla a carnes, pescados, granos (arroz, avena, quinua), legumbres (lentejas), tubérculos (papa, camote), harinas y panes.
        3.  **{$budgetInstructionForRecipe}**

        Aquí están tus opciones de ingredientes:
        - **Lista de Proteínas (escoge solo una por receta):** {$proteinString}
        - **Lista de Carbohidratos (escoge solo una por receta):** {$carbString}
        - **Lista de Grasas (escoge solo una por receta):** {$fatString}

        Puedes añadir condimentos comunes y una pequeña cantidad de vegetales de libre consumo (cebolla, ajo, tomate) para dar sabor.

        {$dietaryRestriction}

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
            {\"name\": \"pechuga de pollo\", \"original\": \"150g (peso en crudo)\"},
            {\"name\": \"arroz blanco\", \"original\": \"80g (peso en crudo)\"},
            {\"name\": \"pan integral\", \"original\": \"2 rebanadas (60g)\"}
        ]
        }
        Asegúrate de que los TRES objetos de receta en el array sean distintos para dar variedad. La respuesta debe ser solo el JSON y en español.";

        $response = Http::withToken(env('OPENAI_API_KEY'))->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.7,
        ]);

        if ($response->successful()) {
            $data = json_decode($response->json('choices.0.message.content'), true);
            
            if (json_last_error() === JSON_ERROR_NONE && isset($data['recipes']) && is_array($data['recipes'])) {
                $processedRecipes = [];
                foreach ($data['recipes'] as $recipeData) {
                    $recipeData['image'] = null;
                    $recipeData['analyzedInstructions'] = [];
                    $processedRecipes[] = $recipeData;
                }
                return $processedRecipes;
            } else {
                Log::warning("La IA no devolvió las recetas como se esperaba.", ['response' => $data]);
            }
        }

        Log::error("Fallo al generar 3 recetas detalladas con IA", ['status' => $response->status(), 'body' => $response->body()]);
        return null;
    }

    // MODIFICADO: Función principal para orquestar el cálculo y la llamada a la IA
    private function generateNutritionalPlan($profile): ?array
    {
        // 1. Calcular los macros objetivos basados en los datos del usuario.
        Log::info('Calculando macros para el usuario.', ['userId' => $profile->user_id]);
        $targetMacros = $this->calculateTargetMacros($profile);

        // 2. Construir el prompt con los macros ya definidos.
        $prompt = $this->buildNutritionalPrompt($profile, $targetMacros);
        
        // 3. Llamar a la IA con el prompt mejorado.
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

    private function calculateTargetMacros($profile): array
    {
        // 1. Recopilar datos del perfil.
        $weight = (float)$profile->weight;
        $height = (float)$profile->height;
        $age = (int)$profile->age;
        $sex = strtolower($profile->sex);
        $weeklyActivity = $profile->weekly_activity; // Campo clave de la BD
        $goal = $profile->goal; // El valor exacto de la BD, ej: 'Bajar grasa'
        $dietStyle = strtolower($profile->dietary_style ?? 'omnívoro');

        // 2. Cálculo de la Tasa Metabólica Basal (TMB) usando Harris-Benedict.
        $bmr = 0;
        if ($sex === 'masculino') {
            // TMB = 66.473 + (13.751 * peso en kg) + (5.003 * altura en cm) - (6.755 * edad en años)
            $bmr = 66.473 + (13.751 * $weight) + (5.003 * $height) - (6.755 * $age);
        } else { // Femenino
            // TMB = 655.0955 + (9.463 * peso en kg) + (1.8496 * altura en cm) - (4.6756 * edad en años)
            $bmr = 655.0955 + (9.463 * $weight) + (1.8496 * $height) - (4.6756 * $age);
        }

        // 3. Determinar el Factor de Actividad (FA) basado en 'weekly_activity'.
        $multiplier = 1.37; // Valor por defecto (ligero)
        
        if (str_contains($weeklyActivity, 'No me muevo y no entreno')) {
            $multiplier = 1.20;
        } elseif (str_contains($weeklyActivity, 'Oficina + entreno 1-2 veces')) {
            $multiplier = 1.37;
        } elseif (str_contains($weeklyActivity, 'Oficina + entreno 3-4 veces')) {
            $multiplier = 1.45;
        } elseif (str_contains($weeklyActivity, 'Oficina + entreno 5-6 veces')) {
            $multiplier = 1.55;
        } elseif (str_contains($weeklyActivity, 'Trabajo activo + entreno 1-2 veces')) {
            $multiplier = 1.55;
        } elseif (str_contains($weeklyActivity, 'Trabajo activo + entreno 3-4 veces')) {
            $multiplier = 1.72;
        } elseif (str_contains($weeklyActivity, 'Trabajo muy físico + entreno 5-6 veces')) {
            $multiplier = 1.90;
        }

        // Gasto Energético Total (GET)
        $tdee = $bmr * $multiplier;

        // 4. Ajustar calorías según el objetivo (lógica del primer mes).
        $targetCalories = $tdee;
        
        if ($goal === 'Bajar grasa') {
            // Déficit calórico del 20% para el primer mes
            $targetCalories = $tdee * 0.80; 
        } elseif ($goal === 'Aumentar músculo') {
            // Superávit calórico del 10% para el primer mes
            $targetCalories = $tdee * 1.10; 
        }
        // Para "Mantenimiento", "comer más saludable" o "mejorar rendimiento", las calorías son iguales al GET.

        // 5. Calcular Proteínas (2.2 g/kg, dentro del rango 1.6-2.2 g/kg)
        $proteinGrams = $weight * 2.2;
        $proteinCalories = $proteinGrams * 4;

        // 6. Calcular Grasas y Carbohidratos.
        $fatCalories = $targetCalories * 0.25; // 25% de grasas, dentro del rango 20-35%
        $fatGrams = $fatCalories / 9;

        $carbCalories = $targetCalories - $proteinCalories - $fatCalories;
        $carbGrams = $carbCalories / 4; // Carbohidratos son el resto

        // 7. Devolver los macros finales y redondeados.
        return [
            'calories' => round($targetCalories),
            'protein' => round($proteinGrams),
            'carbohydrates' => round($carbGrams),
            'fats' => round($fatGrams),
        ];
    }
    
    // MODIFICADO: El prompt ahora recibe los macros y los inyecta como una orden.
    private function buildNutritionalPrompt($profile, array $targetMacros): string
    {
        // --- 1. Recopilación de Datos del Perfil ---
        $allergies = !empty($profile->allergies) ? $profile->allergies : 'Ninguna declarada.';
        $disliked_foods = !empty($profile->disliked_foods) ? $profile->disliked_foods : 'Ninguno declarado.';
        $medical_condition = !empty($profile->medical_condition) ? $profile->medical_condition : 'Ninguna declarada.';
        $userCountry = $profile->pais ?? 'desconocido';
        $dietStyle = strtolower($profile->dietary_style ?? 'omnívoro');
        $goal = strtolower($profile->goal ?? '');
        
        // --- 2. Lógica para el Presupuesto ---
        $rawBudget = $profile->budget ?? 'Medio'; // El texto completo que envía el front
    $budgetLevel = 'medio'; // Valor por defecto

    if (str_contains(strtolower($rawBudget), 'bajo')) {
        $budgetLevel = 'bajo';
    } elseif (str_contains(strtolower($rawBudget), 'alto')) {
        $budgetLevel = 'alto';
    }

        $budgetInstruction = '';
        switch ($budgetLevel) {
            case 'bajo':
            $budgetInstruction = "- **PRESUPUESTO BAJO:** Prioriza estrictamente: Proteínas (huevo entero, atún en lata, legumbres, muslos de pollo), Carbohidratos (arroz blanco, avena, papa, pasta), Grasas (aceite de oliva). **PROHIBIDO USAR: salmón, lomo de res, proteína en polvo, quesos caros, frutos secos exóticos, yogur griego.**";
                break;
            case 'alto':
            $budgetInstruction = "- **PRESUPUESTO ALTO:** Puedes incluir libremente: Proteínas (salmón, lomo de res, proteína en polvo, pechuga de pollo), Carbohidratos (quinua, pan artesanal, batata, arroz blanco, camote), Grasas (aceite de aguacate, almendras, nueces de macadamia, aceite de oliva extra virgen, palta).";
            break;
            default:
            $budgetInstruction = '';
            break;
        }

        // --- 3. Creación de Instrucciones Específicas ---
        $dietaryRestriction = '';
        if ($dietStyle === 'vegano') {
            $dietaryRestriction = "- **REGLA DIETÉTICA:** El plan debe ser 100% vegano.";
        } elseif ($dietStyle === 'vegetariano') {
            $dietaryRestriction = "- **REGLA DIETÉTICA:** El plan debe ser 100% vegetariano.";
            } elseif ($dietStyle === 'keto / low carb') {
                $dietaryRestriction = "- **REGLA DIETÉTICA:** El plan debe ser estrictamente KETO / BAJO EN CARBOHIDRATOS.";
            }
        
            $goalInstruction = '';
            if (str_contains($goal, 'aumentar músculo')) {
                $goalInstruction = "- **ENFOQUE DEL PLAN:** AUMENTAR MÚSCULO (superávit calórico, alta en proteínas).";
            } elseif (str_contains($goal, 'bajar grasa')) {
                $goalInstruction = "- **ENFOQUE DEL PLAN:** BAJAR GRASA (déficit calórico, alta en proteínas).";
            } elseif (str_contains($goal, 'mejorar rendimiento')) {
                $goalInstruction = "- **ENFOQUE DEL PLAN:** MEJORAR RENDIMIENTO (suficiente energía con carbohidratos complejos).";
            } elseif (str_contains($goal, 'comer más saludable')) {
                $goalInstruction = "- **ENFOQUE DEL PLAN:** COMER MÁS SALUDABLE (calorías de mantenimiento, alimentos integrales).";
            }
        
        $mealCountInstruction = "- **REGLA ESTRUCTURAL:** El plan debe contener estas comidas: '{$profile->meal_count}'.";
        
        // --- 4. Construcción del Prompt Final ---
        return "
        Actúa como un nutricionista de élite experto en crear un plan de alimentación FLEXIBLE por INTERCAMBIOS.
        Tu respuesta debe ser **ÚNICAMENTE el objeto JSON**, sin explicaciones.
        
        **DATOS COMPLETOS DEL USUARIO:**
        - **País:** {$userCountry}
        - **Datos Biométricos:** {$profile->age} años, {$profile->sex}, {$profile->height} cm, {$profile->weight} kg.
        - **Nivel de Actividad:** {$profile->activity_level}
        - **Frecuencia de Entrenamiento:** {$profile->training_frequency}
        - **Objetivo Principal:** {$goal}
        
        **REGLAS MAESTRAS (OBLIGATORIAS):**
            1. **USA LOS MACROS CALCULADOS (CRÍTICO E INELUDIBLE):** El plan que generes DEBE apuntar a los macros totales que te proporciono a continuación. Tu JSON final, en la clave 'targetMacros', DEBE reflejar estos números que yo te he calculado. Es el requisito más importante.
            2.  **RECOMENDACIÓN PERSONALIZADA:** Al inicio del plan, DEBES añadir una clave 'recommendation' con un texto breve (4-5 líneas) y motivador, explicando por qué este plan es ideal para el usuario, mencionando su objetivo principal (ej. 'bajar grasa', 'aumentar músculo').
            3.  **REGLA DE PRESUPUESTO CRÍTICA:** La selección de alimentos DEBE basarse ESTRICTAMENTE en el nivel de presupuesto del usuario. Usa los ejemplos proporcionados como tu guía principal. Si el presupuesto es 'bajo', no puedes incluir NINGÚN alimento de la categoría 'alto'.
            4. **NÚMERO DE OPCIONES (MUY IMPORTANTE):** Para cada grupo (Proteínas, Carbohidratos, Grasas), DEBES proporcionar el siguiente número de opciones: TRES para Proteínas, TRES para Carbohidratos y DOS para Grasas.
            5.  **SÉ EXTREMADAMENTE ESPECÍFICO Y USA ALIMENTOS BÁSICOS:** Nunca uses términos genéricos. Si sugieres 'cereal', debe ser 'copos de avena sin azúcar'. Usa únicamente alimentos comunes y accesibles para el país del usuario, evitando ingredientes gourmet o raros.
            6.  **ESPECIFICA CRUDO O COCIDO:** Para alimentos que cambian de peso al cocinarse (avena, arroz, carne, etc.), DEBES especificar si el peso es en CRUDO/SECO o COCIDO. Ejemplo: `\"portion\": \"150g de pollo (peso en crudo)\"`.
            7.  **EQUIVALENCIA NUTRICIONAL ESTRICTA:** Las 'options' intercambiables dentro de un mismo grupo DEBEN ser nutricionalmente casi idénticas (diferencia máx. 15 kcal y 3-4g de proteína). Tu principal tarea es ajustar la porción para lograr esta equivalencia.
            8.  **USA LENGUAJE COMÚN:** Describe los alimentos con sus nombres más comunes en el país del usuario. En lugar de 'copos de avena sin azúcar', usa 'Avena tradicional (hojuelas)'. En lugar de 'filete de res magro', usa 'Bistec de res'.
            9.  **AGRUPACIÓN SIMPLE:** Solo agrupa alimentos casi idénticos (ej. 'Pollo o Pavo'). No agrupes alimentos distintos como 'Papa o Lentejas' en la misma opción.
            10.  Para cada comida, crea claves para los GRUPOS DE ALIMENTOS (`Proteínas`, `Carbohidratos`, `Grasas`, etc.) y dentro, un array `options`.
            11.  Para vegetales, usa una clave `Vegetales` con una opción `Ensalada LIBRE`.
        
            **DATOS Y RESTRICCIONES DEL USUARIO:**
            - **País del Usuario:** {$userCountry}
        - {$budgetInstruction}
        - {$goalInstruction}
        - {$dietaryRestriction}
        - {$mealCountInstruction}
        - **Alergias (NO INCLUIR):** {$allergies}
        - **Alimentos que NO le gustan (EVITAR):** {$disliked_foods}
        - **Condición Médica a considerar:** {$medical_condition}
        
        **EJEMPLO DE ESTRUCTURA JSON (SÍGUELA ESTRICTAMENTE):**
        ```json
        {
          \"nutritionPlan\": {
            \"recommendation\": \"Este plan está diseñado para ayudarte a bajar grasa de forma sostenible. Nos enfocamos en comidas altas en proteína para mantenerte satisfecho y con energía, respetando tu presupuesto. etc\",
            \"targetMacros\": { \"calories\": {$targetMacros['calories']}, \"protein\": {$targetMacros['protein']}, \"fats\": {$targetMacros['fats']}, \"carbohydrates\": {$targetMacros['carbohydrates']} },
            \"meals\": {
              \"Almuerzo\": {
                \"Proteínas\": {
                  \"options\": [
                    { \"name\": \"Pechuga de Pollo o Pavo\", \"portion\": \"200g (peso en crudo)\", \"calories\": 240, \"protein\": 45, \"fats\": 5, \"carbohydrates\": 0 },
                    { \"name\": \"Filete de Tilapia\", \"portion\": \"230g (peso en crudo)\", \"calories\": 245, \"protein\": 46, \"fats\": 6, \"carbohydrates\": 0 }
                  ]
                },
                \"Carbohidratos\": {
                  \"options\": [
                    { \"name\": \"Arroz blanco\", \"portion\": \"80g (peso en crudo)\", \"calories\": 280, \"protein\": 8, \"fats\": 2, \"carbohydrates\": 55 },
                    { \"name\": \"Papa (patata)\", \"portion\": \"350g (peso en crudo)\", \"calories\": 275, \"protein\": 7, \"fats\": 1, \"carbohydrates\": 56 }
                  ]
                }
              }
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