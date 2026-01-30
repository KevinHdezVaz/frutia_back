<?php

namespace App\Services\PlanGeneration;

use Illuminate\Support\Facades\Log;

class FoodCalculationService
{
    public function calculateProteinPortionByFood($foodName, $targetProtein, $isLowBudget = true): ?array
    {
        $nutritionMapLow = [
            'Whole egg' => [
                'protein' => 13,
                'calories' => 155,
                'fats' => 11,
                'carbs' => 1,
                'weigh_raw' => false,
                'unit' => 'unit',
                'unit_weight' => 50
            ],
            'Canned tuna' => [
                'protein' => 30,
                'calories' => 145,
                'fats' => 2,
                'carbs' => 0,
                'weigh_raw' => false
            ],
            'Chicken thigh' => [
                'protein' => 25,
                'calories' => 180,
                'fats' => 10,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Ground beef' => [
                'protein' => 26,
                'calories' => 153,
                'fats' => 7,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Greek yogurt' => [
                'protein' => 10,
                'calories' => 59,
                'fats' => 0.4,
                'carbs' => 3.6,
                'weigh_raw' => false
            ],
        ];

        $nutritionMapHigh = [
            'Egg whites + Whole egg' => [
                'protein' => 11,
                'calories' => 90,
                'fats' => 3,
                'carbs' => 1,
                'weigh_raw' => false,
                'unit' => 'mix',
                'unit_weight' => 55,
                'description' => '3 egg whites + 1 whole egg'
            ],
            'Greek yogurt' => [
                'protein' => 10,
                'calories' => 59,
                'fats' => 0.4,
                'carbs' => 3.6,
                'weigh_raw' => false
            ],
            'High-protein Greek yogurt' => [
                'protein' => 20,
                'calories' => 90,
                'fats' => 3,
                'carbs' => 5,
                'weigh_raw' => false
            ],
            'Whey protein' => [
                'protein' => 80,
                'calories' => 380,
                'fats' => 2,
                'carbs' => 8,
                'weigh_raw' => false
            ],
            'Chicken breast' => [
                'protein' => 31,
                'calories' => 165,
                'fats' => 3.6,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Fresh salmon' => [
                'protein' => 25,
                'calories' => 208,
                'fats' => 13,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Lean beef' => [
                'protein' => 26,
                'calories' => 153,
                'fats' => 7,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Protein powder' => [
                'protein' => 80,
                'calories' => 380,
                'fats' => 2,
                'carbs' => 8,
                'weigh_raw' => false
            ],
            'Casein' => [
                'protein' => 78,
                'calories' => 360,
                'fats' => 1,
                'carbs' => 10,
                'weigh_raw' => false
            ],
            'White fish' => [
                'protein' => 25,
                'calories' => 120,
                'fats' => 2,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Turkey breast' => [
                'protein' => 29,
                'calories' => 135,
                'fats' => 1,
                'carbs' => 0,
                'weigh_raw' => true
            ],
        ];

        $nutritionMap = $isLowBudget ? $nutritionMapLow : array_merge($nutritionMapLow, $nutritionMapHigh);

        $nutrition = $nutritionMap[$foodName] ?? null;

        if (!$nutrition) {
            Log::warning("Alimento de proteína no encontrado: {$foodName}");
            return null;
        }

        $gramsNeeded = ($targetProtein / $nutrition['protein']) * 100;
        $calories = ($gramsNeeded / 100) * $nutrition['calories'];
        $fats = ($gramsNeeded / 100) * $nutrition['fats'];
        $carbs = ($gramsNeeded / 100) * $nutrition['carbs'];

        if ($foodName === 'Egg whites + Whole egg') {
            $totalUnits = round($targetProtein / 6.5);
            if ($totalUnits < 3) $totalUnits = 3;
            $eggWholeUnits = max(1, round($totalUnits * 0.3));
            $eggWhiteUnits = $totalUnits - $eggWholeUnits;
            $whiteWord = $eggWhiteUnits == 1 ? 'egg white' : 'egg whites';
            $wholeWord = $eggWholeUnits == 1 ? 'whole egg' : 'whole eggs';
            $portion = sprintf('%d %s + %d %s',
                $eggWhiteUnits,
                $whiteWord,
                $eggWholeUnits,
                $wholeWord
            );
            $calories = ($eggWhiteUnits * 17) + ($eggWholeUnits * 70);
            $protein = ($eggWhiteUnits * 3.6) + ($eggWholeUnits * 6);
            $fats = ($eggWholeUnits * 5);
            $carbs = round($totalUnits * 0.5);

            return [
                'name' => 'Egg whites + Whole egg',
                'portion' => $portion,
                'calories' => round($calories),
                'protein' => round($protein),
                'fats' => round($fats),
                'carbohydrates' => $carbs
            ];
        }

        if (isset($nutrition['unit']) && isset($nutrition['unit_weight'])) {
            $units = round($gramsNeeded / $nutrition['unit_weight']);
            if ($units < 1) $units = 1;
            $unitWord = $units == 1 ? $nutrition['unit'] : $nutrition['unit'] . 's';
            $portion = "{$units} {$unitWord}";
            $gramsNeeded = $units * $nutrition['unit_weight'];
            $calories = ($gramsNeeded / 100) * $nutrition['calories'];
            $actualProtein = ($gramsNeeded / 100) * $nutrition['protein'];
            $fats = ($gramsNeeded / 100) * $nutrition['fats'];
            $carbs = ($gramsNeeded / 100) * $nutrition['carbs'];
        } else {
            $portionLabel = $nutrition['weigh_raw'] ? '(raw weight)' : '(drained)';
            $portion = round($gramsNeeded) . "g " . $portionLabel;
            $actualProtein = $targetProtein;
        }

        return [
            'name' => $foodName,
            'portion' => $portion,
            'calories' => round($calories),
            'protein' => round($actualProtein ?? $targetProtein),
            'fats' => round($fats, 1),
            'carbohydrates' => round($carbs, 1),
            'is_raw_weight' => $nutrition['weigh_raw']
        ];
    }

