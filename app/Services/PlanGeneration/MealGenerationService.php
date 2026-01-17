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
                'Desayuno' => 0.30,
                'Almuerzo' => 0.40,
                'Cena' => 0.20
            ];

            if ($preferredSnackTime === 'Snack AM') {
                $mealDistribution['Snack AM'] = 0.10;
                $personalizedMessage = "Hola {$userName}, tu plan incluye 3 comidas principales (Desayuno, Almuerzo, Cena) y un snack en la media mañana, como prefieres.";
            } elseif ($preferredSnackTime === 'Snack PM') {
                $mealDistribution['Snack PM'] = 0.10;
                $personalizedMessage = "Hola {$userName}, tu plan incluye 3 comidas principales (Desayuno, Almuerzo, Cena) y un snack en la media tarde, como prefieres.";
            } else {
                $mealDistribution['Snack AM'] = 0.10;
                $personalizedMessage = "Hola {$userName}, tu plan incluye 3 comidas principales (Desayuno, Almuerzo, Cena) y un snack en la media mañana.";

                Log::warning("Preferencia de snack no válida, usando Snack AM por defecto", [
                    'received' => $preferredSnackTime,
                    'user_id' => $profile->user_id
                ]);
            }

            $budget = strtolower($nutritionalData['basic_data']['preferences']['budget'] ?? '');
            $isLowBudget = str_contains($budget, 'bajo');
            $dietaryStyle = strtolower($nutritionalData['basic_data']['preferences']['dietary_style'] ?? 'omnívoro');
            $dislikedFoods = $nutritionalData['basic_data']['preferences']['disliked_foods'] ?? '';
            $allergies = $nutritionalData['basic_data']['health_status']['allergies'] ?? '';

            Log::info("🔍 Restricciones alimentarias del usuario", [
                'user_id' => $profile->user_id,
                'disliked_foods' => $dislikedFoods,
                'allergies' => $allergies
            ]);

            $meals = [];

            foreach ($mealDistribution as $mealName => $percentage) {
                $mealProtein = round($macros['protein']['grams'] * $percentage);
                $mealCarbs = round($macros['carbohydrates']['grams'] * $percentage);
                $mealFats = round($macros['fats']['grams'] * $percentage);
                $mealCalories = round($macros['calories'] * $percentage);

                if (str_contains($mealName, 'Snack')) {
                    $snackType = str_contains($mealName, 'AM') ? 'AM' : 'PM';
                    $meals[$mealName] = $this->generateSnackOptions(
                        $mealCalories,
                        $isLowBudget,
                        $snackType,
                        $dislikedFoods,
                        $allergies
                    );
                } else {
                    $meals[$mealName] = $this->generateDeterministicMealOptions(
                        $mealName,
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

                foreach ($meals[$mealName] as $category => &$categoryData) {
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
                'Desayuno' => '07:00',
                'Snack AM' => '10:00',
                'Almuerzo' => '13:00',
                'Snack PM' => '16:00',
                'Cena' => '20:00',
            ];

            foreach ($meals as $mealName => &$mealData) {
                if (isset($mealTimings[$mealName])) {
                    $mealData['meal_timing'] = $mealTimings[$mealName];
                }
            }

            $generalRecommendations = [
                'Hidratación: consume al menos 2 litros de agua al día',
                'Las 3 comidas principales son obligatorias: Desayuno, Almuerzo y Cena',
                'Tu snack es para ' . ($preferredSnackTime === 'Snack AM' ? 'media mañana' : 'media tarde'),
                'Respeta los horarios para optimizar tu metabolismo',
                'Los vegetales son libres en todas las comidas principales'
            ];

            $nutritionalSummary = [
                'tmb' => $nutritionalData['tmb'] ?? 0,
                'get' => $nutritionalData['get'] ?? 0,
                'targetCalories' => $nutritionalData['target_calories'] ?? 0,
                'goal' => $nutritionalData['basic_data']['goal'] ?? 'Bajar grasa',
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
                    'mealStructure' => '3 comidas principales + 1 snack (' . $preferredSnackTime . ')',
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

    public function generateSnackOptions($targetCalories, $isLowBudget, $snackType = 'AM', $dislikedFoods = '', $allergies = ''): array
    {
        $targetProtein = round($targetCalories * 0.30 / 4);
        $targetCarbs = round($targetCalories * 0.50 / 4);
        $targetFats = round($targetCalories * 0.20 / 9);

        $options = [];

        if ($isLowBudget) {
            $proteinOptions = ['Yogurt griego', 'Atún en lata'];
        } else {
            $proteinOptions = ['Proteína en polvo', 'Yogurt griego alto en proteína', 'Caseína'];
        }

        $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

        if (!empty($filteredProteins)) {
            $options['Proteínas'] = ['options' => []];

            foreach ($filteredProteins as $proteinName) {
                $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein, $isLowBudget);
                if ($portionData) {
                    $options['Proteínas']['options'][] = $portionData;
                }
            }
        }

        $carbOptions = ['Cereal de maíz', 'Crema de arroz', 'Galletas de arroz', 'Avena'];
        $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 4);

        if (!empty($filteredCarbs)) {
            $options['Carbohidratos'] = ['options' => []];

            foreach ($filteredCarbs as $carbName) {
                $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                if ($portionData) {
                    $options['Carbohidratos']['options'][] = $portionData;
                }
            }
        }

        if ($isLowBudget) {
            $fatOptions = ['Mantequilla de maní casera', 'Maní'];
        } else {
            $fatOptions = ['Mantequilla de maní', 'Miel', 'Chocolate negro 70%'];
        }

        $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
        $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, "Snack-{$snackType}-Grasas", '', 3);

        if (!empty($filteredFats)) {
            $options['Grasas'] = ['options' => []];

            foreach ($filteredFats as $fatName) {
                if ($fatName === 'Miel') {
                    $gramsNeeded = round($targetFats * 3);
                    $options['Grasas']['options'][] = [
                        'name' => 'Miel',
                        'portion' => "{$gramsNeeded}g",
                        'calories' => round($targetFats * 9),
                        'protein' => 0,
                        'fats' => 0,
                        'carbohydrates' => round($targetFats * 2.5)
                    ];
                } elseif ($fatName === 'Chocolate negro 70%') {
                    $gramsNeeded = round($targetFats * 1.8);
                    $options['Grasas']['options'][] = [
                        'name' => 'Chocolate negro 70%',
                        'portion' => "{$gramsNeeded}g",
                        'calories' => round($targetFats * 10),
                        'protein' => round($targetFats * 0.15),
                        'fats' => $targetFats,
                        'carbohydrates' => round($targetFats * 0.8)
                    ];
                } else {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats, $isLowBudget);
                    if ($portionData) {
                        $options['Grasas']['options'][] = $portionData;
                    }
                }
            }
        }

        $options['meal_timing'] = $snackType === 'AM' ? '10:00' : '16:00';
        $options['personalized_tips'] = [
            $snackType === 'AM' ?
                'Snack de media mañana para mantener energía' :
                'Snack de media tarde para evitar llegar con mucha hambre a la cena'
        ];

        return $options;
    }

    private function generateDeterministicMealOptions(
        $mealName,
        $targetProtein,
        $targetCarbs,
        $targetFats,
        $isLowBudget,
        $userWeight,
        $dietaryStyle,
        $dislikedFoods = '',
        $foodPreferences = [],
        $allergies = ''
    ): array
    {
        $mealCalories = ($targetProtein * 4) + ($targetCarbs * 4) + ($targetFats * 9);

        $targetProtein = round(($mealCalories * 0.40) / 4);
        $targetCarbs = round(($mealCalories * 0.40) / 4);
        $targetFats = round(($mealCalories * 0.20) / 9);

        Log::info("Macros recalculados para {$mealName} con ratio 40/40/20", [
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

        if (str_contains($dietaryStyle, 'vegano')) {
            $options = $this->getVeganOptions($mealName, $adjustedProtein, $adjustedCarbs, $adjustedFats, $isLowBudget, $dislikedFoods, $foodPreferences, $allergies);
        }
        elseif (str_contains($dietaryStyle, 'vegetariano')) {
            $options = $this->getVegetarianOptions($mealName, $adjustedProtein, $adjustedCarbs, $adjustedFats, $isLowBudget, $dislikedFoods, $foodPreferences, $allergies);
        }
        elseif (str_contains($dietaryStyle, 'keto')) {
            $options = $this->getKetoOptions($mealName, $adjustedProtein, $adjustedCarbs, $adjustedFats, $isLowBudget, $dislikedFoods, $foodPreferences, $allergies);
        }
        else {
            $options = $this->getOmnivorousOptions($mealName, $adjustedProtein, $adjustedCarbs, $adjustedFats, $isLowBudget, $dislikedFoods, $foodPreferences, $allergies);
        }

        if (str_contains($dietaryStyle, 'keto')) {
            $options['Vegetales'] = [
                'requirement' => 'minimum',
                'min_calories' => 100,
                'max_calories' => 150,
                'recommendation' => 'Consumo mínimo de 100 kcal en vegetales por comida principal',
                'options' => [
                    [
                        'name' => 'Ensalada verde mixta grande',
                        'portion' => '400g (2 tazas grandes)',
                        'calories' => 100,
                        'protein' => 4,
                        'fats' => 0,
                        'carbohydrates' => 15,
                        'fiber' => 8,
                        'portion_examples' => '2 tazas de lechuga + 1 taza de espinaca + 1/2 taza pepino + 1/4 taza pimiento'
                    ],
                    [
                        'name' => 'Ensalada de vegetales crucíferos',
                        'portion' => '350g (2 tazas)',
                        'calories' => 105,
                        'protein' => 5,
                        'fats' => 0,
                        'carbohydrates' => 16,
                        'fiber' => 9,
                        'portion_examples' => '1 taza brócoli + 1 taza coliflor + 1/2 taza col morada'
                    ],
                    [
                        'name' => 'Mix de vegetales bajos en carbos',
                        'portion' => '380g',
                        'calories' => 110,
                        'protein' => 4,
                        'fats' => 0,
                        'carbohydrates' => 17,
                        'fiber' => 7,
                        'portion_examples' => '1.5 tazas espinaca + 1/2 taza champiñones + 1/2 taza calabacín + tomates cherry'
                    ]
                ]
            ];
        } else {
            $options['Vegetales'] = [
                'requirement' => 'minimum',
                'min_calories' => 100,
                'max_calories' => 150,
                'recommendation' => 'Consumo mínimo de 100 kcal en vegetales por comida principal',
                'options' => [
                    [
                        'name' => 'Ensalada completa mixta',
                        'portion' => '350g (2.5 tazas)',
                        'calories' => 100,
                        'protein' => 4,
                        'fats' => 0,
                        'carbohydrates' => 18,
                        'fiber' => 6,
                        'portion_examples' => '2 tazas lechuga mixta + 1 tomate mediano + 1/2 taza zanahoria rallada + 1/4 taza cebolla'
                    ],
                    [
                        'name' => 'Bowl de vegetales al vapor',
                        'portion' => '300g (2 tazas)',
                        'calories' => 110,
                        'protein' => 5,
                        'fats' => 0,
                        'carbohydrates' => 20,
                        'fiber' => 8,
                        'portion_examples' => '1 taza brócoli + 1/2 taza zanahoria + 1/2 taza ejotes + 1/2 taza calabaza'
                    ],
                    [
                        'name' => 'Ensalada mediterránea',
                        'portion' => '320g (2 tazas)',
                        'calories' => 105,
                        'protein' => 4,
                        'fats' => 0,
                        'carbohydrates' => 19,
                        'fiber' => 7,
                        'portion_examples' => '1.5 tazas lechuga + 1 tomate + 1/2 pepino + 1/4 taza pimiento + cebolla morada'
                    ],
                    [
                        'name' => 'Vegetales salteados',
                        'portion' => '280g (2 tazas)',
                        'calories' => 120,
                        'protein' => 5,
                        'fats' => 1,
                        'carbohydrates' => 22,
                        'fiber' => 8,
                        'portion_examples' => '1 taza brócoli + 1/2 taza pimiento + 1/2 taza cebolla + 1/2 taza calabacín'
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

            return ['Arroz blanco', 'Papa', 'Lentejas'];
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


 

 
    private function getOmnivorousOptions(
        $mealName,
        $targetProtein,
        $targetCarbs,
        $targetFats,
        $isLowBudget,
        $dislikedFoods = '',
        $foodPreferences = [],
        $allergies = ''
    ): array
    {
        $options = [];

        if ($isLowBudget) {
            if ($mealName === 'Desayuno') {
                $proteinOptions = ['Huevo entero', 'Atún en lata', 'Pollo muslo'];
                $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
                $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
                $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

                $options['Proteínas'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein);
                    if ($portionData) {
                        $options['Proteínas']['options'][] = $portionData;
                    }
                }

                $carbOptions = ['Avena', 'Pan integral', 'Tortilla de maíz'];
                $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
                $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }

                $fatOptions = ['Aceite vegetal', 'Maní', 'Aguacate'];
                $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
                $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
                $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'Desayuno-Grasas', '', 3);

                $options['Grasas'] = ['options' => []];
                foreach ($filteredFats as $fatName) {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats);
                    if ($portionData) {
                        $options['Grasas']['options'][] = $portionData;
                    }
                }

            } elseif ($mealName === 'Almuerzo') {
                $proteinOptions = ['Pollo muslo', 'Carne molida', 'Atún en lata', 'Pechuga de pollo'];

                $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
                $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
                $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

                $options['Proteínas'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein);
                    if ($portionData) {
                        $options['Proteínas']['options'][] = $portionData;
                    }
                }

                $carbOrderPreference = ['Papa', 'Arroz blanco', 'Camote', 'Fideo', 'Frijoles', 'Quinua'];
                $carbOrderPreference = $this->prioritizeFoodList($carbOrderPreference, $foodPreferences['carbs'] ?? []);
                $selectedCarbs = $this->getFilteredFoodOptions($carbOrderPreference, $dislikedFoods, $allergies, 6);
                $selectedCarbs = $this->applyFoodPreferenceSystem($selectedCarbs, 'Almuerzo-Carbos', '', 6);

                $options['Carbohidratos'] = ['options' => []];
                foreach ($selectedCarbs as $foodName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($foodName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }

                $fatOptions = ['Aceite vegetal', 'Maní', 'Aguacate'];
                $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
                $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
                $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'Almuerzo-Grasas', '', 3);

                $options['Grasas'] = ['options' => []];
                foreach ($filteredFats as $fatName) {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats);
                    if ($portionData) {
                        $options['Grasas']['options'][] = $portionData;
                    }
                }

            } else {
                $proteinOptions = ['Atún en lata', 'Pollo muslo', 'Carne molida', 'Huevo entero'];

                $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
                $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
                $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

                $options['Proteínas'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein);
                    if ($portionData) {
                        $options['Proteínas']['options'][] = $portionData;
                    }
                }

                $carbOptions = ['Arroz blanco', 'Frijoles', 'Tortilla de maíz', 'Papa'];
                $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
                $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 4);
                $filteredCarbs = $this->applyFoodPreferenceSystem($filteredCarbs, 'Cena-Carbos', '', 3);

                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }

                $fatOptions = ['Aceite vegetal', 'Maní', 'Aguacate'];
                $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
                $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
                $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'Cena-Grasas', '', 3);

                $options['Grasas'] = ['options' => []];
                foreach ($filteredFats as $fatName) {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats);
                    if ($portionData) {
                        $options['Grasas']['options'][] = $portionData;
                    }
                }
            }
        } else {
            if ($mealName === 'Desayuno') {
                $proteinOptions = ['Claras + Huevo entero', 'Yogurt griego alto en proteínas', 'Proteína whey'];
                $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);

                $forcedFavorites = $this->getForcedFavoritesForMeal('Desayuno', 'Proteínas', $foodPreferences['proteins'] ?? []);
                $proteinOptions = $this->ensureForcedFavoritesInList($proteinOptions, $forcedFavorites, $allergies, $dislikedFoods);

                $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
                $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

                $options['Proteínas'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein, false);
                    if ($portionData) {
                        $options['Proteínas']['options'][] = $portionData;
                    }
                }

                $carbOptions = ['Avena orgánica', 'Pan integral artesanal'];
                $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
                $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }

                $fatOptions = ['Aceite de oliva extra virgen', 'Almendras', 'Aguacate hass'];
                $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
                $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
                $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'Desayuno-Grasas', '', 3);

                $options['Grasas'] = ['options' => []];
                foreach ($filteredFats as $fatName) {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats, false);
                    if ($portionData) {
                        $options['Grasas']['options'][] = $portionData;
                    }
                }

            } elseif ($mealName === 'Almuerzo') {
                $proteinOptions = ['Pechuga de pollo', 'Salmón fresco', 'Carne de res magra', 'Atún en lata', 'Pechuga de pavo'];
                $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);

                $forcedFavorites = $this->getForcedFavoritesForMeal('Almuerzo', 'Proteínas', $foodPreferences['proteins'] ?? []);
                $proteinOptions = $this->ensureForcedFavoritesInList($proteinOptions, $forcedFavorites, $allergies, $dislikedFoods);

                $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
                $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

                $options['Proteínas'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein, false);
                    if ($portionData) {
                        $options['Proteínas']['options'][] = $portionData;
                    }
                }

                $carbOrderPreference = ['Papa', 'Arroz blanco', 'Camote', 'Fideo', 'Frijoles', 'Quinua'];
                $carbOrderPreference = $this->prioritizeFoodList($carbOrderPreference, $foodPreferences['carbs'] ?? []);
                $selectedCarbs = $this->getFilteredFoodOptions($carbOrderPreference, $dislikedFoods, $allergies, 6);
                $selectedCarbs = $this->applyFoodPreferenceSystem($selectedCarbs, 'Almuerzo-Carbos', '', 6);

                $options['Carbohidratos'] = ['options' => []];
                foreach ($selectedCarbs as $foodName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($foodName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }

                $fatOptions = ['Aceite de oliva extra virgen', 'Almendras', 'Nueces', 'Aguacate hass'];
                $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
                $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
                $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'Almuerzo-Grasas', '', 3);

                $options['Grasas'] = ['options' => []];
                foreach ($filteredFats as $fatName) {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats, false);
                    if ($portionData) {
                        $options['Grasas']['options'][] = $portionData;
                    }
                }

            } else {
                $proteinOptions = [
                    'Pescado blanco',
                    'Pechuga de pavo',
                    'Claras + Huevo entero',
                    'Pechuga de pollo',
                    'Atún en lata',
                    'Carne de res magra'
                ];
                $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);

                $forcedFavorites = $this->getForcedFavoritesForMeal('Cena', 'Proteínas', $foodPreferences['proteins'] ?? []);
                $proteinOptions = $this->ensureForcedFavoritesInList($proteinOptions, $forcedFavorites, $allergies, $dislikedFoods);

                $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
                $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

                $options['Proteínas'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    $portionData = $this->foodCalculationService->calculateProteinPortionByFood($proteinName, $targetProtein, false);
                    if ($portionData) {
                        $options['Proteínas']['options'][] = $portionData;
                    }
                }

                $carbOptions = ['Arroz blanco', 'Quinua', 'Frijoles'];
                $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
                $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }

                $fatOptions = ['Aceite de oliva extra virgen', 'Almendras', 'Nueces'];
                $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
                $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
                $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, "{$mealName}-Grasas", '', 3);

                $options['Grasas'] = ['options' => []];
                foreach ($filteredFats as $fatName) {
                    $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats, false);
                    if ($portionData) {
                        $options['Grasas']['options'][] = $portionData;
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
        $mealName,
        $targetProtein,
        $targetCarbs,
        $targetFats,
        $isLowBudget,
        $dislikedFoods = '',
        $foodPreferences = [],
        $allergies = ''
    ): array
    {
        $options = [];

        $carbOptions = ['Brócoli al vapor', 'Espinacas salteadas', 'Lechuga'];
        $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
        $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

        if (!empty($filteredCarbs)) {
            $options['Carbohidratos'] = ['options' => []];
            foreach ($filteredCarbs as $carbName) {
                if ($carbName === 'Brócoli al vapor') {
                    $options['Carbohidratos']['options'][] = [
                        'name' => 'Brócoli al vapor',
                        'portion' => '100g',
                        'calories' => 28,
                        'protein' => 2,
                        'fats' => 0,
                        'carbohydrates' => 2
                    ];
                } elseif ($carbName === 'Espinacas salteadas') {
                    $options['Carbohidratos']['options'][] = [
                        'name' => 'Espinacas salteadas',
                        'portion' => '100g',
                        'calories' => 23,
                        'protein' => 3,
                        'fats' => 0,
                        'carbohydrates' => 1
                    ];
                } elseif ($carbName === 'Lechuga') {
                    $options['Carbohidratos']['options'][] = [
                        'name' => 'Lechuga',
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
            $proteinOptions = ['Huevos enteros', 'Pollo muslo con piel', 'Carne molida 80/20'];
        } else {
            $proteinOptions = ['Salmón', 'Ribeye', 'Pechuga de pato'];
        }
        $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
        $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
        $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

        if (!empty($filteredProteins)) {
            $options['Proteínas'] = ['options' => []];
            foreach ($filteredProteins as $proteinName) {
                if ($proteinName === 'Huevos enteros') {
                    $eggUnits = round($targetProtein / 6);
                    if ($eggUnits < 2) $eggUnits = 2;
                    $options['Proteínas']['options'][] = [
                        'name' => 'Huevos enteros',
                        'portion' => sprintf('%d unidades', $eggUnits),
                        'calories' => $eggUnits * 70,
                        'protein' => $eggUnits * 6,
                        'fats' => $eggUnits * 5,
                        'carbohydrates' => round($eggUnits * 0.5)
                    ];
                } elseif ($proteinName === 'Pollo muslo con piel') {
                    $grams = round($targetProtein * 3.5);
                    $options['Proteínas']['options'][] = [
                        'name' => 'Pollo muslo con piel',
                        'portion' => sprintf('%dg (peso en crudo)', $grams),
                        'calories' => round($targetProtein * 7.5),
                        'protein' => round($targetProtein),
                        'fats' => round($targetProtein * 0.4),
                        'carbohydrates' => 0
                    ];
                } elseif ($proteinName === 'Carne molida 80/20') {
                    $grams = round($targetProtein * 3.5);
                    $options['Proteínas']['options'][] = [
                        'name' => 'Carne molida 80/20',
                        'portion' => sprintf('%dg (peso en crudo)', $grams),
                        'calories' => round($targetProtein * 8.5),
                        'protein' => round($targetProtein),
                        'fats' => round($targetProtein * 0.5),
                        'carbohydrates' => 0
                    ];
                } elseif ($proteinName === 'Salmón') {
                    $grams = round($targetProtein * 4);
                    $options['Proteínas']['options'][] = [
                        'name' => 'Salmón',
                        'portion' => sprintf('%dg (peso en crudo)', $grams),
                        'calories' => round($targetProtein * 8.3),
                        'protein' => round($targetProtein),
                        'fats' => round($targetProtein * 0.48),
                        'carbohydrates' => 0
                    ];
                } elseif ($proteinName === 'Ribeye') {
                    $grams = round($targetProtein * 3.5);
                    $options['Proteínas']['options'][] = [
                        'name' => 'Ribeye',
                        'portion' => sprintf('%dg (peso en crudo)', $grams),
                        'calories' => round($targetProtein * 10.5),
                        'protein' => round($targetProtein),
                        'fats' => round($targetProtein * 0.7),
                        'carbohydrates' => 0
                    ];
                } elseif ($proteinName === 'Pechuga de pato') {
                    $grams = round($targetProtein * 3.7);
                    $options['Proteínas']['options'][] = [
                        'name' => 'Pechuga de pato',
                        'portion' => sprintf('%dg (peso en crudo)', $grams),
                        'calories' => round($targetProtein * 12),
                        'protein' => round($targetProtein),
                        'fats' => round($targetProtein * 0.8),
                        'carbohydrates' => 0
                    ];
                }
            }
        }

        if ($isLowBudget) {
            $fatOptions = ['Manteca de cerdo', 'Mantequilla', 'Aguacate'];
        } else {
            $fatOptions = ['Aceite MCT', 'Mantequilla ghee', 'Aguacate hass'];
        }
        $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
        $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
        $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'Keto-Grasas', '', 3);

        if (!empty($filteredFats)) {
            $options['Grasas'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                if (str_contains($fatName, 'Manteca') || str_contains($fatName, 'Aceite MCT')) {
                    $tbsp = round($targetFats / 12);
                    if ($tbsp < 1) $tbsp = 1;
                    $options['Grasas']['options'][] = [
                        'name' => $fatName,
                        'portion' => sprintf('%d cucharadas', $tbsp),
                        'calories' => round($targetFats * 9),
                        'protein' => 0,
                        'fats' => round($targetFats),
                        'carbohydrates' => 0
                    ];
                } elseif (str_contains($fatName, 'Mantequilla')) {
                    $grams = round($targetFats * 1.1);
                    $options['Grasas']['options'][] = [
                        'name' => $fatName,
                        'portion' => sprintf('%dg', $grams),
                        'calories' => round($targetFats * 8.5),
                        'protein' => 0,
                        'fats' => round($targetFats),
                        'carbohydrates' => 0
                    ];
                } elseif (str_contains($fatName, 'Aguacate')) {
                    $grams = round($targetFats * 6);
                    $options['Grasas']['options'][] = [
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
        $mealName,
        $targetProtein,
        $targetCarbs,
        $targetFats,
        $isLowBudget,
        $dislikedFoods = '',
        $foodPreferences = [],
        $allergies = ''
    ): array
    {
        $options = [];

        if ($mealName === 'Desayuno') {
            if ($isLowBudget) {
                $proteinOptions = ['Huevos enteros', 'Yogurt natural', 'Queso fresco'];
            } else {
                $proteinOptions = ['Huevos enteros', 'Yogurt griego', 'Queso cottage'];
            }
            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredProteins)) {
                $options['Proteínas'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    if ($proteinName === 'Huevos enteros') {
                        $eggUnits = round($targetProtein / 6);
                        if ($eggUnits < 2) $eggUnits = 2;
                        $options['Proteínas']['options'][] = [
                            'name' => 'Huevos enteros',
                            'portion' => sprintf('%d unidades', $eggUnits),
                            'calories' => $eggUnits * 70,
                            'protein' => $eggUnits * 6,
                            'fats' => $eggUnits * 5,
                            'carbohydrates' => round($eggUnits * 0.5)
                        ];
                    } elseif (str_contains($proteinName, 'Yogurt')) {
                        $yogurtGrams = round($targetProtein * ($isLowBudget ? 12.5 : 7.7));
                        $options['Proteínas']['options'][] = [
                            'name' => $proteinName,
                            'portion' => sprintf('%dg', $yogurtGrams),
                            'calories' => round($yogurtGrams * ($isLowBudget ? 0.61 : 0.9)),
                            'protein' => round($targetProtein),
                            'fats' => round($yogurtGrams * ($isLowBudget ? 0.033 : 0.05)),
                            'carbohydrates' => round($yogurtGrams * ($isLowBudget ? 0.047 : 0.04))
                        ];
                    } elseif (str_contains($proteinName, 'Queso')) {
                        $cheeseGrams = round($targetProtein * 4.5);
                        $options['Proteínas']['options'][] = [
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
                ? ['Avena', 'Pan integral', 'Tortilla de maíz']
                : ['Avena orgánica', 'Pan integral artesanal', 'Quinua'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredCarbs)) {
                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }
            }

        } elseif ($mealName === 'Almuerzo') {
            if ($isLowBudget) {
                $proteinOptions = ['Lentejas cocidas', 'Frijoles negros cocidos', 'Tofu firme'];
            } else {
                $proteinOptions = ['Tempeh', 'Seitán', 'Queso panela a la plancha'];
            }
            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredProteins)) {
                $options['Proteínas'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    if (str_contains($proteinName, 'Lentejas')) {
                        $grams = round($targetProtein * 11.1);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Lentejas cocidas',
                            'portion' => sprintf('%dg (peso cocido)', $grams),
                            'calories' => round($grams * 1.16),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.004),
                            'carbohydrates' => round($grams * 0.20)
                        ];
                    } elseif (str_contains($proteinName, 'Frijoles')) {
                        $grams = round($targetProtein * 11.5);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Frijoles negros cocidos',
                            'portion' => sprintf('%dg (peso cocido)', $grams),
                            'calories' => round($grams * 1.32),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.005),
                            'carbohydrates' => round($grams * 0.24)
                        ];
                    } elseif (str_contains($proteinName, 'Tofu')) {
                        $grams = round($targetProtein * 12.5);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Tofu firme',
                            'portion' => sprintf('%dg', $grams),
                            'calories' => round($grams * 1.44),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.09),
                            'carbohydrates' => round($grams * 0.03)
                        ];
                    } elseif (str_contains($proteinName, 'Tempeh')) {
                        $grams = round($targetProtein * 5.3);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Tempeh',
                            'portion' => sprintf('%dg', $grams),
                            'calories' => round($grams * 1.93),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.11),
                            'carbohydrates' => round($grams * 0.09)
                        ];
                    } elseif (str_contains($proteinName, 'Seitán')) {
                        $grams = round($targetProtein * 4);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Seitán',
                            'portion' => sprintf('%dg', $grams),
                            'calories' => round($grams * 3.7),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.02),
                            'carbohydrates' => round($grams * 0.14)
                        ];
                    } elseif (str_contains($proteinName, 'Queso panela')) {
                        $grams = round($targetProtein * 3.8);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Queso panela a la plancha',
                            'portion' => sprintf('%dg', $grams),
                            'calories' => round($grams * 3.2),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.22),
                            'carbohydrates' => round($grams * 0.03)
                        ];
                    }
                }
            }

            $carbOptions = ['Papa', 'Arroz blanco', 'Camote', 'Pasta integral', 'Quinua'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 5);

            if (!empty($filteredCarbs)) {
                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }
            }

        } else {
            if ($isLowBudget) {
                $proteinOptions = ['Huevos revueltos', 'Garbanzos cocidos', 'Queso Oaxaca'];
            } else {
                $proteinOptions = ['Yogurt griego con granola proteica', 'Proteína vegetal en polvo', 'Ricotta con hierbas'];
            }
            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredProteins)) {
                $options['Proteínas'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    if (str_contains($proteinName, 'Huevos')) {
                        $eggUnits = round($targetProtein / 6);
                        if ($eggUnits < 2) $eggUnits = 2;
                        $options['Proteínas']['options'][] = [
                            'name' => 'Huevos revueltos',
                            'portion' => sprintf('%d unidades', $eggUnits),
                            'calories' => $eggUnits * 70,
                            'protein' => $eggUnits * 6,
                            'fats' => $eggUnits * 5,
                            'carbohydrates' => round($eggUnits * 0.5)
                        ];
                    } elseif (str_contains($proteinName, 'Garbanzos')) {
                        $grams = round($targetProtein * 12.2);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Garbanzos cocidos',
                            'portion' => sprintf('%dg (peso cocido)', $grams),
                            'calories' => round($grams * 1.64),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.03),
                            'carbohydrates' => round($grams * 0.27)
                        ];
                    } elseif (str_contains($proteinName, 'Yogurt')) {
                        $grams = round($targetProtein * 5);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Yogurt griego con granola proteica',
                            'portion' => sprintf('%dg yogurt + 30g granola', $grams),
                            'calories' => round($grams * 0.9 + 150),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.05 + 5),
                            'carbohydrates' => round($grams * 0.04 + 20)
                        ];
                    } elseif (str_contains($proteinName, 'Proteína vegetal')) {
                        $grams = round($targetProtein * 1.25);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Proteína vegetal en polvo',
                            'portion' => sprintf('%dg (%d scoops)', $grams, max(1, round($grams / 30))),
                            'calories' => round($grams * 3.8),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.02),
                            'carbohydrates' => round($grams * 0.08)
                        ];
                    } elseif (str_contains($proteinName, 'Ricotta')) {
                        $grams = round($targetProtein * 9);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Ricotta con hierbas',
                            'portion' => sprintf('%dg', $grams),
                            'calories' => round($grams * 1.74),
                            'protein' => round($targetProtein),
                            'fats' => round($grams * 0.13),
                            'carbohydrates' => round($grams * 0.03)
                        ];
                    } else {
                        $grams = round($targetProtein * 5.5);
                        $options['Proteínas']['options'][] = [
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

            $carbOptions = ['Arroz blanco', 'Quinua', 'Frijoles'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredCarbs)) {
                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }
            }
        }

        if ($isLowBudget) {
            $fatOptions = ['Aceite vegetal', 'Crema de cacahuate', 'Semillas de girasol'];
        } else {
            $fatOptions = ['Aceite de oliva extra virgen', 'Nueces', 'Semillas de chía'];
        }
        $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
        $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
        $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, "{$mealName}-Grasas", '', 3);

        if (!empty($filteredFats)) {
            $options['Grasas'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats, $isLowBudget);
                if ($portionData) {
                    $options['Grasas']['options'][] = $portionData;
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
        $mealName,
        $targetProtein,
        $targetCarbs,
        $targetFats,
        $isLowBudget,
        $dislikedFoods = '',
        $foodPreferences = [],
        $allergies = ''
    ): array
    {
        $options = [];

        if ($mealName === 'Desayuno') {
            $proteinOptions = ['Tofu firme', 'Lentejas cocidas', 'Garbanzos cocidos'];
            $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredProteins)) {
                $options['Proteínas'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    if ($proteinName === 'Tofu firme') {
                        $tofuGrams = round($targetProtein * 12.5);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Tofu firme',
                            'portion' => sprintf('%dg', $tofuGrams),
                            'calories' => round($tofuGrams * 1.44),
                            'protein' => round($targetProtein),
                            'fats' => round($tofuGrams * 0.09),
                            'carbohydrates' => round($tofuGrams * 0.03)
                        ];
                    } elseif ($proteinName === 'Lentejas cocidas') {
                        $lentejasGrams = round($targetProtein * 11);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Lentejas cocidas',
                            'portion' => sprintf('%dg (peso cocido)', $lentejasGrams),
                            'calories' => round($lentejasGrams * 1.16),
                            'protein' => round($targetProtein),
                            'fats' => round($lentejasGrams * 0.004),
                            'carbohydrates' => round($lentejasGrams * 0.2)
                        ];
                    } elseif ($proteinName === 'Garbanzos cocidos') {
                        $garbanzosGrams = round($targetProtein * 12);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Garbanzos cocidos',
                            'portion' => sprintf('%dg (peso cocido)', $garbanzosGrams),
                            'calories' => round($garbanzosGrams * 1.64),
                            'protein' => round($targetProtein),
                            'fats' => round($garbanzosGrams * 0.03),
                            'carbohydrates' => round($garbanzosGrams * 0.27)
                        ];
                    }
                }
            }

            $carbOptions = ['Avena tradicional', 'Pan integral', 'Quinua cocida'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredCarbs)) {
                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }
            }

        } elseif ($mealName === 'Almuerzo' || $mealName === 'Cena') {
            $proteinOptions = ['Seitán', 'Tempeh', 'Hamburguesa de lentejas'];
            $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredProteins)) {
                $options['Proteínas'] = ['options' => []];
                foreach ($filteredProteins as $proteinName) {
                    if ($proteinName === 'Seitán') {
                        $seitanGrams = round($targetProtein * 4);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Seitán',
                            'portion' => sprintf('%dg', $seitanGrams),
                            'calories' => round($seitanGrams * 3.7),
                            'protein' => round($targetProtein),
                            'fats' => round($seitanGrams * 0.02),
                            'carbohydrates' => round($seitanGrams * 0.14)
                        ];
                    } elseif ($proteinName === 'Tempeh') {
                        $tempehGrams = round($targetProtein * 5.3);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Tempeh',
                            'portion' => sprintf('%dg', $tempehGrams),
                            'calories' => round($tempehGrams * 1.93),
                            'protein' => round($targetProtein),
                            'fats' => round($tempehGrams * 0.11),
                            'carbohydrates' => round($tempehGrams * 0.09)
                        ];
                    } elseif ($proteinName === 'Hamburguesa de lentejas') {
                        $hamburguesaGrams = round($targetProtein * 6);
                        $options['Proteínas']['options'][] = [
                            'name' => 'Hamburguesa de lentejas',
                            'portion' => sprintf('%dg (2 unidades)', $hamburguesaGrams),
                            'calories' => round($targetProtein * 7),
                            'protein' => round($targetProtein),
                            'fats' => round($targetProtein * 0.3),
                            'carbohydrates' => round($targetProtein * 1.5)
                        ];
                    }
                }
            }

            $carbOptions = ['Arroz blanco', 'Papa', 'Quinua'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredCarbs)) {
                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->foodCalculationService->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }
            }
        }

        if ($isLowBudget) {
            $fatOptions = ['Aceite vegetal', 'Maní', 'Aguacate'];
        } else {
            $fatOptions = ['Aceite de oliva extra virgen', 'Almendras', 'Aguacate hass'];
        }
        $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
        $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
        $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, "{$mealName}-Grasas", '', 3);

        if (!empty($filteredFats)) {
            $options['Grasas'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                $portionData = $this->foodCalculationService->calculateFatPortionByFood($fatName, $targetFats, $isLowBudget);
                if ($portionData) {
                    $options['Grasas']['options'][] = $portionData;
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

