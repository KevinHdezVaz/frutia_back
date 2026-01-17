<?php

namespace App\Services\PlanGeneration;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Services\PlanGeneration\FoodCalculationService;
use App\Services\PlanGeneration\MealGenerationService;
use App\Services\PlanGeneration\PromptBuilderService;

class PlanValidationService
{
    private $foodCalculationService;
    private $mealGenerationService;
    private $promptBuilderService;

    public function __construct()
    {
        $this->foodCalculationService = new FoodCalculationService();
        $this->mealGenerationService = new MealGenerationService();
        $this->promptBuilderService = new PromptBuilderService();
    }

    public function generateAndValidatePlan($profile, $nutritionalData, $userName): array
    {
        $maxAttempts = 2;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            Log::info("Intento #{$attempt} de generar plan válido", ['userId' => $profile->user_id]);

            $planData = $this->generateUltraPersonalizedNutritionalPlan($profile, $nutritionalData, $userName, $attempt);

            if ($planData === null) {
                Log::warning("La IA no generó un plan válido en intento #{$attempt}", ['userId' => $profile->user_id]);
                continue;
            }

            $validation = $this->validateGeneratedPlan($planData, $nutritionalData);

            if ($validation['is_valid']) {
                Log::info('✅ Plan con IA validado exitosamente', [
                    'userId' => $profile->user_id,
                    'attempt' => $attempt,
                    'total_macros' => $validation['total_macros']
                ]);

                $planData['validation_data'] = $validation;
                $planData['generation_method'] = 'ai_validated';

                $dislikedFoods = $profile->disliked_foods ?? '';
                $allergies = $profile->allergies ?? '';

                if (isset($planData['nutritionPlan']['meals'])) {
                    if (!empty($dislikedFoods)) {
                        Log::info("Aplicando filtro de preferencias al plan de IA", [
                            'user_id' => $profile->user_id,
                            'disliked_foods' => $dislikedFoods
                        ]);

                        foreach ($planData['nutritionPlan']['meals'] as $mealName => &$mealData) {
                            $mealData = $this->filterOptionsByPreferences($mealData, $dislikedFoods);
                        }
                    }

                    if (!empty($allergies)) {
                        Log::warning("🚨 Aplicando filtro de ALERGIAS al plan de IA", [
                            'user_id' => $profile->user_id,
                            'allergies' => $allergies
                        ]);

                        foreach ($planData['nutritionPlan']['meals'] as $mealName => &$mealData) {
                            $mealData = $this->filterOptionsByPreferences($mealData, $allergies);
                        }
                    }
                }

                return $planData;
            }

            Log::warning("Plan inválido en intento #{$attempt}", [
                'userId' => $profile->user_id,
                'errors' => $validation['errors']
            ]);
        }