    public function calculateFatPortionByFood($foodName, $targetFats, $isLowBudget = true): ?array
    {
        $nutritionMapLow = [
            'Olive oil' => [
                'protein' => 0,
                'calories' => 884,
                'fats' => 100,
                'carbs' => 0,
                'density' => 0.92
            ],
            'Peanuts' => [
                'protein' => 26,
                'calories' => 567,
                'fats' => 49,
                'carbs' => 16
            ],
            'Avocado' => [
                'protein' => 2,
                'calories' => 160,
                'fats' => 15,
                'carbs' => 9,
                'unit' => 'unit',
                'unit_weight' => 200
            ],
            'Homemade peanut butter' => [
                'protein' => 25,
                'calories' => 588,
                'fats' => 50,
                'carbs' => 20
            ],
        ];

        $nutritionMapHigh = [
            'Extra virgin olive oil' => [
                'protein' => 0,
                'calories' => 884,
                'fats' => 100,
                'carbs' => 0,
                'density' => 0.92
            ],
            'Almonds' => [
                'protein' => 21,
                'calories' => 579,
                'fats' => 50,
                'carbs' => 22
            ],
            'Hass avocado' => [
                'protein' => 2,
                'calories' => 160,
                'fats' => 15,
                'carbs' => 9,
                'unit' => 'unit',
                'unit_weight' => 200
            ],
            'Walnuts' => [
                'protein' => 15,
                'calories' => 654,
                'fats' => 65,
                'carbs' => 14
            ],
            'Peanut butter' => [
                'protein' => 25,
                'calories' => 588,
                'fats' => 50,
                'carbs' => 20
            ],
        ];

        $nutritionMap = $isLowBudget ? $nutritionMapLow : array_merge($nutritionMapLow, $nutritionMapHigh);

        $nutrition = $nutritionMap[$foodName] ?? null;

        if (!$nutrition) {
            Log::warning("Alimento de grasa no encontrado: {$foodName}");
            return null;
        }

        $gramsNeeded = ($targetFats / $nutrition['fats']) * 100;
        $calories = ($gramsNeeded / 100) * $nutrition['calories'];
        $protein = ($gramsNeeded / 100) * $nutrition['protein'];
        $carbs = ($gramsNeeded / 100) * $nutrition['carbs'];

        if (str_contains(strtolower($foodName), 'oil')) {
            $ml = round($gramsNeeded * (1 / ($nutrition['density'] ?? 0.92)));
            $tbsp = max(1, round($ml / 15));
            $tbspWord = $tbsp == 1 ? 'tablespoon' : 'tablespoons';
            $portion = "{$tbsp} {$tbspWord} ({$ml}ml)";
        } elseif (isset($nutrition['unit']) && isset($nutrition['unit_weight'])) {
            $fraction = $gramsNeeded / $nutrition['unit_weight'];
            $unitWord = $nutrition['unit'];
            if ($fraction <= 0.33) {
                $portion = round($gramsNeeded) . "g (1/3 {$unitWord})";
            } elseif ($fraction <= 0.5) {
                $portion = round($gramsNeeded) . "g (1/2 {$unitWord})";
            } elseif ($fraction <= 0.75) {
                $portion = round($gramsNeeded) . "g (3/4 {$unitWord})";
            } else {
                $units = ceil($fraction);
                $pluralUnit = $units == 1 ? $unitWord : $unitWord . 's';
                $portion = round($gramsNeeded) . "g ({$units} {$pluralUnit})";
            }
        } else {
            $portion = round($gramsNeeded) . "g";
        }

        return [
            'name' => $foodName,
            'portion' => $portion,
            'calories' => round($calories),
            'protein' => round($protein, 1),
            'fats' => round($targetFats),
            'carbohydrates' => round($carbs, 1)
        ];
    }

