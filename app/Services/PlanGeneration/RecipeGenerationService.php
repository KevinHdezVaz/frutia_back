<?php

namespace App\Services\PlanGeneration;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class RecipeGenerationService
{
    public function generatePersonalizedRecipes(array $planData, $profile, $nutritionalData): array
    {
        $allMeals = array_keys($planData['nutritionPlan']['meals'] ?? []);
        $mealsToSearch = array_filter($allMeals, function ($mealName) {
            return !str_contains(strtolower($mealName), 'snack de frutas') &&
                !str_contains(strtolower($mealName), 'fruta');
        });

        if (empty($mealsToSearch)) {
            return $planData;
        }

        $profileData = [
            'name' => $nutritionalData['basic_data']['preferences']['preferred_name'] ?? $nutritionalData['basic_data']['preferences']['name'] ?? 'Usuario',
            'goal' => $nutritionalData['basic_data']['goal'] ?? '',
            'weight' => $nutritionalData['basic_data']['weight'] ?? 0,
            'height' => $nutritionalData['basic_data']['height'] ?? 0,
            'age' => $nutritionalData['basic_data']['age'] ?? 0,
            'sex' => $nutritionalData['basic_data']['sex'] ?? '',
            'dietary_style' => $nutritionalData['basic_data']['preferences']['dietary_style'] ?? 'Omnívoro',
            'budget' => $nutritionalData['basic_data']['preferences']['budget'] ?? '',
            'disliked_foods' => $nutritionalData['basic_data']['preferences']['disliked_foods'] ?? '',
            'allergies' => $nutritionalData['basic_data']['health_status']['allergies'] ?? '',
            'has_allergies' => $nutritionalData['basic_data']['health_status']['has_allergies'] ?? false,
            'medical_condition' => $nutritionalData['basic_data']['health_status']['medical_condition'] ?? '',
            'has_medical_condition' => $nutritionalData['basic_data']['health_status']['has_medical_condition'] ?? false,
            'country' => $nutritionalData['basic_data']['country'] ?? 'Mexico',
            'sports' => $nutritionalData['basic_data']['sports_data']['sports'] ?? [],
            'training_frequency' => $nutritionalData['basic_data']['sports_data']['training_frequency'] ?? '',
            'weekly_activity' => $nutritionalData['basic_data']['activity_level'] ?? '',
            'communication_style' => $nutritionalData['basic_data']['preferences']['communication_style'] ?? '',
            'eats_out' => $nutritionalData['basic_data']['preferences']['eats_out'] ?? '',
            'meal_count' => $nutritionalData['basic_data']['preferences']['meal_count'] ?? '',
            'diet_difficulties' => $nutritionalData['basic_data']['emotional_profile']['diet_difficulties'] ?? [],
            'diet_motivations' => $nutritionalData['basic_data']['emotional_profile']['diet_motivations'] ?? [],
            'meal_times' => $nutritionalData['basic_data']['meal_times'] ?? [
                'breakfast_time' => '07:00',
                'lunch_time' => '13:00',
                'dinner_time' => '20:00'
            ],
            'bmi' => $nutritionalData['basic_data']['anthropometric_data']['bmi'] ?? 0,
            'weight_status' => $nutritionalData['basic_data']['anthropometric_data']['weight_status'] ?? '',
            'target_calories' => $nutritionalData['target_calories'] ?? 0,
            'target_protein' => $nutritionalData['macros']['protein']['grams'] ?? 0,
            'target_carbs' => $nutritionalData['macros']['carbohydrates']['grams'] ?? 0,
            'target_fats' => $nutritionalData['macros']['fats']['grams'] ?? 0
        ];

        foreach ($mealsToSearch as $mealName) {
            if (isset($planData['nutritionPlan']['meals'][$mealName])) {
                $mealComponents = $planData['nutritionPlan']['meals'][$mealName];

                $mealPercentages = [
                    'Desayuno' => 0.30,
                    'Almuerzo' => 0.40,
                    'Cena' => 0.30
                ];

                $mealPercentage = $mealPercentages[$mealName] ?? 0.33;
                $profileData['meal_target_protein'] = round($profileData['target_protein'] * $mealPercentage);
                $profileData['meal_target_carbs'] = round($profileData['target_carbs'] * $mealPercentage);
                $profileData['meal_target_fats'] = round($profileData['target_fats'] * $mealPercentage);
                $profileData['meal_target_calories'] = round($profileData['target_calories'] * $mealPercentage);

                $recipes = $this->generateUltraPersonalizedRecipesForMeal(
                    $mealComponents,
                    $profileData,
                    $nutritionalData,
                    $mealName
                );

                if (!empty($recipes)) {
                    $validRecipes = [];
                    foreach ($recipes as $recipe) {
                        if ($this->validateRecipeIngredients($recipe, $profileData)) {
                            $validRecipes[] = $recipe;
                        } else {
                            Log::warning("Receta rechazada por contener ingredientes prohibidos", [
                                'recipe' => $recipe['name'] ?? 'Sin nombre',
                                'meal' => $mealName
                            ]);
                        }
                    }

                    if (!empty($validRecipes)) {
                        $planData['nutritionPlan']['meals'][$mealName]['suggested_recipes'] = $validRecipes;
                        $planData['nutritionPlan']['meals'][$mealName]['meal_timing'] = $this->getMealTiming($mealName, $profileData['meal_times']);
                        $planData['nutritionPlan']['meals'][$mealName]['personalized_tips'] = $this->getMealSpecificTips($mealName, $profileData);

                        Log::info(count($validRecipes) . " recetas ultra-personalizadas validadas para {$mealName}.");
                    }
                }
            }
        }

        return $planData;
    }

