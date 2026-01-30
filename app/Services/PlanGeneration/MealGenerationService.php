<?php

namespace App\Services\PlanGeneration;

use Illuminate\Support\Facades\Log;
use App\Services\NutritionalCalculator;
use App\Services\PlanGeneration\FoodCalculationService;

class MealGenerationService
{
    private $foodCalculationService;

    public function __construct()
    {
        $this->foodCalculationService = new FoodCalculationService();
    }

    public function generateDeterministicPlan($nutritionalData, $profile, $userName): array
    {
        try {
            $macros = $nutritionalData['macros'];
            $userWeight = $nutritionalData['basic_data']['weight'] ?? 70;
            $foodPreferences = $nutritionalData['food_preferences'] ?? [
                'proteins' => [],
                'carbs' => [],
                'fats' => [],
                'fruits' => []
            ];

            Log::info("Aplicando preferencias en plan determinístico", [
                'user_id' => $profile->user_id,
                'preferences' => $foodPreferences
            ]);

            $preferredSnackTime = $nutritionalData['basic_data']['preferences']['preferred_snack_time'] ?? 'Snack AM';

            Log::info("Preferencia de snack del usuario", [
                'preferred_snack_time' => $preferredSnackTime
            ]);

            $mealDistribution = [
                'breakfast' => 0.30,
                'lunch' => 0.40,
                'dinner' => 0.20
            ];

         if ($preferredSnackTime === 'Snack AM') {
    $mealDistribution['snack_am'] = 0.10;
    $personalizedMessage = __('food.personalized_message_am', ['name' => $userName]);
} elseif ($preferredSnackTime === 'Snack PM') {
    $mealDistribution['snack_pm'] = 0.10;
    $personalizedMessage = __('food.personalized_message_pm', ['name' => $userName]);
} else {
    $mealDistribution['snack_am'] = 0.10;
    $personalizedMessage = __('food.personalized_message_default', ['name' => $userName]);
}

            $budget = strtolower($nutritionalData['basic_data']['preferences']['budget'] ?? '');
            $isLowBudget = str_contains($budget, 'low') || str_contains($budget, 'bajo');
            $dietaryStyle = strtolower($nutritionalData['basic_data']['preferences']['dietary_style'] ?? 'omnivore');
            $dislikedFoods = $nutritionalData['basic_data']['preferences']['disliked_foods'] ?? '';
            $allergies = $nutritionalData['basic_data']['health_status']['allergies'] ?? '';

            Log::info("🔍 Restricciones alimentarias del usuario", [
                'user_id' => $profile->user_id,
                'disliked_foods' => $dislikedFoods,
                'allergies' => $allergies
            ]);

            $meals = [];

            foreach ($mealDistribution as $mealKey => $percentage) {
                $mealProtein = round($macros['protein']['grams'] * $percentage);
                $mealCarbs = round($macros['carbohydrates']['grams'] * $percentage);
                $mealFats = round($macros['fats']['grams'] * $percentage);
                $mealCalories = round($macros['calories'] * $percentage);

                if (str_contains($mealKey, 'snack')) {
                    $snackType = str_contains($mealKey, 'am') ? 'AM' : 'PM';
                    $meals[$mealKey] = $this->generateSnackOptions(
                        $mealCalories,
                        $isLowBudget,
                        $snackType,
                        $dislikedFoods,
                        $allergies,
                        $foodPreferences
                    );
                } else {
                    $meals[$mealKey] = $this->generateDeterministicMealOptions(
                        $mealKey,
                        $mealProtein,
                        $mealCarbs,
                        $mealFats,
                        $isLowBudget,
                        $userWeight,
                        $dietaryStyle,
                        $dislikedFoods,
                        $foodPreferences,
                        $allergies
                    );
                }

                foreach ($meals[$mealKey] as $category => &$categoryData) {
                    if (isset($categoryData['options'])) {
                        foreach ($categoryData['options'] as &$option) {
                            if (!isset($option['isEgg'])) {
                                $this->addFoodMetadata($option, $isLowBudget);
                            }
                        }
                    }
                }
            }

            $mealTimings = [
                'breakfast' => '07:00',
                'snack_am' => '10:00',
                'lunch' => '13:00',
                'snack_pm' => '16:00',
                'dinner' => '20:00',
            ];

            foreach ($meals as $mealKey => &$mealData) {
                if (isset($mealTimings[$mealKey])) {
                    $mealData['meal_timing'] = $mealTimings[$mealKey];
                }
            }

            $time = $preferredSnackTime === 'Snack AM' ? __('mid_morning') : __('mid_afternoon');

            $generalRecommendations = [
                __('hydration_recommendation'),
                __('main_meals_mandatory'),
                __('snack_for', ['time' => $time]),
                __('respect_schedules'),
                __('vegetables_free')
            ];

            $nutritionalSummary = [
                'tmb' => $nutritionalData['tmb'] ?? 0,
                'get' => $nutritionalData['get'] ?? 0,
                'targetCalories' => $nutritionalData['target_calories'] ?? 0,
                'goal' => $nutritionalData['basic_data']['goal'] ?? 'Lose fat',
                'snack_preference' => $preferredSnackTime
            ];

            $planData = [
                'nutritionPlan' => [
                    'personalizedMessage' => $personalizedMessage,
                    'meals' => $meals,
                    'targetMacros' => [
                        'calories' => $macros['calories'],
                        'protein' => $macros['protein']['grams'],
                        'fats' => $macros['fats']['grams'],
                        'carbohydrates' => $macros['carbohydrates']['grams']
                    ],
                    'mealStructure' => __('meal_structure', ['time' => $preferredSnackTime]),
                    'generalRecommendations' => $generalRecommendations,
                    'nutritionalSummary' => $nutritionalSummary
                ],
                'validation_data' => [
                    'is_valid' => true,
                    'method' => 'deterministic',
                    'guaranteed_accurate' => true,
                    'snack_generated' => $preferredSnackTime
                ],
                'generation_method' => 'deterministic_backup'
            ];

            Log::info("Plan determinístico generado exitosamente", [
                'user_id' => $profile->user_id,
                'meals_generated' => array_keys($meals),
                'snack_preference' => $preferredSnackTime
            ]);

            return $planData;
        } catch (\Exception $e) {
            Log::error('Error en generateDeterministicPlan', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new \Exception("Error al generar el plan determinístico: " . $e->getMessage());
        }
    }

    public function generateSnackOptions($targetCalories, $isLowBudget, $snackType = 'AM', $dislikedFoods = '', $allergies = '', $foodPreferences = []): array
    {
        $targetProtein = round($targetCalories * 0.30 / 4);
        $targetCarbs = round($targetCalories * 0.50 / 4);
        $targetFats = round($targetCalories * 0.20 / 9);

        $options = [];

        if ($isLowBudget) {
            $proteinOptions = ['Greek yogurt', 'Canned tuna'];
        } else {
            $proteinOptions = ['Protein powder', 'High-protein Greek yogurt', 'Casein'];
        }

        $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
        $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

        if (!empty($filteredProteins)) {
            $options['Proteins'] = ['options' => []];
            foreach ($filteredProteins as $proteinName) {
                $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein, $isLowBudget);
                if ($portionData) {
                    $options['Proteins']['options'][] = $portionData;
                }
            }
        }

        $carbOptions = ['Corn cereal', 'Cream of rice', 'Rice crackers', 'Oats'];
        $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
        $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 4);