    public function calculateCarbPortionByFood($foodName, $targetCarbs): ?array
    {
        $nutritionMap = [
            'Potato' => ['protein' => 2, 'carbs' => 18, 'fats' => 0, 'calories' => 78, 'weigh_raw' => false],
            'White rice' => ['protein' => 2.7, 'carbs' => 28, 'fats' => 0.3, 'calories' => 130, 'weigh_raw' => false],
            'Sweet potato' => ['protein' => 1.6, 'carbs' => 20, 'fats' => 0, 'calories' => 86, 'weigh_raw' => false],
            'Noodles' => ['protein' => 5, 'carbs' => 31, 'fats' => 0.9, 'calories' => 158, 'weigh_raw' => false],
            'Beans' => ['protein' => 8.7, 'carbs' => 21, 'fats' => 0.5, 'calories' => 132, 'weigh_raw' => false],
            'Quinoa' => ['protein' => 4.4, 'carbs' => 21, 'fats' => 1.9, 'calories' => 120, 'weigh_raw' => false],
            'Whole wheat bread' => ['protein' => 9, 'carbs' => 47, 'fats' => 4, 'calories' => 260, 'weigh_raw' => false, 'unit' => 'slice', 'unit_weight' => 30],
            'Corn tortilla' => ['protein' => 6, 'carbs' => 50, 'fats' => 3, 'calories' => 250, 'weigh_raw' => false, 'unit' => 'tortilla', 'unit_weight' => 30],
            'Rice crackers' => ['protein' => 8, 'carbs' => 82, 'fats' => 3, 'calories' => 390, 'weigh_raw' => false, 'unit' => 'unit', 'unit_weight' => 9],
            'Oats' => ['protein' => 13, 'carbs' => 67, 'fats' => 7, 'calories' => 375, 'weigh_raw' => true],
            'Organic oats' => ['protein' => 13, 'carbs' => 67, 'fats' => 7, 'calories' => 375, 'weigh_raw' => true],
            'Cream of rice' => ['protein' => 6, 'carbs' => 80, 'fats' => 1, 'calories' => 360, 'weigh_raw' => true],
            'Corn cereal' => ['protein' => 7, 'carbs' => 84, 'fats' => 3, 'calories' => 380, 'weigh_raw' => true],
            'Artisanal whole wheat bread' => ['protein' => 10, 'carbs' => 45, 'fats' => 5, 'calories' => 270, 'weigh_raw' => false, 'unit' => 'slice', 'unit_weight' => 35],
        ];

        $nutrition = $nutritionMap[$foodName] ?? null;

        if (!$nutrition) {
            Log::warning("Alimento de carbohidrato no encontrado: {$foodName}");
            return null;
        }

        $gramsNeeded = ($targetCarbs / $nutrition['carbs']) * 100;
        $calories = ($gramsNeeded / 100) * $nutrition['calories'];
        $protein = ($gramsNeeded / 100) * $nutrition['protein'];
        $fats = ($gramsNeeded / 100) * $nutrition['fats'];

        if (isset($nutrition['unit']) && isset($nutrition['unit_weight'])) {
            $units = round($gramsNeeded / $nutrition['unit_weight']);
            if ($units < 1) $units = 1;
            $unitWord = $units == 1 ? $nutrition['unit'] : $nutrition['unit'] . 's';
            $portion = "{$units} {$unitWord}";
            $gramsNeeded = $units * $nutrition['unit_weight'];
            $calories = ($gramsNeeded / 100) * $nutrition['calories'];
            $protein = ($gramsNeeded / 100) * $nutrition['protein'];
            $fats = ($gramsNeeded / 100) * $nutrition['fats'];
            $actualCarbs = ($gramsNeeded / 100) * $nutrition['carbs'];
        } else {
            $portionLabel = $nutrition['weigh_raw'] ? '(dry weight)' : '(cooked weight)';
            $portion = round($gramsNeeded) . "g " . $portionLabel;
            $actualCarbs = $targetCarbs;
        }

        return [
            'name' => $foodName,
            'portion' => $portion,
            'calories' => round($calories),
            'protein' => round($protein, 1),
            'fats' => round($fats, 1),
            'carbohydrates' => round($actualCarbs ?? $targetCarbs),
            'is_raw_weight' => $nutrition['weigh_raw']
        ];
    }