    public function addTrialMessage(array $planData, string $userName): array
    {
        if (isset($planData['nutritionPlan']['meals'])) {
            foreach ($planData['nutritionPlan']['meals'] as $mealName => &$mealData) {
                $mealData['trial_message'] = [
                    'title' => 'Recetas Personalizadas',
                    'message' => "¡Hola {$userName}! Las recetas personalizadas están disponibles con la suscripción completa.",
                    'upgrade_hint' => 'Activa tu suscripción para acceder a recetas paso a paso.'
                ];
            }
        }

        return $planData;
    }

    private function generateUltraPersonalizedRecipesForMeal(array $mealComponents, array $profileData, $nutritionalData, $mealName): ?array
    {
        $proteinOptions = [];
        $carbOptions = [];
        $fatOptions = [];

        if (isset($mealComponents['Proteínas']['options'])) {
            $proteinOptions = array_map(fn($opt) => $opt['name'] . ' (' . $opt['portion'] . ')', $mealComponents['Proteínas']['options']);
        }
        if (isset($mealComponents['Carbohidratos']['options'])) {
            $carbOptions = array_map(fn($opt) => $opt['name'] . ' (' . $opt['portion'] . ')', $mealComponents['Carbohidratos']['options']);
        }
        if (isset($mealComponents['Grasas']['options'])) {
            $fatOptions = array_map(fn($opt) => $opt['name'] . ' (' . $opt['portion'] . ')', $mealComponents['Grasas']['options']);
        }

        if (empty($proteinOptions)) {
            $budget = strtolower($profileData['budget']);
            if (str_contains($budget, 'bajo')) {
                $proteinOptions = ['Huevo entero', 'Pollo muslo', 'Atún en lata', 'Frijoles'];
            } else {
                $proteinOptions = ['Pechuga de pollo', 'Salmón', 'Claras + Huevo Entero', 'Yogurt griego'];
            }
        }

        if (empty($carbOptions)) {
            $dietStyle = strtolower($profileData['dietary_style']);
            if (str_contains($dietStyle, 'keto')) {
                $carbOptions = ['Vegetales verdes', 'Coliflor', 'Brócoli', 'Espinacas'];
            } else {
                $carbOptions = ['Arroz', 'Quinua', 'Papa', 'Avena', 'Pan integral'];
            }
        }

        if (empty($fatOptions)) {
            $budget = strtolower($profileData['budget']);
            if (str_contains($budget, 'bajo')) {
                $fatOptions = ['Aceite vegetal', 'Maní', 'Aguacate pequeño'];
            } else {
                $fatOptions = ['Aceite de oliva extra virgen', 'Almendras', 'Aguacate hass', 'Nueces'];
            }
        }

        $proteinString = implode(', ', array_unique($proteinOptions));
        $carbString = implode(', ', array_unique($carbOptions));
        $fatString = implode(', ', array_unique($fatOptions));

        $dislikedFoodsList = !empty($profileData['disliked_foods'])
            ? array_map('trim', explode(',', $profileData['disliked_foods']))
            : [];

        $allergiesList = !empty($profileData['allergies'])
            ? array_map('trim', explode(',', $profileData['allergies']))
            : [];

        $isSnack = str_contains(strtolower($mealName), 'snack');

        $needsPortable = str_contains(strtolower($profileData['eats_out']), 'casi todos') ||
            str_contains(strtolower($profileData['eats_out']), 'veces');

        $needsQuick = in_array('Preparar la comida', $profileData['diet_difficulties']) ||
            in_array('No tengo tiempo para cocinar', $profileData['diet_difficulties']);

        $needsAlternatives = in_array('Saber qué comer cuando no tengo lo del plan', $profileData['diet_difficulties']);

        $communicationTone = '';
        if (str_contains(strtolower($profileData['communication_style']), 'motivadora')) {
            $communicationTone = "Usa un tono MOTIVADOR y ENERGÉTICO: '¡Vamos {$profileData['name']}!', '¡Esta receta te llevará al siguiente nivel!'";
        } elseif (str_contains(strtolower($profileData['communication_style']), 'directa')) {
            $communicationTone = "Usa un tono DIRECTO y CLARO: Sin rodeos, instrucciones precisas, datos concretos.";
        } elseif (str_contains(strtolower($profileData['communication_style']), 'cercana')) {
            $communicationTone = "Usa un tono CERCANO y AMIGABLE: Como un amigo cocinando contigo.";
        }

        $snackRules = '';
        if ($isSnack) {
            $snackRules = "

    🍎 **REGLAS CRÍTICAS PARA SNACKS - OBLIGATORIO CUMPLIR:**
    ⚠️ ESTE ES UN SNACK, NO UNA COMIDA COMPLETA

    **INGREDIENTES PROHIBIDOS EN SNACKS:**
    - ❌ NUNCA usar: Carnes (pollo, res, cerdo, pescado)
    - ❌ NUNCA usar: Preparaciones que requieran cocción compleja
    - ❌ NUNCA usar: Más de 5 ingredientes

    **INGREDIENTES PERMITIDOS EN SNACKS:**
    - ✅ Yogurt griego / Proteína en polvo / Caseína
    - ✅ Frutas frescas (plátano, manzana, fresas, mango)
    - ✅ Cereales (avena, granola, galletas de arroz)
    - ✅ Frutos secos (almendras, nueces, maní)
    - ✅ Mantequilla de maní / Miel / Chocolate negro

    **CARACTERÍSTICAS OBLIGATORIAS:**
    - Preparación: MÁXIMO 10 minutos
    - Ingredientes: MÁXIMO 5 ingredientes
    - Debe ser 100% PORTABLE (para llevar al trabajo)
    - Sin cocción o cocción mínima (licuadora/microondas)
    - Calorías: EXACTAMENTE {$profileData['meal_target_calories']} kcal (no más de 220)

    **EJEMPLOS DE SNACKS CORRECTOS:**
    ✅ Yogurt griego + granola + fresas + miel
    ✅ Licuado de proteína + plátano + mantequilla de maní
    ✅ Avena con leche + arándanos + almendras
    ✅ Galletas de arroz + queso cottage + frutas

    **EJEMPLOS DE RECETAS PROHIBIDAS PARA SNACKS:**
    ❌ Tacos de pollo (es comida completa)
    ❌ Ensalada con salmón (es comida completa)
    ❌ Bowl con carne (es comida completa)
    ";
        }

        $prompt = "
    Eres el chef y nutricionista personal de {$profileData['name']} desde hace años. Conoces PERFECTAMENTE todos sus gustos, rutinas y necesidades.

    🔴 **RESTRICCIONES ABSOLUTAS - NUNCA VIOLAR:**
    " . (!empty($dislikedFoodsList) ?
                "- PROHIBIDO usar estos alimentos que NO le gustan: " . implode(', ', $dislikedFoodsList) :
                "- No hay alimentos que evitar por preferencia") . "
    " . (!empty($allergiesList) ?
                "- ALERGIAS MORTALES (NUNCA incluir): " . implode(', ', $allergiesList) :
                "- No hay alergias reportadas") . "
    " . (!empty($profileData['medical_condition']) ?
                "- Condición médica a considerar: {$profileData['medical_condition']}" :
                "- No hay condiciones médicas especiales") . "

    📊 **PERFIL COMPLETO DE {$profileData['name']}:**
    - Edad: {$profileData['age']} años, Sexo: {$profileData['sex']}
    - Peso: {$profileData['weight']}kg, Altura: {$profileData['height']}cm, BMI: " . round($profileData['bmi'], 1) . "
    - Estado físico: {$profileData['weight_status']}
    - País: {$profileData['country']} (usa ingredientes locales disponibles)
    - Objetivo principal: {$profileData['goal']}
    - Actividad semanal: {$profileData['weekly_activity']}
    - Deportes que practica: " . (!empty($profileData['sports']) ? implode(', ', $profileData['sports']) : 'Ninguno específico') . "
    - Estilo dietético: {$profileData['dietary_style']}
    - Presupuesto: {$profileData['budget']}
    - Come fuera: {$profileData['eats_out']}
    - Estructura de comidas: {$profileData['meal_count']}
    - Hora específica del {$mealName}: " . $this->getMealTiming($mealName, $profileData['meal_times']) . "

    🎯 **OBJETIVOS NUTRICIONALES PARA ESTE {$mealName}:**
    - Calorías objetivo: {$profileData['meal_target_calories']} kcal
    - Proteínas objetivo: {$profileData['meal_target_protein']}g
    - Carbohidratos objetivo: {$profileData['meal_target_carbs']}g
    - Grasas objetivo: {$profileData['meal_target_fats']}g

    💪 **DIFICULTADES ESPECÍFICAS A RESOLVER:**
    " . (!empty($profileData['diet_difficulties']) ?
                implode("\n", array_map(fn($d) => "- {$d} → Propón solución específica", $profileData['diet_difficulties'])) :
                "- No hay dificultades específicas reportadas") . "

    🌟 **MOTIVACIONES A REFORZAR:**
    " . (!empty($profileData['diet_motivations']) ?
                implode("\n", array_map(fn($m) => "- {$m} → Conecta la receta con esta motivación", $profileData['diet_motivations'])) :
                "- Motivación general de salud") . "

    🛒 **INGREDIENTES BASE DISPONIBLES PARA {$profileData['name']}:**
    - Proteínas: {$proteinString}
    - Carbohidratos: {$carbString}
    - Grasas: {$fatString}

    {$snackRules}

    📋 **REGLAS ESPECIALES DE GENERACIÓN:**
    " . ($needsPortable ? "- INCLUYE al menos 1 receta PORTABLE para llevar al trabajo/comer fuera" : "") . "
    " . ($needsQuick ? "- Las recetas deben ser RÁPIDAS (máximo 20 minutos)" : "") . "
    " . ($needsAlternatives ? "- DA ALTERNATIVAS para cada ingrediente principal" : "") . "
    " . (str_contains(strtolower($profileData['dietary_style']), 'keto') ?
                "- KETO ESTRICTO: Máximo 5g carbohidratos netos por receta" : "") . "
    " . (str_contains(strtolower($profileData['dietary_style']), 'vegano') ?
                "- VEGANO: Solo ingredientes de origen vegetal" : "") . "
    " . (str_contains(strtolower($profileData['dietary_style']), 'vegetariano') ?
                "- VEGETARIANO: Sin carne ni pescado" : "") . "

    {$communicationTone}

    **ESTRUCTURA JSON OBLIGATORIA:**
    Genera EXACTAMENTE 3 recetas DIFERENTES y CREATIVAS que {$profileData['name']} amaría:
    {
      \"recipes\": [
        {
          \"name\": \"Nombre creativo en español, auténtico de {$profileData['country']}\",
          \"personalizedNote\": \"Nota PERSONAL para {$profileData['name']} explicando por qué esta receta es PERFECTA para él/ella, mencionando su objetivo de '{$profileData['goal']}' y sus motivaciones\",
          \"instructions\": \"Paso 1: [Instrucción clara y específica]\\nPaso 2: [Siguiente paso]\\nPaso 3: [Finalización]\\nTip personal: [Consejo específico para {$profileData['name']}]\",
          \"readyInMinutes\": " . ($isSnack ? "10" : "20") . ",
          \"servings\": 1,
          \"calories\": {$profileData['meal_target_calories']},
          \"protein\": {$profileData['meal_target_protein']},
          \"carbs\": {$profileData['meal_target_carbs']},
          \"fats\": {$profileData['meal_target_fats']},
          \"extendedIngredients\": [
            {
              \"name\": \"ingrediente principal\",
              \"original\": \"cantidad específica (peso/medida)\",
              \"localName\": \"Nombre local en {$profileData['country']}\",
              \"alternatives\": \"Alternativas si no está disponible\"
            }
          ],
          \"cuisineType\": \"{$profileData['country']}\",
          \"difficultyLevel\": \"Fácil/Intermedio/Avanzado\",
          \"goalAlignment\": \"Explicación específica de cómo esta receta ayuda con: {$profileData['goal']}\",
          \"sportsSupport\": \"Cómo apoya el entrenamiento de: " . implode(', ', $profileData['sports']) . "\",
          \"portableOption\": " . ($needsPortable || $isSnack ? "true" : "false") . ",
          \"quickRecipe\": " . ($needsQuick || $isSnack ? "true" : "false") . ",
          \"dietCompliance\": \"Cumple con dieta {$profileData['dietary_style']}\",
          \"specialTips\": \"Tips para superar: " . implode(', ', array_slice($profileData['diet_difficulties'], 0, 2)) . "\"
        }
      ]
    }

    IMPORTANTE:
    - Las 3 recetas deben ser MUY diferentes entre sí
    - NUNCA uses ingredientes de las listas prohibidas
    - Los macros deben ser exactos o muy cercanos a los objetivos
    - Usa nombres de recetas creativos y apetitosos en español
    - Las instrucciones deben ser claras y fáciles de seguir
    - Menciona a {$profileData['name']} por su nombre en las notas personalizadas
    " . ($isSnack ? "\n⚠️ RECUERDA: Esto es un SNACK, no una comida completa. SOLO ingredientes simples, SIN carnes." : "") . "
    ";

        try {
            $response = Http::withToken(env('OPENAI_API_KEY'))
                ->timeout(150)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o',
                    'messages' => [
                        ['role' => 'system', 'content' => 'Eres un chef nutricionista experto en personalización extrema de recetas.' . ($isSnack ? ' Te especializas en crear SNACKS simples y portables, NUNCA usas carnes en snacks.' : '')],
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.6,
                    'max_tokens' => 4000
                ]);

            if ($response->successful()) {
                $data = json_decode($response->json('choices.0.message.content'), true);

                if (json_last_error() === JSON_ERROR_NONE && isset($data['recipes']) && is_array($data['recipes'])) {
                    $processedRecipes = [];

                    foreach ($data['recipes'] as $recipeData) {
                        if ($isSnack) {
                            $hasProhibitedIngredient = false;
                            $prohibitedInSnacks = ['pollo', 'carne', 'res', 'cerdo', 'pescado', 'salmón', 'atún fresco', 'pavo'];

                            foreach ($recipeData['extendedIngredients'] ?? [] as $ingredient) {
                                $ingredientName = strtolower($ingredient['name'] ?? '');
                                foreach ($prohibitedInSnacks as $prohibited) {
                                    if (str_contains($ingredientName, $prohibited)) {
                                        $hasProhibitedIngredient = true;
                                        Log::warning("Snack rechazado por contener ingrediente prohibido", [
                                            'recipe' => $recipeData['name'] ?? 'Sin nombre',
                                            'ingredient' => $ingredient['name'],
                                            'prohibited' => $prohibited
                                        ]);
                                        break 2;
                                    }
                                }
                            }

                            if ($hasProhibitedIngredient) {
                                continue;
                            }
                        }

                        $recipeData['image'] = null;
                        $recipeData['analyzedInstructions'] = $this->parseInstructionsToSteps($recipeData['instructions'] ?? '');
                        $recipeData['personalizedFor'] = $profileData['name'];
                        $recipeData['mealType'] = $mealName;
                        $recipeData['generatedAt'] = now()->toIso8601String();
                        $recipeData['profileGoal'] = $profileData['goal'];
                        $recipeData['budgetLevel'] = $profileData['budget'];

                        if ($this->validateRecipeIngredients($recipeData, $profileData)) {
                            $processedRecipes[] = $recipeData;
                        } else {
                            Log::warning("Receta generada pero rechazada por contener ingredientes prohibidos", [
                                'recipe_name' => $recipeData['name'] ?? 'Sin nombre'
                            ]);
                        }
                    }

                    return $processedRecipes;
                }
            }

            Log::error("Error al generar recetas personalizadas", [
                'status' => $response->status(),
                'response' => $response->body(),
                'meal' => $mealName,
                'user' => $profileData['name']
            ]);
        } catch (\Exception $e) {
            Log::error("Excepción al generar recetas", [
                'error' => $e->getMessage(),
                'meal' => $mealName,
                'user' => $profileData['name']
            ]);
        }

        return null;
    }

    private function validateRecipeIngredients(array $recipe, array $profileData): bool
    {
        $dislikedFoods = !empty($profileData['disliked_foods'])
            ? array_map(fn($f) => trim(strtolower($f)), explode(',', $profileData['disliked_foods']))
            : [];

        $allergies = !empty($profileData['allergies'])
            ? array_map(fn($a) => trim(strtolower($a)), explode(',', $profileData['allergies']))
            : [];

        foreach ($recipe['extendedIngredients'] ?? [] as $ingredient) {
            $ingredientName = strtolower($ingredient['name'] ?? '');
            $localName = strtolower($ingredient['localName'] ?? '');

            foreach ($dislikedFoods as $disliked) {
                if (!empty($disliked) && (
                    str_contains($ingredientName, $disliked) ||
                    str_contains($localName, $disliked) ||
                    str_contains($disliked, $ingredientName) ||
                    str_contains($disliked, $localName)
                )) {
                    Log::warning("Receta contiene alimento no deseado", [
                        'ingredient' => $ingredient['name'],
                        'disliked_food' => $disliked,
                        'recipe' => $recipe['name'] ?? 'Sin nombre',
                        'user' => $profileData['name']
                    ]);
                    return false;
                }
            }

            foreach ($allergies as $allergy) {
                if (!empty($allergy) && (
                    str_contains($ingredientName, $allergy) ||
                    str_contains($localName, $allergy) ||
                    str_contains($allergy, $ingredientName) ||
                    str_contains($allergy, $localName)
                )) {
                    Log::error("¡ALERTA CRÍTICA! Receta contiene alérgeno", [
                        'ingredient' => $ingredient['name'],
                        'allergen' => $allergy,
                        'recipe' => $recipe['name'] ?? 'Sin nombre',
                        'user' => $profileData['name']
                    ]);
                    return false;
                }
            }
        }

        $dietaryStyle = strtolower($profileData['dietary_style'] ?? '');

        if (str_contains($dietaryStyle, 'vegano')) {
            $animalProducts = ['huevo', 'leche', 'queso', 'yogurt', 'yogur', 'carne', 'pollo', 'pescado', 'mariscos', 'miel', 'mantequilla', 'crema', 'jamón', 'atún'];
            foreach ($recipe['extendedIngredients'] ?? [] as $ingredient) {
                $ingredientName = strtolower($ingredient['name'] ?? '');
                $localName = strtolower($ingredient['localName'] ?? '');

                foreach ($animalProducts as $animal) {
                    if (str_contains($ingredientName, $animal) || str_contains($localName, $animal)) {
                        Log::warning("Receta no es vegana", [
                            'ingredient' => $ingredient['name'],
                            'recipe' => $recipe['name'] ?? 'Sin nombre'
                        ]);
                        return false;
                    }
                }
            }
        }

        if (str_contains($dietaryStyle, 'vegetariano')) {
            $meats = ['carne', 'pollo', 'pechuga', 'muslo', 'pescado', 'mariscos', 'atún', 'salmón', 'jamón', 'bacon', 'tocino', 'chorizo', 'salchicha'];
            foreach ($recipe['extendedIngredients'] ?? [] as $ingredient) {
                $ingredientName = strtolower($ingredient['name'] ?? '');
                $localName = strtolower($ingredient['localName'] ?? '');

                foreach ($meats as $meat) {
                    if (str_contains($ingredientName, $meat) || str_contains($localName, $meat)) {
                        Log::warning("Receta no es vegetariana", [
                            'ingredient' => $ingredient['name'],
                            'recipe' => $recipe['name'] ?? 'Sin nombre'
                        ]);
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function parseInstructionsToSteps(string $instructions): array
    {
        if (empty($instructions)) {
            return [];
        }

        $steps = [];
        $lines = explode("\n", $instructions);

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if (!empty($line) && !str_starts_with(strtolower($line), 'tip')) {
                $line = preg_replace('/^Paso \d+:\s*/i', '', $line);

                $steps[] = [
                    'number' => $index + 1,
                    'step' => $line
                ];
            }
        }

        return $steps;
    }

    private function getMealTiming($mealName, $mealTimes): string
    {
        switch ($mealName) {
            case 'Desayuno':
                return $mealTimes['breakfast_time'] ?? '07:00';
            case 'Almuerzo':
                return $mealTimes['lunch_time'] ?? '13:00';
            case 'Cena':
                return $mealTimes['dinner_time'] ?? '20:00';
            case 'Snack Proteico':
                return '16:00';
            default:
                return '12:00';
        }
    }

    private function getMealSpecificTips($mealName, array $profileData): array
    {
        $tips = [];
        $mealLower = strtolower($mealName);

        if (str_contains($mealLower, 'desayuno')) {
            $tips[] = "Desayuno diseñado para darte energía sostenida hasta el almuerzo";

            if (!empty($profileData['sports']) && in_array('Gym', $profileData['sports'])) {
                $tips[] = "Perfecto como pre-entreno si vas al gym en la mañana";
            }

            if (str_contains(strtolower($profileData['goal']), 'bajar grasa')) {
                $tips[] = "Alto en proteína para activar tu metabolismo desde temprano";
            }
        } elseif (str_contains($mealLower, 'almuerzo')) {
            $tips[] = "Tu comida principal del día con el 40% de tus nutrientes";

            if (str_contains($profileData['weekly_activity'], 'trabajo activo')) {
                $tips[] = "Energía para mantener tu rendimiento en tu trabajo activo";
            }
        } elseif (str_contains($mealLower, 'cena')) {
            $tips[] = "Cena balanceada para recuperación nocturna óptima";

            if (str_contains(strtolower($profileData['goal']), 'aumentar músculo')) {
                $tips[] = "Rica en proteínas de absorción lenta para síntesis muscular nocturna";
            }
        }

        if (in_array('Controlar los antojos', $profileData['diet_difficulties'])) {
            $tips[] = "Rica en fibra y proteína para mantener saciedad y evitar antojos";
        }

        if (in_array('Preparar la comida', $profileData['diet_difficulties'])) {
            $tips[] = "Puedes preparar el doble y guardar para mañana";
        }

        return $tips;
    }
}