<?php

namespace App\Services\PlanGeneration;

use Illuminate\Support\Facades\Log;

class FoodCalculationService
{
    public function calculateProteinPortionByFood($foodName, $targetProtein, $isLowBudget = true): ?array
    {
        $nutritionMapLow = [
            'Huevo entero' => [
                'protein' => 13,
                'calories' => 155,
                'fats' => 11,
                'carbs' => 1,
                'weigh_raw' => false,
                'unit' => 'unidad',
                'unit_weight' => 50
            ],
            'Atún en lata' => [
                'protein' => 30,
                'calories' => 145,
                'fats' => 2,
                'carbs' => 0,
                'weigh_raw' => false
            ],
            'Pollo muslo' => [
                'protein' => 25,
                'calories' => 180,
                'fats' => 10,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Carne molida' => [
                'protein' => 26,
                'calories' => 153,
                'fats' => 7,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Yogurt griego' => [
                'protein' => 10,
                'calories' => 59,
                'fats' => 0.4,
                'carbs' => 3.6,
                'weigh_raw' => false
            ],
        ];

        $nutritionMapHigh = [
            'Claras + Huevo entero' => [
                'protein' => 11,
                'calories' => 90,
                'fats' => 3,
                'carbs' => 1,
                'weigh_raw' => false,
                'unit' => 'mezcla',
                'unit_weight' => 55,
                'description' => '3 claras + 1 huevo entero'
            ],
            'Yogurt griego' => [
                'protein' => 10,
                'calories' => 59,
                'fats' => 0.4,
                'carbs' => 3.6,
                'weigh_raw' => false
            ],
            'Yogurt griego alto en proteínas' => [
                'protein' => 20,
                'calories' => 90,
                'fats' => 3,
                'carbs' => 5,
                'weigh_raw' => false
            ],
            'Yogurt griego alto en proteína' => [
                'protein' => 20,
                'calories' => 90,
                'fats' => 3,
                'carbs' => 5,
                'weigh_raw' => false
            ],
            'Proteína whey' => [
                'protein' => 80,
                'calories' => 380,
                'fats' => 2,
                'carbs' => 8,
                'weigh_raw' => false
            ],
            'Pechuga de pollo' => [
                'protein' => 31,
                'calories' => 165,
                'fats' => 3.6,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Salmón fresco' => [
                'protein' => 25,
                'calories' => 208,
                'fats' => 13,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Carne de res magra' => [
                'protein' => 26,
                'calories' => 153,
                'fats' => 7,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Proteína en polvo' => [
                'protein' => 80,
                'calories' => 380,
                'fats' => 2,
                'carbs' => 8,
                'weigh_raw' => false
            ],
            'Caseína' => [
                'protein' => 78,
                'calories' => 360,
                'fats' => 1,
                'carbs' => 10,
                'weigh_raw' => false
            ],
            'Pescado blanco' => [
                'protein' => 25,
                'calories' => 120,
                'fats' => 2,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Pechuga de pavo' => [
                'protein' => 29,
                'calories' => 135,
                'fats' => 1,
                'carbs' => 0,
                'weigh_raw' => true
            ],
            'Claras + Huevo Entero' => [
                'protein' => 11,
                'calories' => 52,
                'fats' => 0,
                'carbs' => 1,
                'weigh_raw' => false,
                'unit' => 'unidad',
                'unit_weight' => 33
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

        if ($foodName === 'Claras + Huevo entero') {
            $totalUnits = round($targetProtein / 6.5);
            if ($totalUnits < 3) $totalUnits = 3;

            $eggWholeUnits = max(1, round($totalUnits * 0.3));
            $eggWhiteUnits = $totalUnits - $eggWholeUnits;

            $portion = sprintf('%d claras + %d huevo%s entero%s',
                $eggWhiteUnits,
                $eggWholeUnits,
                $eggWholeUnits > 1 ? 's' : '',
                $eggWholeUnits > 1 ? 's' : ''
            );

            $calories = ($eggWhiteUnits * 17) + ($eggWholeUnits * 70);
            $protein = ($eggWhiteUnits * 3.6) + ($eggWholeUnits * 6);
            $fats = ($eggWholeUnits * 5);
            $carbs = round($totalUnits * 0.5);

            return [
                'name' => 'Claras + Huevo entero',
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

            $portion = "{$units} " . ($units == 1 ? $nutrition['unit'] : $nutrition['unit'] . 's');

            $gramsNeeded = $units * $nutrition['unit_weight'];
            $calories = ($gramsNeeded / 100) * $nutrition['calories'];
            $actualProtein = ($gramsNeeded / 100) * $nutrition['protein'];
            $fats = ($gramsNeeded / 100) * $nutrition['fats'];
            $carbs = ($gramsNeeded / 100) * $nutrition['carbs'];
        } else {
            $portionLabel = $nutrition['weigh_raw'] ? '(peso en crudo)' : 'escurrido';
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
            'Aceite de oliva' => [
                'protein' => 0,
                'calories' => 884,
                'fats' => 100,
                'carbs' => 0,
                'density' => 0.92
            ],
            'Maní' => [
                'protein' => 26,
                'calories' => 567,
                'fats' => 49,
                'carbs' => 16
            ],
            'Aguacate' => [
                'protein' => 2,
                'calories' => 160,
                'fats' => 15,
                'carbs' => 9,
                'unit' => 'unidad',
                'unit_weight' => 200
            ],
            'Mantequilla de maní casera' => [
                'protein' => 25,
                'calories' => 588,
                'fats' => 50,
                'carbs' => 20
            ],
        ];

        $nutritionMapHigh = [
            'Aceite de oliva extra virgen' => [
                'protein' => 0,
                'calories' => 884,
                'fats' => 100,
                'carbs' => 0,
                'density' => 0.92
            ],
            'Almendras' => [
                'protein' => 21,
                'calories' => 579,
                'fats' => 50,
                'carbs' => 22
            ],
            'Aguacate hass' => [
                'protein' => 2,
                'calories' => 160,
                'fats' => 15,
                'carbs' => 9,
                'unit' => 'unidad',
                'unit_weight' => 200
            ],
            'Nueces' => [
                'protein' => 15,
                'calories' => 654,
                'fats' => 65,
                'carbs' => 14
            ],
            'Mantequilla de maní' => [
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

        if (str_contains(strtolower($foodName), 'aceite')) {
            $ml = round($gramsNeeded * (1 / ($nutrition['density'] ?? 0.92)));
            $tbsp = max(1, round($ml / 15));
            $portion = "{$tbsp} " . ($tbsp == 1 ? 'cucharada' : 'cucharadas') . " ({$ml}ml)";

        } elseif (isset($nutrition['unit']) && isset($nutrition['unit_weight'])) {
            $fraction = $gramsNeeded / $nutrition['unit_weight'];

            if ($fraction <= 0.33) {
                $portion = round($gramsNeeded) . "g (1/3 {$nutrition['unit']})";
            } elseif ($fraction <= 0.5) {
                $portion = round($gramsNeeded) . "g (1/2 {$nutrition['unit']})";
            } elseif ($fraction <= 0.75) {
                $portion = round($gramsNeeded) . "g (3/4 {$nutrition['unit']})";
            } else {
                $units = ceil($fraction);
                $portion = round($gramsNeeded) . "g ({$units} " . ($units == 1 ? $nutrition['unit'] : $nutrition['unit'] . 's') . ")";
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
            'Papa' => ['protein' => 2, 'carbs' => 18, 'fats' => 0, 'calories' => 78, 'weigh_raw' => false],
            'Arroz blanco' => ['protein' => 2.7, 'carbs' => 28, 'fats' => 0.3, 'calories' => 130, 'weigh_raw' => false],
            'Camote' => ['protein' => 1.6, 'carbs' => 20, 'fats' => 0, 'calories' => 86, 'weigh_raw' => false],
            'Fideo' => ['protein' => 5, 'carbs' => 31, 'fats' => 0.9, 'calories' => 158, 'weigh_raw' => false],
            'Frijoles' => ['protein' => 8.7, 'carbs' => 21, 'fats' => 0.5, 'calories' => 132, 'weigh_raw' => false],
            'Quinua' => ['protein' => 4.4, 'carbs' => 21, 'fats' => 1.9, 'calories' => 120, 'weigh_raw' => false],
            'Pan integral' => ['protein' => 9, 'carbs' => 47, 'fats' => 4, 'calories' => 260, 'weigh_raw' => false, 'unit' => 'rebanada', 'unit_weight' => 30],
            'Tortilla de maíz' => ['protein' => 6, 'carbs' => 50, 'fats' => 3, 'calories' => 250, 'weigh_raw' => false, 'unit' => 'tortilla', 'unit_weight' => 30],
            'Galletas de arroz' => ['protein' => 8, 'carbs' => 82, 'fats' => 3, 'calories' => 390, 'weigh_raw' => false, 'unit' => 'unidad', 'unit_weight' => 9],
            'Avena' => ['protein' => 13, 'carbs' => 67, 'fats' => 7, 'calories' => 375, 'weigh_raw' => true],
            'Avena orgánica' => ['protein' => 13, 'carbs' => 67, 'fats' => 7, 'calories' => 375, 'weigh_raw' => true],
            'Crema de arroz' => ['protein' => 6, 'carbs' => 80, 'fats' => 1, 'calories' => 360, 'weigh_raw' => true],
            'Cereal de maíz' => ['protein' => 7, 'carbs' => 84, 'fats' => 3, 'calories' => 380, 'weigh_raw' => true],
            'Pan integral artesanal' => ['protein' => 10, 'carbs' => 45, 'fats' => 5, 'calories' => 270, 'weigh_raw' => false, 'unit' => 'rebanada', 'unit_weight' => 35],
            'arroz blanco' => ['protein' => 2.6, 'carbs' => 23, 'fats' => 0.9, 'calories' => 111, 'weigh_raw' => false],
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

            $portion = "{$units} " . ($units == 1 ? $nutrition['unit'] : $nutrition['unit'] . 's');

            $gramsNeeded = $units * $nutrition['unit_weight'];
            $calories = ($gramsNeeded / 100) * $nutrition['calories'];
            $protein = ($gramsNeeded / 100) * $nutrition['protein'];
            $fats = ($gramsNeeded / 100) * $nutrition['fats'];
            $actualCarbs = ($gramsNeeded / 100) * $nutrition['carbs'];

        } else {
            $portionLabel = $nutrition['weigh_raw'] ? '(peso en seco)' : '(peso cocido)';
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
        $eggProducts = ['huevo entero', 'huevos', 'Claras + Huevo Entero', 'claras pasteurizadas', 'huevo', 'clara'];
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
            'salmón',
            'salmon',
            'pechuga de pollo',
            'Claras + Huevo Entero',
            'yogurt griego',
            'yogur griego',
            'proteína',
            'whey',
            'quinua',
            'quinoa',
            'avena orgánica',
            'pan integral artesanal',
            'aceite de oliva extra virgen',
            'almendras',
            'nueces',
            'pistachos',
            'aguacate hass',
            'palta hass'
        ];

        foreach ($highBudgetFoods as $food) {
            if (str_contains($foodName, $food)) {
                return true;
            }
        }
        return false;
    }

    public function isFoodLowBudget($foodName): bool
    {
        $lowBudgetFoods = [
            'huevo entero',
            'pollo muslo',
            'muslos',
            'atún en lata',
            'carne molida',
            'arroz blanco',
            'papa',
            'fideos',
            'avena tradicional',
            'tortillas de maíz',
            'pan de molde',
            'aceite vegetal',
            'maní',
            'cacahuate',
            'frijoles',
            'lentejas'
        ];

        foreach ($lowBudgetFoods as $food) {
            if (str_contains($foodName, $food)) {
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
            'palta' => 'aguacate',
            'aguacate' => 'palta',
            'pollo pechuga' => 'pechuga de pollo',
            'atun' => 'atun en lata',
            'mani' => 'mantequilla de mani',
            'claras' => 'Claras + Huevo Entero',
            'yogurt' => 'yogur',
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