    public function isEggProduct($foodName): bool
    {
        $eggProducts = ['whole egg', 'eggs', 'egg whites + whole egg', 'pasteurized egg whites', 'egg', 'egg white'];
        $nameLower = strtolower($foodName);
        foreach ($eggProducts as $egg) {
            if (str_contains($nameLower, $egg)) {
                return true;
            }
        }
        return false;
    }

    public function isFoodHighBudget($foodName): bool
    {
        $highBudgetFoods = [
            'salmon',
            'chicken breast',
            'egg whites + whole egg',
            'greek yogurt',
            'protein',
            'whey',
            'quinoa',
            'organic oats',
            'artisanal whole wheat bread',
            'extra virgin olive oil',
            'almonds',
            'walnuts',
            'pistachios',
            'hass avocado'
        ];

        $nameLower = strtolower($foodName);
        foreach ($highBudgetFoods as $food) {
            if (str_contains($nameLower, $food)) {
                return true;
            }
        }
        return false;
    }

    public function isFoodLowBudget($foodName): bool
    {
        $lowBudgetFoods = [
            'whole egg',
            'chicken thigh',
            'canned tuna',
            'ground beef',
            'white rice',
            'potato',
            'noodles',
            'traditional oats',
            'corn tortilla',
            'whole wheat bread',
            'vegetable oil',
            'peanuts',
            'beans',
            'lentils'
        ];

        $nameLower = strtolower($foodName);
        foreach ($lowBudgetFoods as $food) {
            if (str_contains($nameLower, $food)) {
                return true;
            }
        }
        return false;
    }

    public function removeAccents(string $text): string
    {
        $unwanted = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'ñ' => 'n', 'Ñ' => 'n'
        ];
        return strtr(strtolower($text), $unwanted);
    }

    public function normalizeText(string $text): string
    {
        $text = strtolower($text);
        $text = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', '/', '-'],
            ['a', 'e', 'i', 'o', 'u', 'n', ' ', ' '],
            $text
        );
        return trim($text);
    }

    public function containsAllKeywords(string $text, string $keywords): bool
    {
        $keywordWords = array_filter(explode(' ', $keywords), function($word) {
            return strlen($word) > 2;
        });

        foreach ($keywordWords as $word) {
            if (!str_contains($text, $word)) {
                return false;
            }
        }

        return !empty($keywordWords);
    }

    public function areNamesEquivalent(string $name1, string $name2): bool
    {
        $equivalences = [
            'avocado' => 'hass avocado',
            'hass avocado' => 'avocado',
            'chicken breast' => 'chicken',
            'canned tuna' => 'tuna',
            'peanut butter' => 'peanuts',
            'egg whites' => 'egg whites + whole egg',
            'yogurt' => 'greek yogurt',
        ];

        foreach ($equivalences as $key => $value) {
            if (
                (strpos($name1, $key) !== false && strpos($name2, $value) !== false) ||
                (strpos($name1, $value) !== false && strpos($name2, $key) !== false)
            ) {
                return true;
            }
        }

        return false;
    }
}