        Log::info('🔄 Usando plan determinístico optimizado (backup garantizado)', ['userId' => $profile->user_id]);
        return $this->mealGenerationService->generateDeterministicPlan($nutritionalData, $profile, $userName);
    }

    public function validateGeneratedPlan($planData, $nutritionalData): array
    {
        $errors = [];
        $warnings = [];
        $totalMacros = ['protein' => 0, 'carbs' => 0, 'fats' => 0, 'calories' => 0, 'fiber' => 0];
        $foodAppearances = [];

        if (!isset($planData['nutritionPlan']['meals'])) {
            return [
                'is_valid' => false,
                'errors' => ['Estructura del plan inválida'],
                'warnings' => [],
                'total_macros' => $totalMacros
            ];
        }

        $budget = strtolower($nutritionalData['basic_data']['preferences']['budget'] ?? '');
        $isLowBudget = str_contains($budget, 'bajo');

        foreach ($planData['nutritionPlan']['meals'] as $mealName => $mealData) {
            foreach ($mealData as $category => $categoryData) {
                if (!isset($categoryData['options']) || !is_array($categoryData['options'])) {
                    continue;
                }

                $firstOption = $categoryData['options'][0] ?? null;
                if ($firstOption) {
                    $totalMacros['protein'] += $firstOption['protein'] ?? 0;
                    $totalMacros['carbs'] += $firstOption['carbohydrates'] ?? 0;
                    $totalMacros['fats'] += $firstOption['fats'] ?? 0;
                    $totalMacros['calories'] += $firstOption['calories'] ?? 0;

                    if (isset($firstOption['fiber'])) {
                        $totalMacros['fiber'] += $firstOption['fiber'];
                    }

                    foreach ($categoryData['options'] as $option) {
                        $foodName = strtolower($option['name'] ?? '');
                        if (!isset($foodAppearances[$foodName])) {
                            $foodAppearances[$foodName] = [];
                        }
                        $foodAppearances[$foodName][] = $mealName;

                        if ($isLowBudget) {
                            if ($this->foodCalculationService->isFoodHighBudget($foodName)) {
                                $errors[] = "Alimento de presupuesto alto en plan bajo: {$option['name']} en {$mealName}";
                            }
                        } else {
                            if ($this->foodCalculationService->isFoodLowBudget($foodName)) {
                                $warnings[] = "Alimento de presupuesto bajo en plan alto: {$option['name']} en {$mealName}";
                            }
                        }
                    }
                }
            }
        }

        $mealsWithEggs = [];
        foreach ($planData['nutritionPlan']['meals'] as $mealName => $mealData) {
            $hasEggInMeal = false;

            foreach ($mealData as $category => $categoryData) {
                if (!isset($categoryData['options']) || !is_array($categoryData['options'])) {
                    continue;
                }

                foreach ($categoryData['options'] as $option) {
                    if ($this->foodCalculationService->isEggProduct($option['name'] ?? '')) {
                        $hasEggInMeal = true;
                        break;
                    }
                }

                if ($hasEggInMeal) {
                    $mealsWithEggs[] = $mealName;
                    break;
                }
            }
        }

        if (count($mealsWithEggs) > 1) {
            $errors[] = 'Huevos aparecen en múltiples comidas (máximo 1 vez al día): ' . implode(', ', $mealsWithEggs);
        }

        $allergies = $nutritionalData['basic_data']['health_status']['allergies'] ?? '';

        if (!empty($allergies)) {
            $allergiesList = array_map('trim', array_map('strtolower', explode(',', $allergies)));

            foreach ($planData['nutritionPlan']['meals'] as $mealName => $mealData) {
                foreach ($mealData as $category => $categoryData) {
                    foreach ($categoryData['options'] ?? [] as $option) {
                        $foodName = $this->foodCalculationService->removeAccents(strtolower($option['name'] ?? ''));

                        foreach ($allergiesList as $allergen) {
                            if (empty($allergen)) continue;

                            $allergenNormalized = $this->foodCalculationService->removeAccents($allergen);

                            if (str_contains($foodName, $allergenNormalized) ||
                                str_contains($allergenNormalized, $foodName)) {

                                $errors[] = "❌ CRÍTICO: '{$option['name']}' contiene alérgeno MORTAL '{$allergen}' en {$mealName}/{$category}";

                                Log::error("🚨 ALERGIA DETECTADA EN PLAN GENERADO", [
                                    'food' => $option['name'],
                                    'allergen' => $allergen,
                                    'meal' => $mealName,
                                    'category' => $category
                                ]);
                            }
                        }
                    }
                }
            }
        }

        if (isset($planData['nutritionPlan']['meals']['Desayuno'])) {
            foreach ($planData['nutritionPlan']['meals']['Desayuno'] as $category => $categoryData) {
                foreach ($categoryData['options'] ?? [] as $option) {
                    $foodName = strtolower($option['name'] ?? '');
                    if (str_contains($foodName, 'quinua') || str_contains($foodName, 'quinoa')) {
                        $errors[] = "❌ CRÍTICO: Quinua no permitida en desayuno (solo almuerzo/cena)";
                        Log::error("Quinua detectada en desayuno", [
                            'option' => $option,
                            'category' => $category
                        ]);
                    }
                }
            }
        }

        $lessPreferredInPlan = [];
        foreach ($planData['nutritionPlan']['meals'] as $mealName => $mealData) {
            foreach ($mealData as $category => $categoryData) {
                if ($category === 'Carbohidratos' || $category === 'Grasas') {
                    foreach ($categoryData['options'] ?? [] as $index => $option) {
                        $foodName = strtolower($option['name'] ?? '');

                        $leastPreferred = ['camote', 'maní', 'mantequilla de maní'];
                        foreach ($leastPreferred as $lp) {
                            if (str_contains($foodName, $lp) && $index === 0) {
                                $warnings[] = "Alimento menos preferido '{$option['name']}' en primera opción de {$mealName}/{$category}";
                                $lessPreferredInPlan[] = "{$mealName} - {$option['name']}";
                            }
                        }
                    }
                }
            }
        }

        foreach ($planData['nutritionPlan']['meals'] as $mealName => $mealData) {
            if (isset($mealData['Carbohidratos']['options'])) {
                foreach ($mealData['Carbohidratos']['options'] as $option) {
                    $foodName = strtolower($option['name'] ?? '');
                    $portion = $option['portion'] ?? '';

                    $mustBeCooked = ['papa', 'arroz', 'camote', 'fideo', 'frijol', 'quinua', 'quinoa', 'pan', 'tortilla', 'galleta'];

                    $shouldBeCooked = false;
                    foreach ($mustBeCooked as $food) {
                        if (str_contains($foodName, $food)) {
                            $shouldBeCooked = true;
                            break;
                        }
                    }

                    $isCooked = str_contains(strtolower($portion), 'cocido');
                    $isRaw = str_contains(strtolower($portion), 'crudo') || str_contains(strtolower($portion), 'seco');

                    if ($shouldBeCooked && $isRaw) {
                        $errors[] = "{$option['name']} debe estar en peso cocido, no crudo";
                    }

                    if ((str_contains($foodName, 'avena') || str_contains($foodName, 'crema de arroz')) && $isCooked) {
                        $errors[] = "{$option['name']} debe estar en peso seco/crudo, no cocido";
                    }
                }
            }
        }

        $mainMeals = ['Desayuno', 'Almuerzo', 'Cena'];

        foreach ($mainMeals as $mealName) {
            if (isset($planData['nutritionPlan']['meals'][$mealName]['Vegetales'])) {
                $vegetableCalories = $planData['nutritionPlan']['meals'][$mealName]['Vegetales']['options'][0]['calories'] ?? 0;

                if ($vegetableCalories < 100) {
                    $warnings[] = "{$mealName} tiene solo {$vegetableCalories} kcal en vegetales (mínimo requerido: 100 kcal)";
                }

                if ($vegetableCalories > 150) {
                    $warnings[] = "{$mealName} tiene {$vegetableCalories} kcal en vegetales (máximo recomendado: 150 kcal)";
                }
            } else {
                $errors[] = "{$mealName} NO incluye vegetales (mínimo obligatorio: 100 kcal)";
            }
        }

        $targetMacros = $nutritionalData['macros'];
        $tolerance = 0.05;

        $proteinDiff = abs($totalMacros['protein'] - $targetMacros['protein']['grams']);
        $carbsDiff = abs($totalMacros['carbs'] - $targetMacros['carbohydrates']['grams']);
        $fatsDiff = abs($totalMacros['fats'] - $targetMacros['fats']['grams']);

        if ($proteinDiff > $targetMacros['protein']['grams'] * $tolerance) {
            $errors[] = sprintf(
                'Proteína fuera de rango: objetivo %dg, obtenido %dg (diff: %dg)',
                $targetMacros['protein']['grams'],
                $totalMacros['protein'],
                $proteinDiff
            );
        }

        if ($carbsDiff > $targetMacros['carbohydrates']['grams'] * $tolerance) {
            $errors[] = sprintf(
                'Carbohidratos fuera de rango: objetivo %dg, obtenido %dg (diff: %dg)',
                $targetMacros['carbohydrates']['grams'],
                $totalMacros['carbs'],
                $carbsDiff
            );
        }

        if ($fatsDiff > $targetMacros['fats']['grams'] * $tolerance) {
            $errors[] = sprintf(
                'Grasas fuera de rango: objetivo %dg, obtenido %dg (diff: %dg)',
                $targetMacros['fats']['grams'],
                $totalMacros['fats'],
                $fatsDiff
            );
        }

        if ($totalMacros['fiber'] > 0) {
            $sex = $nutritionalData['basic_data']['sex'] ?? 'masculino';
            $targetFiber = (strtolower($sex) === 'masculino') ? 38 : 25;

            if ($totalMacros['fiber'] < $targetFiber * 0.8) {
                $warnings[] = sprintf(
                    'Fibra baja: %dg (objetivo: %dg diarios)',
                    $totalMacros['fiber'],
                    $targetFiber
                );
            }
        }

        $mealDistribution = ['Desayuno' => 0.30, 'Almuerzo' => 0.40, 'Cena' => 0.30];
        foreach ($planData['nutritionPlan']['meals'] as $mealName => $mealData) {
            if (isset($mealDistribution[$mealName])) {
                $expectedPercentage = $mealDistribution[$mealName];
                $expectedCalories = $targetMacros['calories'] * $expectedPercentage;

                $mealCalories = 0;
                foreach ($mealData as $category => $categoryData) {
                    if (isset($categoryData['options'][0])) {
                        $mealCalories += $categoryData['options'][0]['calories'] ?? 0;
                    }
                }

                $calorieDiff = abs($mealCalories - $expectedCalories);
                if ($calorieDiff > $expectedCalories * 0.15) {
                    $warnings[] = sprintf(
                        '%s desequilibrado: esperado ~%d kcal, tiene %d kcal',
                        $mealName,
                        round($expectedCalories),
                        $mealCalories
                    );
                }
            }
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'total_macros' => $totalMacros,
            'food_appearances' => $foodAppearances,
            'meals_with_eggs' => $mealsWithEggs
        ];
    }

    private function generateUltraPersonalizedNutritionalPlan($profile, $nutritionalData, $userName, $attemptNumber = 1): ?array
    {
        $prompt = $this->promptBuilderService->buildUltraPersonalizedPrompt($profile, $nutritionalData, $userName, $attemptNumber);

        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->timeout(150)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.3,
            ]);

        if ($response->successful()) {
            $planData = json_decode($response->json('choices.0.message.content'), true);
            $planData['nutritional_calculations'] = $nutritionalData;
            return $planData;
        }

        Log::error("Fallo en la llamada a OpenAI para generar plan personalizado", [
            'status' => $response->status(),
            'body' => $response->body(),
            'attempt' => $attemptNumber
        ]);
        return null;
    }

    private function filterOptionsByPreferences(array $mealOptions, string $dislikedFoods): array
    {
        if (empty($dislikedFoods)) {
            return $mealOptions;
        }

        $dislikedList = array_map('trim', array_map('strtolower', explode(',', $dislikedFoods)));

        foreach ($mealOptions as $category => &$categoryData) {
            if (isset($categoryData['options']) && is_array($categoryData['options'])) {
                $filteredOptions = array_filter(
                    $categoryData['options'],
                    function($option) use ($dislikedList, $category) {
                        $foodName = strtolower($option['name'] ?? '');
                        $foodNameNormalized = $this->foodCalculationService->removeAccents($foodName);

                        foreach ($dislikedList as $disliked) {
                            if (empty($disliked)) continue;

                            $dislikedNormalized = $this->foodCalculationService->removeAccents($disliked);

                            if (str_contains($foodNameNormalized, $dislikedNormalized) ||
                                str_contains($dislikedNormalized, $foodNameNormalized)) {

                                Log::info("❌ Opción filtrada por preferencia del usuario", [
                                    'food_option' => $option['name'],
                                    'matches_disliked' => $disliked,
                                    'category' => $category
                                ]);
                                return false;
                            }
                        }
                        return true;
                    }
                );

                $categoryData['options'] = array_values($filteredOptions);

                if (empty($categoryData['options'])) {
                    Log::warning("⚠️ Categoría sin opciones después de filtrar", [
                        'category' => $category,
                        'disliked_foods' => $dislikedFoods
                    ]);
                }
            }
        }

        return $mealOptions;
    }

    private function filterAllergens(array $foodList, string $allergies): array
    {
        if (empty($allergies)) {
            return $foodList;
        }

        $allergensList = array_map('trim', array_map('strtolower', explode(',', $allergies)));
        $filtered = [];

        foreach ($foodList as $food) {
            $foodNormalized = $this->foodCalculationService->removeAccents(strtolower($food));
            $isAllergen = false;

            foreach ($allergensList as $allergen) {
                if (empty($allergen)) continue;

                $allergenNormalized = $this->foodCalculationService->removeAccents($allergen);

                $isMatch = str_contains($foodNormalized, $allergenNormalized) ||
                    str_contains($allergenNormalized, $foodNormalized) ||
                    $this->foodCalculationService->containsAllKeywords($foodNormalized, $allergenNormalized);

                if ($isMatch) {
                    Log::warning("🚨 Alimento filtrado por ALERGIA", [
                        'food' => $food,
                        'allergen' => $allergen
                    ]);

                    $isAllergen = true;
                    break;
                }
            }

            if (!$isAllergen) {
                $filtered[] = $food;
            }
        }

        if (empty($filtered)) {
            Log::error("⚠️ TODOS los alimentos fueron filtrados por alergias", [
                'original_list' => $foodList,
                'allergies' => $allergies
            ]);

            return ['Arroz blanco', 'Papa', 'Lentejas'];
        }

        return $filtered;
    }

    private function filterFoodOptions($foodList, $dislikedFoods, $maxOptions = 4): array
    {
        $dislikedArray = is_array($dislikedFoods)
            ? $dislikedFoods
            : array_map('trim', explode(',', strtolower($dislikedFoods)));

        $filtered = [];

        foreach ($foodList as $food) {
            $foodNormalized = $this->foodCalculationService->removeAccents(strtolower($food));
            $isDisliked = false;

            foreach ($dislikedArray as $disliked) {
                $dislikedNormalized = $this->foodCalculationService->removeAccents(strtolower(trim($disliked)));

                if (!empty($dislikedNormalized)) {
                    $isMatch = str_contains($foodNormalized, $dislikedNormalized) ||
                        str_contains($dislikedNormalized, $foodNormalized) ||
                        $this->foodCalculationService->containsAllKeywords($foodNormalized, $dislikedNormalized);

                    if ($isMatch) {
                        Log::info("❌ Alimento filtrado por preferencia", [
                            'food' => $food,
                            'disliked' => $disliked,
                            'food_normalized' => $foodNormalized,
                            'disliked_normalized' => $dislikedNormalized
                        ]);

                        $isDisliked = true;
                        break;
                    }
                }
            }

            if (!$isDisliked) {
                $filtered[] = $food;
            }

            if (count($filtered) >= $maxOptions) break;
        }

        if (empty($filtered)) {
            Log::warning("⚠️ Todos los alimentos fueron filtrados", [
                'original_list' => $foodList,
                'disliked_foods' => $dislikedFoods
            ]);
        }

        return empty($filtered) ? array_slice($foodList, 0, $maxOptions) : $filtered;
    }

    private function getFilteredFoodOptions(
        array $foodList,
        string $dislikedFoods,
        string $allergies,
        int $maxOptions = 4
    ): array {
        if (!empty($allergies)) {
            $foodList = $this->filterAllergens($foodList, $allergies);

            Log::info("🚨 Alimentos después de filtrar alergias", [
                'remaining' => $foodList,
                'allergies' => $allergies
            ]);
        }

        if (!empty($dislikedFoods)) {
            $foodList = $this->filterFoodOptions($foodList, $dislikedFoods, count($foodList));

            Log::info("❌ Alimentos después de filtrar gustos", [
                'remaining' => $foodList,
                'disliked' => $dislikedFoods
            ]);
        }

        $result = array_slice($foodList, 0, $maxOptions);

        if (empty($result)) {
            Log::warning("⚠️ Todos los alimentos fueron filtrados, usando fallback", [
                'original_list' => func_get_arg(0),
                'allergies' => $allergies,
                'disliked' => $dislikedFoods
            ]);

            return ['Arroz blanco', 'Papa', 'Lentejas'];
        }

        return $result;
    }

    private function applyFoodPreferenceSystem($foodList, $mealType, $dislikedFoods, $minOptions = 3): array
    {
        $leastPreferredFoods = [
            'Camote',
            'Maní',
            'Mantequilla de maní',
            'Mantequilla de maní casera'
        ];

        $filtered = $this->filterFoodOptions($foodList, $dislikedFoods, count($foodList));

        $preferred = [];
        $lessPreferred = [];

        foreach ($filtered as $food) {
            $isLessPreferred = false;
            foreach ($leastPreferredFoods as $leastPref) {
                if (str_contains(strtolower($food), strtolower($leastPref))) {
                    $lessPreferred[] = $food;
                    $isLessPreferred = true;
                    break;
                }
            }

            if (!$isLessPreferred) {
                $preferred[] = $food;
            }
        }

        if (count($preferred) >= $minOptions) {
            Log::info("Usando solo alimentos preferidos para {$mealType}", [
                'preferred' => $preferred,
                'excluded' => $lessPreferred
            ]);
            return array_slice($preferred, 0, $minOptions);
        }

        $result = array_merge($preferred, $lessPreferred);

        Log::info("Complementando con alimentos menos preferidos para {$mealType}", [
            'preferred_count' => count($preferred),
            'less_preferred_used' => array_slice($lessPreferred, 0, $minOptions - count($preferred))
        ]);

        return array_slice($result, 0, $minOptions);
    }

    private function prioritizeFoodList(array $foodList, array $favoriteNames): array
    {
        if (empty($favoriteNames)) {
            return $foodList;
        }

        $favorites = [];
        $others = [];

        foreach ($foodList as $food) {
            $isFavorite = false;
            $foodNormalized = $this->foodCalculationService->normalizeText($food);

            foreach ($favoriteNames as $favName) {
                $favNormalized = $this->foodCalculationService->normalizeText($favName);

                if (
                    strpos($foodNormalized, $favNormalized) !== false ||
                    strpos($favNormalized, $foodNormalized) !== false ||
                    $this->foodCalculationService->areNamesEquivalent($foodNormalized, $favNormalized) ||
                    $this->isSimilarProtein($foodNormalized, $favNormalized)
                ) {
                    Log::info("✅ Alimento marcado como favorito", [
                        'food' => $food,
                        'favorite' => $favName
                    ]);

                    $isFavorite = true;
                    break;
                }
            }

            if ($isFavorite) {
                $favorites[] = $food;
            } else {
                $others[] = $food;
            }
        }

        Log::info("Resultado de priorización", [
            'total_foods' => count($foodList),
            'favorites_found' => count($favorites),
            'others' => count($others),
            'final_order' => array_merge($favorites, $others)
        ]);

        return array_merge($favorites, $others);
    }

    private function isSimilarProtein(string $food, string $favorite): bool
    {
        $proteinEquivalences = [
            'caseina' => ['caseina', 'proteina en polvo', 'proteina'],
            'whey' => ['whey', 'proteina whey', 'proteina en polvo'],
            'yogurt griego' => ['yogurt griego', 'yogur griego', 'yogurt griego alto en proteinas'],
            'claras' => ['Claras + Huevo Entero', 'claras pasteurizadas', 'clara de huevo'],
        ];

        foreach ($proteinEquivalences as $key => $variants) {
            $foodInGroup = false;
            $favoriteInGroup = false;

            foreach ($variants as $variant) {
                if (str_contains($food, $variant)) $foodInGroup = true;
                if (str_contains($favorite, $variant)) $favoriteInGroup = true;
            }

            if ($foodInGroup && $favoriteInGroup) {
                return true;
            }
        }

        return false;
    }

    private function getForcedFavoritesForMeal(
        string $mealName,
        string $category,
        array $userFavorites
    ): array {
        $forcedFavorites = [];

        $mealRules = [
            'proteins' => [
                'Desayuno' => ['Yogurt Griego', 'Proteína Whey', 'Caseína', 'Huevo', 'Claras'],
                'Almuerzo' => ['Pollo', 'Atún', 'Carne', 'Res', 'Pavo', 'Pescado'],
                'Cena' => ['Pollo', 'Atún', 'Carne', 'Res', 'Pavo', 'Pescado'],
                'Snack' => ['Yogurt Griego', 'Proteína Whey', 'Caseína', 'Proteína en polvo']
            ],
            'carbs' => [
                'Desayuno' => ['Avena', 'Pan', 'Tortilla'],
                'Almuerzo' => ['Arroz', 'Papa', 'Camote', 'Quinua', 'Fideo', 'Pasta'],
                'Cena' => ['Arroz', 'Papa', 'Camote', 'Quinua', 'Fideo', 'Pasta'],
                'Snack' => ['Avena', 'Galletas', 'Cereal']
            ],
            'fats' => [
                'Desayuno' => ['Aceite', 'Aguacate', 'Palta', 'Almendras', 'Nueces', 'Maní'],
                'Almuerzo' => ['Aceite', 'Aguacate', 'Palta', 'Almendras', 'Nueces', 'Maní'],
                'Cena' => ['Aceite', 'Aguacate', 'Palta', 'Almendras', 'Nueces', 'Maní'],
                'Snack' => ['Maní', 'Mantequilla', 'Almendras', 'Nueces']
            ]
        ];

        $mealType = 'Desayuno';
        if (str_contains($mealName, 'Almuerzo')) $mealType = 'Almuerzo';
        elseif (str_contains($mealName, 'Cena')) $mealType = 'Cena';
        elseif (str_contains($mealName, 'Snack')) $mealType = 'Snack';

        $categoryKey = $category === 'Proteínas' ? 'proteins' :
            ($category === 'Carbohidratos' ? 'carbs' : 'fats');

        $allowedKeywords = $mealRules[$categoryKey][$mealType] ?? [];

        foreach ($userFavorites as $favorite) {
            $favoriteNormalized = $this->foodCalculationService->normalizeText($favorite);

            foreach ($allowedKeywords as $keyword) {
                $keywordNormalized = $this->foodCalculationService->normalizeText($keyword);

                if (str_contains($favoriteNormalized, $keywordNormalized) ||
                    str_contains($keywordNormalized, $favoriteNormalized)) {
                    $forcedFavorites[] = $favorite;
                    break;
                }
            }
        }

        return $forcedFavorites;
    }

    private function ensureForcedFavoritesInList(
        array $currentOptions,
        array $forcedFavorites,
        string $allergies,
        string $dislikedFoods
    ): array {
        if (empty($forcedFavorites)) {
            return $currentOptions;
        }

        $nameMapping = [
            'Yogurt Griego' => ['Yogurt griego alto en proteínas', 'Yogurt griego'],
            'Caseína' => ['Caseína', 'Proteína en polvo'],
            'Proteína Whey' => ['Proteína whey', 'Proteína en polvo'],
            'Pollo' => ['Pechuga de pollo', 'Pollo muslo'],
            'Atún' => ['Atún en lata', 'Atún fresco'],
            'Carne' => ['Carne de res magra', 'Carne molida'],
            'Res' => ['Carne de res magra', 'Carne molida'],
            'Pavo' => ['Pechuga de pavo'],
            'Pescado' => ['Pescado blanco', 'Salmón fresco'],
            'Papa' => ['Papa'],
            'Arroz' => ['Arroz blanco', 'arroz blanco'],
            'Camote' => ['Camote'],
            'Quinua' => ['Quinua'],
            'Avena' => ['Avena orgánica', 'Avena tradicional', 'Avena'],
            'Tortilla' => ['Tortilla de maíz'],
            'Pan' => ['Pan integral artesanal', 'Pan integral'],
            'Galletas' => ['Galletas de arroz'],
            'Fideo' => ['Fideo'],
            'Pasta' => ['Pasta integral'],
            'Aceite De Oliva' => ['Aceite de oliva extra virgen', 'Aceite de oliva'],
            'Aceite De Palta' => ['Aguacate hass', 'Aguacate'],
            'Aguacate' => ['Aguacate hass', 'Aguacate'],
            'Palta' => ['Aguacate hass', 'Aguacate'],
            'Nueces' => ['Nueces'],
            'Almendras' => ['Almendras'],
            'Chía' => ['Semillas de chía orgánicas'],
            'Maní' => ['Maní', 'Mantequilla de maní']
        ];

        $missingFavorites = [];

        foreach ($forcedFavorites as $favorite) {
            $favoriteNormalized = $this->foodCalculationService->normalizeText($favorite);

            $foundInList = false;
            foreach ($currentOptions as $option) {
                $optionNormalized = $this->foodCalculationService->normalizeText($option);
                if (str_contains($optionNormalized, $favoriteNormalized) ||
                    str_contains($favoriteNormalized, $optionNormalized)) {
                    $foundInList = true;
                    break;
                }
            }

            if (!$foundInList) {
                foreach ($nameMapping as $userKey => $systemNames) {
                    $userKeyNormalized = $this->foodCalculationService->normalizeText($userKey);

                    if (str_contains($favoriteNormalized, $userKeyNormalized) ||
                        str_contains($userKeyNormalized, $favoriteNormalized)) {

                        foreach ($systemNames as $systemName) {
                            $systemNormalized = $this->foodCalculationService->normalizeText($systemName);

                            $isAllergic = !empty($allergies) &&
                                $this->foodCalculationService->containsAllKeywords($systemNormalized, $this->foodCalculationService->removeAccents(strtolower($allergies)));

                            $isDisliked = !empty($dislikedFoods) &&
                                $this->foodCalculationService->containsAllKeywords($systemNormalized, $this->foodCalculationService->removeAccents(strtolower($dislikedFoods)));

                            if (!$isAllergic && !$isDisliked) {
                                $missingFavorites[] = $systemName;

                                Log::info("✅ Favorito FORZADO agregado", [
                                    'user_favorite' => $favorite,
                                    'system_name' => $systemName
                                ]);
                                break;
                            }
                        }
                        break;
                    }
                }
            }
        }

        return array_merge($missingFavorites, $currentOptions);
    }

}