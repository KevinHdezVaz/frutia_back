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
            Log::info("Attempt #{$attempt} to generate valid plan", ['userId' => $profile->user_id]);

            $planData = $this->generateUltraPersonalizedNutritionalPlan($profile, $nutritionalData, $userName, $attempt);

            if ($planData === null) {
                Log::warning("AI did not generate a valid plan on attempt #{$attempt}", ['userId' => $profile->user_id]);
                continue;
            }

            $validation = $this->validateGeneratedPlan($planData, $nutritionalData);

            if ($validation['is_valid']) {
                Log::info('✅ AI plan validated successfully', [
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
                        Log::info("Applying preferences filter to AI plan", [
                            'user_id' => $profile->user_id,
                            'disliked_foods' => $dislikedFoods
                        ]);

                        foreach ($planData['nutritionPlan']['meals'] as $mealName => &$mealData) {
                            $mealData = $this->filterOptionsByPreferences($mealData, $dislikedFoods);
                        }
                    }

                    if (!empty($allergies)) {
                        Log::warning("🚨 Applying ALLERGIES filter to AI plan", [
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

            Log::warning("Invalid plan on attempt #{$attempt}", [
                'userId' => $profile->user_id,
                'errors' => $validation['errors']
            ]);
        }

        Log::info('🔄 Using optimized deterministic plan (guaranteed backup)', ['userId' => $profile->user_id]);
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
                'errors' => ['Invalid plan structure'],
                'warnings' => [],
                'total_macros' => $totalMacros
            ];
        }

        $budget = strtolower($nutritionalData['basic_data']['preferences']['budget'] ?? '');
        $isLowBudget = str_contains($budget, 'low') || str_contains($budget, 'bajo');

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
                                $errors[] = "High-budget food in low-budget plan: {$option['name']} in {$mealName}";
                            }
                        } else {
                            if ($this->foodCalculationService->isFoodLowBudget($foodName)) {
                                $warnings[] = "Low-budget food in high-budget plan: {$option['name']} in {$mealName}";
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
            $errors[] = 'Eggs appear in multiple meals (maximum once per day): ' . implode(', ', $mealsWithEggs);
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
                                $errors[] = "❌ CRITICAL: '{$option['name']}' contains DEADLY allergen '{$allergen}' in {$mealName}/{$category}";
                                Log::error("🚨 ALLERGY DETECTED IN GENERATED PLAN", [
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

        if (isset($planData['nutritionPlan']['meals']['breakfast'])) {
            foreach ($planData['nutritionPlan']['meals']['breakfast'] as $category => $categoryData) {
                foreach ($categoryData['options'] ?? [] as $option) {
                    $foodName = strtolower($option['name'] ?? '');
                    if (str_contains($foodName, 'quinoa')) {
                        $errors[] = "❌ CRITICAL: Quinoa not allowed in breakfast (only lunch/dinner)";
                        Log::error("Quinoa detected in breakfast", [
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
                if ($category === 'Carbs' || $category === 'Fats') {
                    foreach ($categoryData['options'] ?? [] as $index => $option) {
                        $foodName = strtolower($option['name'] ?? '');
                        $leastPreferred = ['sweet potato', 'peanuts', 'peanut butter'];
                        foreach ($leastPreferred as $lp) {
                            if (str_contains($foodName, $lp) && $index === 0) {
                                $warnings[] = "Less preferred food '{$option['name']}' in first option of {$mealName}/{$category}";
                                $lessPreferredInPlan[] = "{$mealName} - {$option['name']}";
                            }
                        }
                    }
                }
            }
        }

        foreach ($planData['nutritionPlan']['meals'] as $mealName => $mealData) {
            if (isset($mealData['Carbs']['options'])) {
                foreach ($mealData['Carbs']['options'] as $option) {
                    $foodName = strtolower($option['name'] ?? '');
                    $portion = $option['portion'] ?? '';

                    $mustBeCooked = ['potato', 'rice', 'sweet potato', 'noodles', 'beans', 'quinoa', 'bread', 'tortilla', 'rice crackers'];
                    $shouldBeCooked = false;

                    foreach ($mustBeCooked as $food) {
                        if (str_contains($foodName, $food)) {
                            $shouldBeCooked = true;
                            break;
                        }
                    }

                    $isCooked = str_contains(strtolower($portion), 'cooked');
                    $isRaw = str_contains(strtolower($portion), 'raw') || str_contains(strtolower($portion), 'dry');

                    if ($shouldBeCooked && $isRaw) {
                        $errors[] = "{$option['name']} should be cooked weight, not raw";
                    }

                    if ((str_contains($foodName, 'oats') || str_contains($foodName, 'cream of rice')) && $isCooked) {
                        $errors[] = "{$option['name']} should be dry/raw weight, not cooked";
                    }
                }
            }
        }

        $mainMeals = ['breakfast', 'lunch', 'dinner'];

        foreach ($mainMeals as $mealName) {
            if (isset($planData['nutritionPlan']['meals'][$mealName]['Vegetables'])) {
                $vegetableCalories = $planData['nutritionPlan']['meals'][$mealName]['Vegetables']['options'][0]['calories'] ?? 0;

                if ($vegetableCalories < 100) {
                    $warnings[] = "{$mealName} has only {$vegetableCalories} kcal in vegetables (minimum required: 100 kcal)";
                }

                if ($vegetableCalories > 150) {
                    $warnings[] = "{$mealName} has {$vegetableCalories} kcal in vegetables (maximum recommended: 150 kcal)";
                }
            } else {
                $errors[] = "{$mealName} does NOT include vegetables (minimum required: 100 kcal)";
            }
        }

        $targetMacros = $nutritionalData['macros'];
        $tolerance = 0.05;

        $proteinDiff = abs($totalMacros['protein'] - $targetMacros['protein']['grams']);
        $carbsDiff = abs($totalMacros['carbs'] - $targetMacros['carbohydrates']['grams']);
        $fatsDiff = abs($totalMacros['fats'] - $targetMacros['fats']['grams']);

        if ($proteinDiff > $targetMacros['protein']['grams'] * $tolerance) {
            $errors[] = sprintf(
                'Protein out of range: target %dg, obtained %dg (diff: %dg)',
                $targetMacros['protein']['grams'],
                $totalMacros['protein'],
                $proteinDiff
            );
        }

        if ($carbsDiff > $targetMacros['carbohydrates']['grams'] * $tolerance) {
            $errors[] = sprintf(
                'Carbohydrates out of range: target %dg, obtained %dg (diff: %dg)',
                $targetMacros['carbohydrates']['grams'],
                $totalMacros['carbs'],
                $carbsDiff
            );
        }

        if ($fatsDiff > $targetMacros['fats']['grams'] * $tolerance) {
            $errors[] = sprintf(
                'Fats out of range: target %dg, obtained %dg (diff: %dg)',
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
                    'Low fiber: %dg (daily target: %dg)',
                    $totalMacros['fiber'],
                    $targetFiber
                );
            }
        }

        $mealDistribution = ['breakfast' => 0.30, 'lunch' => 0.40, 'dinner' => 0.30];

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
                        '%s unbalanced: expected ~%d kcal, has %d kcal',
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

        Log::error("Failure in OpenAI call to generate personalized plan", [
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
                                Log::info("❌ Option filtered by user preference", [
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
                    Log::warning("⚠️ Category without options after filtering", [
                        'category' => $category,
                        'disliked_foods' => $dislikedFoods
                    ]);
                }
            }
        }

        return $mealOptions;
    }

    private function getFilteredFoodOptions(
        array $foodList,
        string $dislikedFoods,
        string $allergies,
        int $maxOptions = 4
    ): array {
        if (!empty($allergies)) {
            $foodList = $this->filterAllergens($foodList, $allergies);
            Log::info("🚨 Foods after filtering allergies", [
                'remaining' => $foodList,
                'allergies' => $allergies
            ]);
        }

        if (!empty($dislikedFoods)) {
            $foodList = $this->filterFoodOptions($foodList, $dislikedFoods, count($foodList));
            Log::info("❌ Foods after filtering preferences", [
                'remaining' => $foodList,
                'disliked' => $dislikedFoods
            ]);
        }

        $result = array_slice($foodList, 0, $maxOptions);

        if (empty($result)) {
            Log::warning("⚠️ All foods filtered, using fallback", [
                'original_list' => $foodList,
                'allergies' => $allergies,
                'disliked' => $dislikedFoods
            ]);
            return ['White rice', 'Potato', 'Beans'];
        }

        return $result;
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
                    Log::warning("🚨 Food filtered by ALLERGY", [
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
            Log::error("⚠️ ALL foods filtered by allergies", [
                'original_list' => $foodList,
                'allergies' => $allergies
            ]);
            return ['White rice', 'Potato', 'Beans'];
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
                        Log::info("❌ Food filtered by preference", [
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
            Log::warning("⚠️ All foods filtered", [
                'original_list' => $foodList,
                'disliked_foods' => $dislikedFoods
            ]);
        }

        return empty($filtered) ? array_slice($foodList, 0, $maxOptions) : $filtered;
    }

    private function applyFoodPreferenceSystem($foodList, $mealType, $dislikedFoods, $minOptions = 3): array
    {
        $leastPreferredFoods = [
            'Sweet potato',
            'Peanuts',
            'Peanut butter',
            'Homemade peanut butter'
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
            Log::info("Using only preferred foods for {$mealType}", [
                'preferred' => $preferred,
                'excluded' => $lessPreferred
            ]);
            return array_slice($preferred, 0, $minOptions);
        }

        $result = array_merge($preferred, $lessPreferred);

        Log::info("Supplementing with less preferred foods for {$mealType}", [
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
                    Log::info("✅ Food marked as favorite", [
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

        Log::info("Prioritization result", [
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
            'casein' => ['casein', 'protein powder', 'protein'],
            'whey' => ['whey', 'whey protein', 'protein powder'],
            'greek yogurt' => ['greek yogurt', 'high-protein greek yogurt'],
            'egg whites' => ['egg whites + whole egg', 'pasteurized egg whites'],
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
        string $mealKey,
        string $category,
        array $userFavorites
    ): array {
        $forcedFavorites = [];

        $mealRules = [
            'proteins' => [
                'breakfast' => ['Greek yogurt', 'Whey protein', 'Casein', 'Egg', 'Egg whites'],
                'lunch' => ['Chicken', 'Tuna', 'Beef', 'Turkey', 'Fish'],
                'dinner' => ['Chicken', 'Tuna', 'Beef', 'Turkey', 'Fish'],
                'snack' => ['Greek yogurt', 'Whey protein', 'Casein', 'Protein powder']
            ],
            'carbs' => [
                'breakfast' => ['Oats', 'Bread', 'Tortilla'],
                'lunch' => ['Rice', 'Potato', 'Sweet potato', 'Quinoa', 'Noodles', 'Pasta'],
                'dinner' => ['Rice', 'Potato', 'Sweet potato', 'Quinoa', 'Noodles', 'Pasta'],
                'snack' => ['Oats', 'Rice crackers', 'Corn cereal']
            ],
            'fats' => [
                'breakfast' => ['Oil', 'Avocado', 'Almonds', 'Walnuts', 'Peanuts'],
                'lunch' => ['Oil', 'Avocado', 'Almonds', 'Walnuts', 'Peanuts'],
                'dinner' => ['Oil', 'Avocado', 'Almonds', 'Walnuts', 'Peanuts'],
                'snack' => ['Peanuts', 'Peanut butter', 'Almonds', 'Walnuts']
            ]
        ];

        $mealType = 'breakfast';
        if (str_contains($mealKey, 'lunch')) $mealType = 'lunch';
        elseif (str_contains($mealKey, 'dinner')) $mealType = 'dinner';
        elseif (str_contains($mealKey, 'snack')) $mealType = 'snack';

        $categoryKey = $category === 'Proteins' ? 'proteins' :
            ($category === 'Carbs' ? 'carbs' : 'fats');

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
            'Greek yogurt' => ['High-protein Greek yogurt', 'Greek yogurt'],
            'Casein' => ['Casein', 'Protein powder'],
            'Whey protein' => ['Whey protein', 'Protein powder'],
            'Chicken' => ['Chicken breast', 'Chicken thigh'],
            'Tuna' => ['Canned tuna', 'Fresh tuna'],
            'Beef' => ['Lean beef', 'Ground beef'],
            'Turkey' => ['Turkey breast'],
            'Fish' => ['White fish', 'Fresh salmon'],
            'Potato' => ['Potato'],
            'Rice' => ['White rice'],
            'Sweet potato' => ['Sweet potato'],
            'Quinoa' => ['Quinoa'],
            'Oats' => ['Oats', 'Organic oats'],
            'Tortilla' => ['Corn tortilla'],
            'Bread' => ['Whole wheat bread', 'Artisanal whole wheat bread'],
            'Rice crackers' => ['Rice crackers'],
            'Noodles' => ['Noodles'],
            'Pasta' => ['Whole wheat pasta'],
            'Olive oil' => ['Extra virgin olive oil', 'Olive oil'],
            'Avocado' => ['Hass avocado', 'Avocado'],
            'Walnuts' => ['Walnuts'],
            'Almonds' => ['Almonds'],
            'Chia' => ['Organic chia seeds'],
            'Peanuts' => ['Peanuts', 'Peanut butter']
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
                                Log::info("✅ Forced favorite added", [
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