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

                // PASO 4: Despachar el job para enriquecer con precios
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

        private function generateAndAssignAiRecipes(array $planData, $profile): array
        {
            $mealsToSearch = array_keys($planData['nutritionPlan']['meals']);

            if (empty($mealsToSearch)) {
                return $planData;
            }

            foreach ($mealsToSearch as $mealName) {
                if (isset($planData['nutritionPlan']['meals'][$mealName])) {
                    $mealComponents = $planData['nutritionPlan']['meals'][$mealName];
                    
                    // Pasamos el perfil del usuario para conocer su estilo de dieta
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
            $ingredientList = [];
            foreach ($mealComponents as $component) {
                if (isset($component['options'])) {
                    foreach($component['options'] as $option) {
                        $ingredientList[] = $option['name'];
                    }
                }
            }

            if (empty($ingredientList)) {
                return null;
            }

            $ingredientsString = implode(' y ', $ingredientList);
            
            // ▼▼▼ LÓGICA DE RESTRICCIÓN DIETÉTICA AÑADIDA ▼▼▼
            $dietaryRestriction = '';
        $dietStyle = strtolower($profile->dietary_style ?? 'omnívoro');
        
        if ($dietStyle === 'vegano') {
            $dietaryRestriction = "REGLA CRÍTICA: La receta DEBE ser 100% vegana.";
        } elseif ($dietStyle === 'vegetariano') {
            $dietaryRestriction = "REGLA CRÍTICA: La receta DEBE ser 100% vegetariana.";
        } elseif ($dietStyle === 'keto / low carb') {
            $dietaryRestriction = "REGLA CRÍTICA: La receta DEBE ser estrictamente KETO / BAJA EN CARBOHIDRATOS. No incluyas granos (quinoa, arroz, pan, tortillas), frutas altas en azúcar (mango, plátano) ni azúcares (miel).";
        }

            $prompt = "Actúa como un chef experto y nutricionista. Tu misión es crear TRES recetas saludables y DELICIOSAS que sean notablemente DIFERENTES entre sí, usando estos ingredientes base: {$ingredientsString}.
            
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
                {\"name\": \"ingrediente1\", \"original\": \"cantidad y unidad\"}
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
            // --- 1. Recopilación de Datos del Perfil ---
            $allergies = !empty($profile->allergies) ? $profile->allergies : 'Ninguna declarada.';
            $disliked_foods = !empty($profile->disliked_foods) ? $profile->disliked_foods : 'Ninguno declarado.';
            $medical_condition = !empty($profile->medical_condition) ? $profile->medical_condition : 'Ninguna declarada.';
            $userCountry = $profile->pais ?? 'desconocido';
            $dietStyle = strtolower($profile->dietary_style ?? 'omnívoro');
            $goal = strtolower($profile->goal ?? '');
        
            // --- 2. Creación de Instrucciones Específicas ---
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
        
            // --- 3. Construcción del Prompt Final ---
            return "
            Actúa como un nutricionista de élite experto en crear un plan de alimentación FLEXIBLE por INTERCAMBIOS.
            Tu respuesta debe ser **ÚNICAMENTE el objeto JSON**, sin explicaciones.
        
            **REGLAS MAESTRAS (OBLIGATORIAS):**
            1.  **SÉ EXTREMADAMENTE ESPECÍFICO Y USA ALIMENTOS BÁSICOS:** Nunca uses términos genéricos. Si sugieres 'cereal', debe ser 'copos de avena sin azúcar'. Usa únicamente alimentos comunes y accesibles para el país del usuario, evitando ingredientes gourmet o raros.
            2.  **ESPECIFICA CRUDO O COCIDO:** Para alimentos que cambian de peso al cocinarse (avena, arroz, carne, etc.), DEBES especificar si el peso es en CRUDO/SECO o COCIDO. Ejemplo: `\"portion\": \"150g de pollo (peso en crudo)\"`.
            3.  **EQUIVALENCIA NUTRICIONAL ESTRICTA:** Las 'options' intercambiables dentro de un mismo grupo DEBEN ser nutricionalmente casi idénticas (diferencia máx. 15 kcal y 3-4g de proteína). Tu principal tarea es ajustar la porción para lograr esta equivalencia.
            4.  **USA LENGUAJE COMÚN:** Describe los alimentos con sus nombres más comunes en el país del usuario. En lugar de 'copos de avena sin azúcar', usa 'Avena tradicional (hojuelas)'. En lugar de 'filete de res magro', usa 'Bistec de res'.
            5.  **AGRUPACIÓN SIMPLE:** Solo agrupa alimentos casi idénticos (ej. 'Pollo o Pavo'). No agrupes alimentos distintos como 'Papa o Lentejas' en la misma opción.
            6.  Para cada comida, crea claves para los GRUPOS DE ALIMENTOS (`Proteínas`, `Carbohidratos`, `Grasas`, etc.) y dentro, un array `options`.
            7.  Para vegetales, usa una clave `Vegetales` con una opción `Ensalada LIBRE`.
        
            **DATOS Y RESTRICCIONES DEL USUARIO:**
            - **País del Usuario:** {$userCountry}
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
                \"targetMacros\": { \"calories\": 2200, \"protein\": 180, \"fats\": 70, \"carbohydrates\": 210 },
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
                    },
                    \"Grasas\": {
                      \"options\": [
                        { \"name\": \"Aguacate\", \"portion\": \"100g\", \"calories\": 160, \"protein\": 2, \"fats\": 15, \"carbohydrates\": 9 }
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