        if (!empty($filteredCarbs)) {
            $options['Carbs'] = ['options' => []];
            foreach ($filteredCarbs as $carbName) {
                $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                if ($portionData) {
                    $options['Carbs']['options'][] = $portionData;
                }
            }
        }

        if ($isLowBudget) {
            $fatOptions = ['Homemade peanut butter', 'Peanuts'];
        } else {
            $fatOptions = ['Peanut butter', 'Honey', '70% dark chocolate'];
        }

        $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
        $filteredFats = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies);
        $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, "Snack-{$snackType}-Fats", '', 3);

        if (!empty($filteredFats)) {
            $options['Fats'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                if ($fatName === 'Honey') {
                    $gramsNeeded = round($targetFats * 3);
                    $options['Fats']['options'][] = [
                        'name' => 'Honey',
                        'portion' => "{$gramsNeeded}g",
                        'calories' => round($targetFats * 9),
                        'protein' => 0,
                        'fats' => 0,
                        'carbohydrates' => round($targetFats * 2.5)
                    ];
                } elseif ($fatName === '70% dark chocolate') {
                    $gramsNeeded = round($targetFats * 1.8);
                    $options['Fats']['options'][] = [
                        'name' => '70% dark chocolate',
                        'portion' => "{$gramsNeeded}g",
                        'calories' => round($targetFats * 10),
                        'protein' => round($targetFats * 0.15),
                        'fats' => $targetFats,
                        'carbohydrates' => round($targetFats * 0.8)
                    ];
                } else {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats, $isLowBudget);
                    if ($portionData) {
                        $options['Fats']['options'][] = $portionData;
                    }
                }
            }
        }

        $options['meal_timing'] = $snackType === 'AM' ? '10:00' : '16:00';
        $options['personalized_tips'] = [
            $snackType === 'AM' ?
                __('snack_am_tip') :
                __('snack_pm_tip')
        ];

        return $options;
    }

    private function generateDeterministicMealOptions(
        $mealKey,
        $targetProtein,
        $targetCarbs,
        $targetFats,
        $isLowBudget,
        $userWeight,
        $dietaryStyle,
        $dislikedFoods = '',
        $foodPreferences = [],
        $allergies = ''
    ): array {
        $mealCalories = ($targetProtein * 4) + ($targetCarbs * 4) + ($targetFats * 9);

        $targetProtein = round(($mealCalories * 0.40) / 4);
        $targetCarbs = round(($mealCalories * 0.40) / 4);
        $targetFats = round(($mealCalories * 0.20) / 9);

        Log::info("Macros recalculados para {$mealKey} con ratio 40/40/20", [
            'calories' => $mealCalories,
            'protein' => $targetProtein,
            'carbs' => $targetCarbs,
            'fats' => $targetFats
        ]);

        $calculator = new NutritionalCalculator();
        $adjustedTargets = $calculator->calculateAdjustedPortions(
            [
                'protein' => $targetProtein,
                'carbohydrates' => $targetCarbs,
                'fats' => $targetFats
            ],
            1.0
        );

        $adjustedProtein = round($adjustedTargets['protein']['primary']);
        $adjustedCarbs = round($adjustedTargets['carbohydrates']['primary']);
        $adjustedFats = round($adjustedTargets['fats']['primary']);

        $dietaryStyle = strtolower($dietaryStyle);
        $dietaryStyle = preg_replace('/[^\w\s]/u', '', $dietaryStyle);
        $dietaryStyle = trim($dietaryStyle);

        $options = [];

        if (str_contains($dietaryStyle, 'vegan')) {
            $options = $this->getVeganOptions($mealKey, $adjustedProtein, $adjustedCarbs, $adjustedFats, $isLowBudget, $dislikedFoods, $foodPreferences, $allergies);
        } elseif (str_contains($dietaryStyle, 'vegetarian')) {
            $options = $this->getVegetarianOptions($mealKey, $adjustedProtein, $adjustedCarbs, $adjustedFats, $isLowBudget, $dislikedFoods, $foodPreferences, $allergies);
        } elseif (str_contains($dietaryStyle, 'keto')) {
            $options = $this->getKetoOptions($mealKey, $adjustedProtein, $adjustedCarbs, $adjustedFats, $isLowBudget, $dislikedFoods, $foodPreferences, $allergies);
        } else {
            $options = $this->getOmnivorousOptions($mealKey, $adjustedProtein, $adjustedCarbs, $adjustedFats, $isLowBudget, $dislikedFoods, $foodPreferences, $allergies);
        }

        if (str_contains($dietaryStyle, 'keto')) {
            $options['Vegetables'] = [
                'requirement' => 'minimum',
                'min_calories' => 100,
                'max_calories' => 150,
                'recommendation' => __('vegetables_recommendation'),
                'options' => [
                    [
                        'name' => 'Large mixed green salad',
                        'portion' => '400g (2 large cups)',
                        'calories' => 100,
                        'protein' => 4,
                        'fats' => 0,
                        'carbohydrates' => 15,
                        'fiber' => 8,
                        'portion_examples' => '2 cups lettuce + 1 cup spinach + 1/2 cup cucumber + 1/4 cup bell pepper'
                    ],
                    [
                        'name' => 'Cruciferous vegetables salad',
                        'portion' => '350g (2 cups)',
                        'calories' => 105,
                        'protein' => 5,
                        'fats' => 0,
                        'carbohydrates' => 16,
                        'fiber' => 9,
                        'portion_examples' => '1 cup broccoli + 1 cup cauliflower + 1/2 cup purple cabbage'
                    ],
                    [
                        'name' => 'Low-carb vegetables mix',
                        'portion' => '380g',
                        'calories' => 110,
                        'protein' => 4,
                        'fats' => 0,
                        'carbohydrates' => 17,
                        'fiber' => 7,
                        'portion_examples' => '1.5 cups spinach + 1/2 cup mushrooms + 1/2 cup zucchini + cherry tomatoes'
                    ]
                ]
            ];
        } else {
            $options['Vegetables'] = [
                'requirement' => 'minimum',
                'min_calories' => 100,
                'max_calories' => 150,
                'recommendation' => __('vegetables_recommendation'),
                'options' => [
                    [
                        'name' => 'Complete mixed salad',
                        'portion' => '350g (2.5 cups)',
                        'calories' => 100,
                        'protein' => 4,
                        'fats' => 0,
                        'carbohydrates' => 18,
                        'fiber' => 6,
                        'portion_examples' => '2 cups mixed lettuce + 1 medium tomato + 1/2 cup grated carrot + 1/4 cup onion'
                    ],
                    [
                        'name' => 'Steamed vegetables bowl',
                        'portion' => '300g (2 cups)',
                        'calories' => 110,
                        'protein' => 5,
                        'fats' => 0,
                        'carbohydrates' => 20,
                        'fiber' => 8,
                        'portion_examples' => '1 cup broccoli + 1/2 cup carrot + 1/2 cup green beans + 1/2 cup squash'
                    ],
                    [
                        'name' => 'Mediterranean salad',
                        'portion' => '320g (2 cups)',
                        'calories' => 105,
                        'protein' => 4,
                        'fats' => 0,
                        'carbohydrates' => 19,
                        'fiber' => 7,
                        'portion_examples' => '1.5 cups lettuce + 1 tomato + 1/2 cucumber + 1/4 cup bell pepper + red onion'
                    ],
                    [
                        'name' => 'Sautéed vegetables',
                        'portion' => '280g (2 cups)',
                        'calories' => 120,
                        'protein' => 5,
                        'fats' => 1,
                        'carbohydrates' => 22,
                        'fiber' => 8,
                        'portion_examples' => '1 cup broccoli + 1/2 cup bell pepper + 1/2 cup onion + 1/2 cup zucchini'
                    ]
                ]
            ];
        }

        return $options;
    }

    private function addFoodMetadata(&$option, $isLowBudget = false)
    {
        $foodName = $option['name'] ?? '';
        $option['isEgg'] = $this->foodCalculationService->isEggProduct($foodName);
        $option['isHighBudget'] = $this->foodCalculationService->isFoodHighBudget($foodName);
        $option['isLowBudget'] = $this->foodCalculationService->isFoodLowBudget($foodName);
        $option['budgetAppropriate'] = $isLowBudget ? !$option['isHighBudget'] : !$option['isLowBudget'];
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

    private function getOmnivorousOptions(
        $mealKey,
        $targetProtein,
        $targetCarbs,
        $targetFats,
        $isLowBudget,
        $dislikedFoods = '',
        $foodPreferences = [],
        $allergies = ''
    ): array {
        $options = [];

        if ($isLowBudget) {
            if ($mealKey === 'breakfast') {
                $proteinOptions = ['Whole egg', 'Canned tuna', 'Chicken thigh'];
                $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
                $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
                $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

                $options['Proteins'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein);
                    if ($portionData) {
                        $options['Proteins']['options'][] = $portionData;
                    }
                }

                $carbOptions = ['Oats', 'Whole wheat bread', 'Corn tortilla'];
                $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
                $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

                $options['Carbs'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbs']['options'][] = $portionData;
                    }
                }

                $fatOptions = ['Vegetable oil', 'Peanuts', 'Avocado'];
                $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
                $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies);
                $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'breakfast-Fats', '', 3);

                $options['Fats'] = ['options' => []];
                foreach ($filteredFats as $fatName) {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats);
                    if ($portionData) {
                        $options['Fats']['options'][] = $portionData;
                    }
                }
            } elseif ($mealKey === 'lunch') {
                $proteinOptions = ['Chicken thigh', 'Ground beef', 'Canned tuna', 'Chicken breast'];
                $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
                $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
                $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

                $options['Proteins'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein);
                    if ($portionData) {
                        $options['Proteins']['options'][] = $portionData;
                    }
                }

                $carbOrderPreference = ['Potato', 'White rice', 'Sweet potato', 'Noodles', 'Beans', 'Quinoa'];
                $carbOrderPreference = $this->prioritizeFoodList($carbOrderPreference, $foodPreferences['carbs'] ?? []);
                $selectedCarbs = $this->getFilteredFoodOptions($carbOrderPreference, $dislikedFoods, $allergies, 6);
                $selectedCarbs = $this->applyFoodPreferenceSystem($selectedCarbs, 'lunch-Carbs', '', 6);

                $options['Carbs'] = ['options' => []];
                foreach ($selectedCarbs as $foodName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($foodName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbs']['options'][] = $portionData;
                    }
                }

                $fatOptions = ['Vegetable oil', 'Peanuts', 'Avocado'];
                $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
                $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies);
                $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'lunch-Fats', '', 3);

                $options['Fats'] = ['options' => []];
                foreach ($filteredFats as $fatName) {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats);
                    if ($portionData) {
                        $options['Fats']['options'][] = $portionData;
                    }
                }
            } else {
                $proteinOptions = ['Canned tuna', 'Chicken thigh', 'Ground beef', 'Whole egg'];
                $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
                $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
                $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

                $options['Proteins'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein);
                    if ($portionData) {
                        $options['Proteins']['options'][] = $portionData;
                    }
                }

                $carbOptions = ['White rice', 'Beans', 'Corn tortilla', 'Potato'];
                $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
                $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 4);
                $filteredCarbs = $this->applyFoodPreferenceSystem($filteredCarbs, 'dinner-Carbs', '', 3);

                $options['Carbs'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbs']['options'][] = $portionData;
                    }
                }

                $fatOptions = ['Vegetable oil', 'Peanuts', 'Avocado'];
                $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
                $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies);
                $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'dinner-Fats', '', 3);

                $options['Fats'] = ['options' => []];
                foreach ($filteredFats as $fatName) {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats);
                    if ($portionData) {
                        $options['Fats']['options'][] = $portionData;
                    }
                }
            }
        } else {
            if ($mealKey === 'breakfast') {
                $proteinOptions = ['Egg whites + Whole egg', 'High-protein Greek yogurt', 'Whey protein'];
                $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
                $forcedFavorites = $this->getForcedFavoritesForMeal('breakfast', 'Proteins', $foodPreferences['proteins'] ?? []);
                $proteinOptions = $this->ensureForcedFavoritesInList($proteinOptions, $forcedFavorites, $allergies, $dislikedFoods);
                $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
                $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

                $options['Proteins'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein, false);
                    if ($portionData) {
                        $options['Proteins']['options'][] = $portionData;
                    }
                }

                $carbOptions = ['Organic oats', 'Artisanal whole wheat bread'];
                $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
                $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

                $options['Carbs'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbs']['options'][] = $portionData;
                    }
                }

                $fatOptions = ['Extra virgin olive oil', 'Almonds', 'Hass avocado'];
                $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
                $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies);
                $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'breakfast-Fats', '', 3);

                $options['Fats'] = ['options' => []];
                foreach ($filteredFats as $fatName) {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats, false);
                    if ($portionData) {
                        $options['Fats']['options'][] = $portionData;
                    }
                }
            } elseif ($mealKey === 'lunch') {
                $proteinOptions = ['Chicken breast', 'Fresh salmon', 'Lean beef', 'Canned tuna', 'Turkey breast'];
                $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
                $forcedFavorites = $this->getForcedFavoritesForMeal('lunch', 'Proteins', $foodPreferences['proteins'] ?? []);
                $proteinOptions = $this->ensureForcedFavoritesInList($proteinOptions, $forcedFavorites, $allergies, $dislikedFoods);
                $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
                $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

                $options['Proteins'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein, false);
                    if ($portionData) {
                        $options['Proteins']['options'][] = $portionData;
                    }
                }

                $carbOrderPreference = ['Potato', 'White rice', 'Sweet potato', 'Noodles', 'Beans', 'Quinoa'];
                $carbOrderPreference = $this->prioritizeFoodList($carbOrderPreference, $foodPreferences['carbs'] ?? []);
                $selectedCarbs = $this->getFilteredFoodOptions($carbOrderPreference, $dislikedFoods, $allergies, 6);
                $selectedCarbs = $this->applyFoodPreferenceSystem($selectedCarbs, 'lunch-Carbs', '', 6);

                $options['Carbs'] = ['options' => []];
                foreach ($selectedCarbs as $foodName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($foodName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbs']['options'][] = $portionData;
                    }
                }

                $fatOptions = ['Extra virgin olive oil', 'Almonds', 'Walnuts', 'Hass avocado'];
                $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
                $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies);
                $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'lunch-Fats', '', 3);

                $options['Fats'] = ['options' => []];
                foreach ($filteredFats as $fatName) {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats, false);
                    if ($portionData) {
                        $options['Fats']['options'][] = $portionData;
                    }
                }
            } else {
                $proteinOptions = [
                    'White fish',
                    'Turkey breast',
                    'Egg whites + Whole egg',
                    'Chicken breast',
                    'Canned tuna',
                    'Lean beef'
                ];
                $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
                $forcedFavorites = $this->getForcedFavoritesForMeal('dinner', 'Proteins', $foodPreferences['proteins'] ?? []);
                $proteinOptions = $this->ensureForcedFavoritesInList($proteinOptions, $forcedFavorites, $allergies, $dislikedFoods);
                $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
                $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

                $options['Proteins'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein, false);
                    if ($portionData) {
                        $options['Proteins']['options'][] = $portionData;
                    }
                }

                $carbOptions = ['White rice', 'Quinoa', 'Beans'];
                $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
                $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

                $options['Carbs'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbs']['options'][] = $portionData;
                    }
                }

                $fatOptions = ['Extra virgin olive oil', 'Almonds', 'Walnuts'];
                $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
                $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies);
                $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, "{$mealKey}-Fats", '', 3);

                $options['Fats'] = ['options' => []];
                foreach ($filteredFats as $fatName) {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats, false);
                    if ($portionData) {
                        $options['Fats']['options'][] = $portionData;
                    }
                }
            }
        }

        foreach ($options as $category => &$categoryData) {
            if (isset($categoryData['options'])) {
                foreach ($categoryData['options'] as &$option) {
                    $this->addFoodMetadata($option, $isLowBudget);
                }
            }
        }

        return $options;
    }

    private function getKetoOptions(
        $mealKey,
        $targetProtein,
        $targetCarbs,
        $targetFats,
        $isLowBudget,
        $dislikedFoods = '',
        $foodPreferences = [],
        $allergies = ''
    ): array {
        $options = [];

        $carbOptions = ['Steamed broccoli', 'Sautéed spinach', 'Lettuce'];
        $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
        $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

        if (!empty($filteredCarbs)) {
            $options['Carbs'] = ['options' => []];
            foreach ($filteredCarbs as $carbName) {
                if ($carbName === 'Steamed broccoli') {
                    $options['Carbs']['options'][] = [
                        'name' => 'Steamed broccoli',
                        'portion' => '100g',
                        'calories' => 28,
                        'protein' => 2,
                        'fats' => 0,
                        'carbohydrates' => 2
                    ];
                } elseif ($carbName === 'Sautéed spinach') {
                    $options['Carbs']['options'][] = [
                        'name' => 'Sautéed spinach',
                        'portion' => '100g',
                        'calories' => 23,
                        'protein' => 3,
                        'fats' => 0,
                        'carbohydrates' => 1
                    ];
                } elseif ($carbName === 'Lettuce') {
                    $options['Carbs']['options'][] = [
                        'name' => 'Lettuce',
                        'portion' => '150g',
                        'calories' => 15,
                        'protein' => 1,
                        'fats' => 0,
                        'carbohydrates' => 2
                    ];
                }
            }
        }

        if ($isLowBudget) {
            $proteinOptions = ['Whole eggs', 'Chicken thigh with skin', 'Ground beef 80/20'];
        } else {
            $proteinOptions = ['Salmon', 'Ribeye', 'Duck breast'];
        }

        $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
        $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
        $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

        if (!empty($filteredProteins)) {
            $options['Proteins'] = ['options' => []];
            foreach ($filteredProteins as $proteinName) {
                if ($proteinName === 'Whole eggs') {
                    $eggUnits = round($targetProtein / 6);
                    if ($eggUnits < 2) $eggUnits = 2;
                    $options['Proteins']['options'][] = [
                        'name' => 'Whole eggs',
                        'portion' => sprintf('%d units', $eggUnits),
                        'calories' => $eggUnits * 70,
                        'protein' => $eggUnits * 6,
                        'fats' => $eggUnits * 5,
                        'carbohydrates' => round($eggUnits * 0.5)
                    ];
                } elseif ($proteinName === 'Chicken thigh with skin') {
                    $grams = round($targetProtein * 3.5);
                    $options['Proteins']['options'][] = [
                        'name' => 'Chicken thigh with skin',
                        'portion' => sprintf('%dg (raw weight)', $grams),
                        'calories' => round($targetProtein * 7.5),
                        'protein' => round($targetProtein),
                        'fats' => round($targetProtein * 0.4),
                        'carbohydrates' => 0
                    ];
                } elseif ($proteinName === 'Ground beef 80/20') {
                    $grams = round($targetProtein * 3.5);
                    $options['Proteins']['options'][] = [
                        'name' => 'Ground beef 80/20',
                        'portion' => sprintf('%dg (raw weight)', $grams),
                        'calories' => round($targetProtein * 8.5),
                        'protein' => round($targetProtein),
                        'fats' => round($targetProtein * 0.5),
                        'carbohydrates' => 0
                    ];
                } elseif ($proteinName === 'Salmon') {
                    $grams = round($targetProtein * 4);
                    $options['Proteins']['options'][] = [
                        'name' => 'Salmon',
                        'portion' => sprintf('%dg (raw weight)', $grams),
                        'calories' => round($targetProtein * 8.3),
                        'protein' => round($targetProtein),
                        'fats' => round($targetProtein * 0.48),
                        'carbohydrates' => 0
                    ];
                } elseif ($proteinName === 'Ribeye') {
                    $grams = round($targetProtein * 3.5);
                    $options['Proteins']['options'][] = [
                        'name' => 'Ribeye',
                        'portion' => sprintf('%dg (raw weight)', $grams),
                        'calories' => round($targetProtein * 10.5),
                        'protein' => round($targetProtein),
                        'fats' => round($targetProtein * 0.7),
                        'carbohydrates' => 0
                    ];
                } elseif ($proteinName === 'Duck breast') {
                    $grams = round($targetProtein * 3.7);
                    $options['Proteins']['options'][] = [
                        'name' => 'Duck breast',
                        'portion' => sprintf('%dg (raw weight)', $grams),
                        'calories' => round($targetProtein * 12),
                        'protein' => round($targetProtein),
                        'fats' => round($targetProtein * 0.8),
                        'carbohydrates' => 0
                    ];
                }
            }
        }

        if ($isLowBudget) {
            $fatOptions = ['Lard', 'Butter', 'Avocado'];
        } else {
            $fatOptions = ['MCT oil', 'Ghee butter', 'Hass avocado'];
        }

        $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
        $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies);
        $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'Keto-Fats', '', 3);

        if (!empty($filteredFats)) {
            $options['Fats'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                if (str_contains($fatName, 'Lard') || str_contains($fatName, 'MCT oil')) {
                    $tbsp = round($targetFats / 12);
                    if ($tbsp < 1) $tbsp = 1;
                    $options['Fats']['options'][] = [
                        'name' => $fatName,
                        'portion' => sprintf('%d tablespoons', $tbsp),
                        'calories' => round($targetFats * 9),
                        'protein' => 0,
                        'fats' => round($targetFats),
                        'carbohydrates' => 0
                    ];
                } elseif (str_contains($fatName, 'Butter')) {
                    $grams = round($targetFats * 1.1);
                    $options['Fats']['options'][] = [
                        'name' => $fatName,
                        'portion' => sprintf('%dg', $grams),
                        'calories' => round($targetFats * 8.5),
                        'protein' => 0,
                        'fats' => round($targetFats),
                        'carbohydrates' => 0
                    ];
                } elseif (str_contains($fatName, 'Avocado')) {
                    $grams = round($targetFats * 6);
                    $options['Fats']['options'][] = [
                        'name' => $fatName,
                        'portion' => sprintf('%dg', $grams),
                        'calories' => round($targetFats * 9.5),
                        'protein' => round($targetFats * 0.15),
                        'fats' => round($targetFats),
                        'carbohydrates' => round($targetFats * 0.15)
                    ];
                }
            }
        }

        foreach ($options as $category => &$categoryData) {
            if (isset($categoryData['options'])) {
                foreach ($categoryData['options'] as &$option) {
                    $this->addFoodMetadata($option, $isLowBudget);
                }
            }
        }

        return $options;
    }

    private function getVegetarianOptions(
        $mealKey,
        $targetProtein,
        $targetCarbs,
        $targetFats,
        $isLowBudget,
        $dislikedFoods = '',
        $foodPreferences = [],
        $allergies = ''
    ): array {
        $options = [];

        if ($mealKey === 'breakfast') {
            if ($isLowBudget) {
                $proteinOptions = ['Whole eggs', 'Natural yogurt', 'Fresh cheese'];
            } else {
                $proteinOptions = ['Whole eggs', 'Greek yogurt', 'Cottage cheese'];
            }

            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredProteins)) {
                $options['Proteins'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    if ($proteinName === 'Whole eggs') {
                        $eggUnits = round($targetProtein / 6);
                        if ($eggUnits < 2) $eggUnits = 2;
                        $options['Proteins']['options'][] = [
                            'name' => 'Whole eggs',
                            'portion' => sprintf('%d units', $eggUnits),
                            'calories' => $eggUnits * 70,
                            'protein' => $eggUnits * 6,
                            'fats' => $eggUnits * 5,
                            'carbohydrates' => round($eggUnits * 0.5)
                        ];
                    } elseif (str_contains($proteinName, 'yogurt')) {
                        $yogurtGrams = round($targetProtein * ($isLowBudget ? 12.5 : 7.7));
                        $options['Proteins']['options'][] = [
                            'name' => $proteinName,
                            'portion' => sprintf('%dg', $yogurtGrams),
                            'calories' => round($yogurtGrams * ($isLowBudget ? 0.61 : 0.9)),
                            'protein' => round($targetProtein),
                            'fats' => round($yogurtGrams * ($isLowBudget ? 0.033 : 0.05)),
                            'carbohydrates' => round($yogurtGrams * ($isLowBudget ? 0.047 : 0.04))
                        ];
                    } elseif (str_contains($proteinName, 'cheese')) {
                        $cheeseGrams = round($targetProtein * 4.5);
                        $options['Proteins']['options'][] = [
                            'name' => $proteinName,
                            'portion' => sprintf('%dg', $cheeseGrams),
                            'calories' => round($cheeseGrams * ($isLowBudget ? 1.85 : 0.98)),
                            'protein' => round($targetProtein),
                            'fats' => round($cheeseGrams * ($isLowBudget ? 0.10 : 0.04)),
                            'carbohydrates' => round($cheeseGrams * ($isLowBudget ? 0.04 : 0.03))
                        ];
                    }
                }
            }

            $carbOptions = $isLowBudget
                ? ['Oats', 'Whole wheat bread', 'Corn tortilla']
                : ['Organic oats', 'Artisanal whole wheat bread', 'Quinoa'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredCarbs)) {
                $options['Carbs'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbs']['options'][] = $portionData;
                    }
                }
            }
        } elseif ($mealKey === 'lunch') {
            if ($isLowBudget) {
                $proteinOptions = ['Cooked lentils', 'Cooked black beans', 'Firm tofu'];
            } else {
                $proteinOptions = ['Tempeh', 'Seitan', 'Grilled panela cheese'];
            }

            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredProteins)) {
                $options['Proteins'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    if (str_contains($proteinName, 'Lentils')) {
                        $grams = round($targetProtein * 11.1);
                        $options['Proteins']['options'][] = [
                            'name' => 'Cooked lentils',
                            'portion' => sprintf('%dg (cooked weight)', $grams),
                            'calories' => round($grams * 1.16),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.004),
                            'carbohydrates' => round($grams * 0.20)
                        ];
                    } elseif (str_contains($proteinName, 'Beans')) {
                        $grams = round($targetProtein * 11.5);
                        $options['Proteins']['options'][] = [
                            'name' => 'Cooked black beans',
                            'portion' => sprintf('%dg (cooked weight)', $grams),
                            'calories' => round($grams * 1.32),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.005),
                            'carbohydrates' => round($grams * 0.24)
                        ];
                    } elseif (str_contains($proteinName, 'Tofu')) {
                        $grams = round($targetProtein * 12.5);
                        $options['Proteins']['options'][] = [
                            'name' => 'Firm tofu',
                            'portion' => sprintf('%dg', $grams),
                            'calories' => round($grams * 1.44),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.09),
                            'carbohydrates' => round($grams * 0.03)
                        ];
                    } elseif (str_contains($proteinName, 'Tempeh')) {
                        $grams = round($targetProtein * 5.3);
                        $options['Proteins']['options'][] = [
                            'name' => 'Tempeh',
                            'portion' => sprintf('%dg', $grams),
                            'calories' => round($grams * 1.93),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.11),
                            'carbohydrates' => round($grams * 0.09)
                        ];
                    } elseif (str_contains($proteinName, 'Seitan')) {
                        $grams = round($targetProtein * 4);
                        $options['Proteins']['options'][] = [
                            'name' => 'Seitan',
                            'portion' => sprintf('%dg', $grams),
                            'calories' => round($grams * 3.7),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.02),
                            'carbohydrates' => round($grams * 0.14)
                        ];
                    } elseif (str_contains($proteinName, 'panela')) {
                        $grams = round($targetProtein * 3.8);
                        $options['Proteins']['options'][] = [
                            'name' => 'Grilled panela cheese',
                            'portion' => sprintf('%dg', $grams),
                            'calories' => round($grams * 3.2),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.22),
                            'carbohydrates' => round($grams * 0.03)
                        ];
                    }
                }
            }

            $carbOptions = ['Potato', 'White rice', 'Sweet potato', 'Whole wheat pasta', 'Quinoa'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 5);

            if (!empty($filteredCarbs)) {
                $options['Carbs'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbs']['options'][] = $portionData;
                    }
                }
            }
        } else {
            if ($isLowBudget) {
                $proteinOptions = ['Scrambled eggs', 'Cooked chickpeas', 'Oaxaca cheese'];
            } else {
                $proteinOptions = ['Greek yogurt with protein granola', 'Plant-based protein powder', 'Ricotta with herbs'];
            }

            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredProteins)) {
                $options['Proteins'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    if (str_contains($proteinName, 'eggs')) {
                        $eggUnits = round($targetProtein / 6);
                        if ($eggUnits < 2) $eggUnits = 2;
                        $options['Proteins']['options'][] = [
                            'name' => 'Scrambled eggs',
                            'portion' => sprintf('%d units', $eggUnits),
                            'calories' => $eggUnits * 70,
                            'protein' => $eggUnits * 6,
                            'fats' => $eggUnits * 5,
                            'carbohydrates' => round($eggUnits * 0.5)
                        ];
                    } elseif (str_contains($proteinName, 'chickpeas')) {
                        $grams = round($targetProtein * 12.2);
                        $options['Proteins']['options'][] = [
                            'name' => 'Cooked chickpeas',
                            'portion' => sprintf('%dg (cooked weight)', $grams),
                            'calories' => round($grams * 1.64),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.03),
                            'carbohydrates' => round($grams * 0.27)
                        ];
                    } elseif (str_contains($proteinName, 'Greek yogurt')) {
                        $grams = round($targetProtein * 5);
                        $options['Proteins']['options'][] = [
                            'name' => 'Greek yogurt with protein granola',
                            'portion' => sprintf('%dg yogurt + 30g granola', $grams),
                            'calories' => round($grams * 0.9 + 150),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.05 + 5),
                            'carbohydrates' => round($grams * 0.04 + 20)
                        ];
                    } elseif (str_contains($proteinName, 'Plant-based protein')) {
                        $grams = round($targetProtein * 1.25);
                        $options['Proteins']['options'][] = [
                            'name' => 'Plant-based protein powder',
                            'portion' => sprintf('%dg (%d scoops)', $grams, max(1, round($grams / 30))),
                            'calories' => round($grams * 3.8),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.02),
                            'carbohydrates' => round($grams * 0.08)
                        ];
                    } elseif (str_contains($proteinName, 'Ricotta')) {
                        $grams = round($targetProtein * 9);
                        $options['Proteins']['options'][] = [
                            'name' => 'Ricotta with herbs',
                            'portion' => sprintf('%dg', $grams),
                            'calories' => round($grams * 1.74),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.13),
                            'carbohydrates' => round($grams * 0.03)
                        ];
                    } else {
                        $grams = round($targetProtein * 5.5);
                        $options['Proteins']['options'][] = [
                            'name' => $proteinName,
                            'portion' => sprintf('%dg', $grams),
                            'calories' => round($grams * 3.5),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.28),
                            'carbohydrates' => round($grams * 0.02)
                        ];
                    }
                }
            }

            $carbOptions = ['White rice', 'Quinoa', 'Beans'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredCarbs)) {
                $options['Carbs'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbs']['options'][] = $portionData;
                    }
                }
            }
        }

        if ($isLowBudget) {
            $fatOptions = ['Vegetable oil', 'Peanut butter', 'Sunflower seeds'];
        } else {
            $fatOptions = ['Extra virgin olive oil', 'Walnuts', 'Chia seeds'];
        }

        $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
        $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies);
        $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, "{$mealKey}-Fats", '', 3);

        if (!empty($filteredFats)) {
            $options['Fats'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats, $isLowBudget);
                if ($portionData) {
                    $options['Fats']['options'][] = $portionData;
                }
            }
        }

        foreach ($options as $category => &$categoryData) {
            if (isset($categoryData['options'])) {
                foreach ($categoryData['options'] as &$option) {
                    $this->addFoodMetadata($option, $isLowBudget);
                }
            }
        }

        return $options;
    }

    private function getVeganOptions(
        $mealKey,
        $targetProtein,
        $targetCarbs,
        $targetFats,
        $isLowBudget,
        $dislikedFoods = '',
        $foodPreferences = [],
        $allergies = ''
    ): array {
        $options = [];

        if ($mealKey === 'breakfast') {
            $proteinOptions = ['Firm tofu', 'Cooked lentils', 'Cooked chickpeas'];
            $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredProteins)) {
                $options['Proteins'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    if ($proteinName === 'Firm tofu') {
                        $tofuGrams = round($targetProtein * 12.5);
                        $options['Proteins']['options'][] = [
                            'name' => 'Firm tofu',
                            'portion' => sprintf('%dg', $tofuGrams),
                            'calories' => round($tofuGrams * 1.44),
                            'protein' => round($targetProtein),
                            'fats' => round($tofuGrams * 0.09),
                            'carbohydrates' => round($tofuGrams * 0.03)
                        ];
                    } elseif ($proteinName === 'Cooked lentils') {
                        $lentejasGrams = round($targetProtein * 11);
                        $options['Proteins']['options'][] = [
                            'name' => 'Cooked lentils',
                            'portion' => sprintf('%dg (cooked weight)', $lentejasGrams),
                            'calories' => round($lentejasGrams * 1.16),
                            'protein' => round($targetProtein),
                            'fats' => round($lentejasGrams * 0.004),
                            'carbohydrates' => round($lentejasGrams * 0.2)
                        ];
                    } elseif ($proteinName === 'Cooked chickpeas') {
                        $garbanzosGrams = round($targetProtein * 12);
                        $options['Proteins']['options'][] = [
                            'name' => 'Cooked chickpeas',
                            'portion' => sprintf('%dg (cooked weight)', $garbanzosGrams),
                            'calories' => round($garbanzosGrams * 1.64),
                            'protein' => round($targetProtein),
                            'fats' => round($garbanzosGrams * 0.03),
                            'carbohydrates' => round($garbanzosGrams * 0.27)
                        ];
                    }
                }
            }

            $carbOptions = ['Traditional oats', 'Whole wheat bread', 'Cooked quinoa'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredCarbs)) {
                $options['Carbs'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbs']['options'][] = $portionData;
                    }
                }
            }
        } elseif ($mealKey === 'lunch' || $mealKey === 'dinner') {
            $proteinOptions = ['Seitan', 'Tempeh', 'Lentil burger'];
            $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredProteins)) {
                $options['Proteins'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    if ($proteinName === 'Seitan') {
                        $seitanGrams = round($targetProtein * 4);
                        $options['Proteins']['options'][] = [
                            'name' => 'Seitan',
                            'portion' => sprintf('%dg', $seitanGrams),
                            'calories' => round($seitanGrams * 3.7),
                            'protein' => round($targetProtein),
                            'fats' => round($seitanGrams * 0.02),
                            'carbohydrates' => round($seitanGrams * 0.14)
                        ];
                    } elseif ($proteinName === 'Tempeh') {
                        $tempehGrams = round($targetProtein * 5.3);
                        $options['Proteins']['options'][] = [
                            'name' => 'Tempeh',
                            'portion' => sprintf('%dg', $tempehGrams),
                            'calories' => round($tempehGrams * 1.93),
                            'protein' => round($targetProtein),
                            'fats' => round($tempehGrams * 0.11),
                            'carbohydrates' => round($tempehGrams * 0.09)
                        ];
                    } elseif ($proteinName === 'Lentil burger') {
                        $hamburguesaGrams = round($targetProtein * 6);
                        $options['Proteins']['options'][] = [
                            'name' => 'Lentil burger',
                            'portion' => sprintf('%dg (2 units)', $hamburguesaGrams),
                            'calories' => round($targetProtein * 7),
                            'protein' => round($targetProtein),
                            'fats' => round($targetProtein * 0.3),
                            'carbohydrates' => round($targetProtein * 1.5)
                        ];
                    }
                }
            }

            $carbOptions = ['White rice', 'Potato', 'Quinoa'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredCarbs)) {
                $options['Carbs'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbs']['options'][] = $portionData;
                    }
                }
            }
        }

        if ($isLowBudget) {
            $fatOptions = ['Vegetable oil', 'Peanuts', 'Avocado'];
        } else {
            $fatOptions = ['Extra virgin olive oil', 'Almonds', 'Hass avocado'];
        }

        $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
        $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies);
        $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, "{$mealKey}-Fats", '', 3);

        if (!empty($filteredFats)) {
            $options['Fats'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats, $isLowBudget);
                if ($portionData) {
                    $options['Fats']['options'][] = $portionData;
                }
            }
        }

        foreach ($options as $category => &$categoryData) {
            if (isset($categoryData['options'])) {
                foreach ($categoryData['options'] as &$option) {
                    $this->addFoodMetadata($option, $isLowBudget);
                }
            }
        }

        return $options;
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