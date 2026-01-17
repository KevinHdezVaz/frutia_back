<?php

namespace App\Services\PlanGeneration;

use Illuminate\Support\Facades\Log;

class NutritionalPlanService
{
    // NUEVO: Lista de alimentos según PDF en orden ESTRICTO de preferencia
    private const FOOD_PREFERENCES = [
        'carbohidratos' => [
            'almuerzo_cena' => ['Papa', 'Arroz blanco', 'Camote', 'Fideo', 'Frijoles', 'Quinua'],
            'desayuno' => ['Avena', 'Pan integral', 'Tortilla de maíz'],
            'snacks' => ['Cereal de maíz', 'Crema de arroz', 'Galletas de arroz', 'Avena']
        ],
        'proteinas' => [
            'bajo' => [
                'desayuno' => ['Huevo entero', 'Claras + Huevo Entero'],
                'almuerzo_cena' => ['Pechuga de pollo', 'Carne molida 93% magra', 'Atún en lata', 'Pescado blanco'],
                'snacks' => ['Yogurt griego']
            ],
            'alto' => [
                'desayuno' => ['Claras + Huevo entero', 'Proteína whey', 'Yogurt griego alto en proteínas', 'Caseína'],
                'almuerzo_cena' => ['Pescado blanco', 'Pechuga de pollo', 'Pechuga de pavo', 'Carne de res magra', 'Salmón fresco'],
                'snacks' => ['Yogurt griego alto en proteínas', 'Proteína whey', 'Caseína']
            ]
        ],
        'grasas' => [
            'bajo' => ['Aceite de oliva', 'Maní', 'Queso bajo en grasa', 'Mantequilla de maní casera', 'Semillas de ajonjolí', 'Aceitunas'],
            'alto' => ['Aceite de oliva extra virgen', 'Aceite de palta', 'Palta', 'Almendras', 'Nueces', 'Pistachos', 'Pecanas', 'Semillas de chía orgánicas', 'Linaza orgánica', 'Mantequilla de maní', 'Miel', 'Chocolate negro 70%']
        ]
    ];

    public function extractPersonalizationData($profile, $userName): array
    {
        return [
            'personal_data' => [
                'name' => $userName,
                'preferred_name' => $userName,
                'goal' => $profile->goal,
                'age' => (int)$profile->age,
                'sex' => $this->normalizeSex($profile->sex),
                'weight' => (float)$profile->weight,
                'height' => (float)$profile->height,
                'country' => $profile->pais ?? 'No especificado',
                'bmi' => $this->calculateBMI($profile->weight, $profile->height),
                'age_group' => $this->getAgeGroup($profile->age),
                'sex_normalized' => $this->normalizeSex($profile->sex)
            ],
            'activity_data' => [
                'weekly_activity' => $profile->weekly_activity,
                'sports' => is_string($profile->sport) ? json_decode($profile->sport, true) : ($profile->sport ?? []),
                'training_frequency' => $profile->training_frequency ?? 'No especificado'
            ],
            'meal_structure' => [
                'meal_count' => $profile->meal_count,
                'breakfast_time' => $profile->breakfast_time,
                'lunch_time' => $profile->lunch_time,
                'dinner_time' => $profile->dinner_time,
                'preferred_snack_time' => $profile->preferred_snack_time ?? 'Snack PM',
                'eats_out' => $profile->eats_out
            ],
            'dietary_preferences' => [
                'dietary_style' => $profile->dietary_style ?? 'Omnívoro',
                'budget' => $profile->budget,
                'disliked_foods' => $profile->disliked_foods ?? '',
                'has_allergies' => $profile->has_allergies ?? false,
                'allergies' => $profile->allergies ?? '',
                'has_medical_condition' => $profile->has_medical_condition ?? false,
                'medical_condition' => $profile->medical_condition ?? ''
            ],
            'emotional_data' => [
                'communication_style' => $profile->communication_style,
                'diet_difficulties' => is_string($profile->diet_difficulties)
                    ? json_decode($profile->diet_difficulties, true)
                    : ($profile->diet_difficulties ?? []),
                'diet_motivations' => is_string($profile->diet_motivations)
                    ? json_decode($profile->diet_motivations, true)
                    : ($profile->diet_motivations ?? [])
            ],
            'created_at' => now()
        ];
    }

    public function calculateCompleteNutritionalPlan($profile, $userName): array
    {
        $this->validateAnthropometricData($profile);

        $basicData = [
            'age' => (int)$profile->age,
            'sex' => $this->normalizeSex($profile->sex),
            'weight' => (float)$profile->weight,
            'height' => (float)$profile->height,
            'activity_level' => $profile->weekly_activity,
            'goal' => $profile->goal,
            'country' => $profile->pais ?? 'No especificado',

            'anthropometric_data' => [
                'bmi' => $this->calculateBMI($profile->weight, $profile->height),
                'bmr_category' => $this->getBMRCategory($profile->age),
                'weight_status' => $this->getWeightStatus($this->calculateBMI($profile->weight, $profile->height)),
                'ideal_weight_range' => $this->calculateIdealWeightRange($profile->height, $profile->sex)
            ],

            'health_status' => [
                'medical_condition' => $profile->medical_condition ?? 'Ninguna',
                'allergies' => $profile->allergies ?? 'Ninguna',
                'has_medical_condition' => $profile->has_medical_condition ?? false,
                'has_allergies' => $profile->has_allergies ?? false
            ],

            'preferences' => [
                'name' => $userName,
                'preferred_name' => $userName,
                'dietary_style' => $profile->dietary_style ?? 'Omnívoro',
                'disliked_foods' => $profile->disliked_foods ?? 'Ninguno',
                'budget' => $profile->budget ?? 'Medio',
                'meal_count' => $profile->meal_count ?? '3 comidas principales',
                'eats_out' => $profile->eats_out ?? 'A veces',
                'communication_style' => $profile->communication_style ?? 'Cercana'
            ],

            'meal_times' => [
                'breakfast_time' => $profile->breakfast_time ?? '07:00',
                'lunch_time' => $profile->lunch_time ?? '13:00',
                'dinner_time' => $profile->dinner_time ?? '20:00'
            ],

            'sports_data' => [
                'sports' => is_string($profile->sport) ? json_decode($profile->sport, true) : ($profile->sport ?? []),
                'training_frequency' => $profile->training_frequency ?? 'Moderado'
            ],

            'emotional_profile' => [
                'diet_difficulties' => is_string($profile->diet_difficulties)
                    ? json_decode($profile->diet_difficulties, true)
                    : ($profile->diet_difficulties ?? []),
                'diet_motivations' => is_string($profile->diet_motivations)
                    ? json_decode($profile->diet_motivations, true)
                    : ($profile->diet_motivations ?? [])
            ]
        ];

        $tmb = $this->calculateTMB($basicData['sex'], $basicData['weight'], $basicData['height'], $basicData['age']);
        $activityFactor = $this->getExactActivityFactor($basicData['activity_level']);
        $get = $tmb * $activityFactor;

        $adjustedCalories = $this->adjustCaloriesForGoalFixed(
            $get,
            $basicData['goal'],
            $basicData['sex']
        );
        
        $macros = $this->calculateFixedMacronutrients($adjustedCalories, $basicData['goal']);
        $micronutrients = $this->calculateMicronutrientTargets($basicData);

        return [
            'basic_data' => $basicData,
            'tmb' => round($tmb),
            'activity_factor' => $activityFactor,
            'get' => round($get),
            'target_calories' => round($adjustedCalories),
            'macros' => $macros,
            'micronutrients' => $micronutrients,
            'calculation_date' => now(),
            'personalization_level' => 'ultra_high'
        ];
    }

    private function calculateFixedMacronutrients($calories, $goal): array
    {
        $goalLower = strtolower($goal);

        if (str_contains($goalLower, 'bajar grasa')) {
            $proteinPercentage = 0.35;
            $carbPercentage = 0.40;
            $fatPercentage = 0.25;
        }
        elseif (str_contains($goalLower, 'aumentar músculo')) {
            $proteinPercentage = 0.25;
            $carbPercentage = 0.50;
            $fatPercentage = 0.25;
        }
        elseif (str_contains($goalLower, 'comer más saludable')) {
            $proteinPercentage = 0.30;
            $carbPercentage = 0.40;
            $fatPercentage = 0.30;
        } elseif (str_contains($goalLower, 'mejorar rendimiento')) {
            $proteinPercentage = 0.25;
            $carbPercentage = 0.50;
            $fatPercentage = 0.25;
        } else {
            $proteinPercentage = 0.30;
            $carbPercentage = 0.40;
            $fatPercentage = 0.30;
        }

        $proteinCalories = $calories * $proteinPercentage;
        $carbCalories = $calories * $carbPercentage;
        $fatCalories = $calories * $fatPercentage;

        $proteinGrams = $proteinCalories / 4;
        $carbGrams = $carbCalories / 4;
        $fatGrams = $fatCalories / 9;

        return [
            'calories' => round($calories),
            'protein' => [
                'grams' => round($proteinGrams),
                'calories' => round($proteinCalories),
                'percentage' => round($proteinPercentage * 100, 1),
                'per_kg' => 0
            ],
            'fats' => [
                'grams' => round($fatGrams),
                'calories' => round($fatCalories),
                'percentage' => round($fatPercentage * 100, 1),
                'per_kg' => 0
            ],
            'carbohydrates' => [
                'grams' => round($carbGrams),
                'calories' => round($carbCalories),
                'percentage' => round($carbPercentage * 100, 1),
                'per_kg' => 0
            ]
        ];
    }

    private function adjustCaloriesForGoalFixed($get, $goal, $sex = 'masculino'): float
    {
        $goalLower = strtolower($goal);

        if (str_contains($goalLower, 'bajar grasa')) {
            if (strtolower($sex) === 'femenino') {
                return $get * 0.75;
            } else {
                return $get * 0.65;
            }
        }
        elseif (str_contains($goalLower, 'aumentar músculo')) {
            return $get * 1.15;
        }
        elseif (str_contains($goalLower, 'comer más saludable')) {
            return $get * 0.95;
        }
        elseif (str_contains($goalLower, 'mejorar rendimiento')) {
            return $get * 1.05;
        }
        else {
            return $get;
        }
    }

    private function calculateMicronutrientTargets($basicData): array
    {
        $sex = strtolower($basicData['sex']);
        $age = $basicData['age'];
        $goal = strtolower($basicData['goal']);

        $fiberTarget = ($sex === 'masculino') ? 38 : 25;
        $vitaminCTarget = 90;
        $vitaminDTarget = 600;
        $calciumTarget = ($age > 50) ? 1200 : 1000;
        $ironTarget = ($sex === 'masculino') ? 8 : 18;
        $magnesiumTarget = ($sex === 'masculino') ? 420 : 320;
        $potassiumTarget = 3400;
        $sodiumMax = 2300;

        if (str_contains($goal, 'bajar grasa')) {
            $fiberTarget += 5;
            $potassiumTarget += 500;
        } elseif (str_contains($goal, 'aumentar músculo')) {
            $magnesiumTarget += 100;
            $vitaminDTarget = 800;
        }

        return [
            'fiber' => [
                'target' => $fiberTarget,
                'unit' => 'g',
                'importance' => 'critical',
                'tip' => 'Esencial para saciedad y salud digestiva'
            ],
            'vitamin_c' => [
                'target' => $vitaminCTarget,
                'unit' => 'mg',
                'importance' => 'high',
                'tip' => 'Antioxidante y sistema inmune'
            ],
            'vitamin_d' => [
                'target' => $vitaminDTarget,
                'unit' => 'IU',
                'importance' => 'high',
                'tip' => 'Salud ósea y función muscular'
            ],
            'calcium' => [
                'target' => $calciumTarget,
                'unit' => 'mg',
                'importance' => 'high',
                'tip' => 'Huesos fuertes y contracción muscular'
            ],
            'iron' => [
                'target' => $ironTarget,
                'unit' => 'mg',
                'importance' => 'high',
                'tip' => 'Transporte de oxígeno y energía'
            ],
            'magnesium' => [
                'target' => $magnesiumTarget,
                'unit' => 'mg',
                'importance' => 'high',
                'tip' => 'Función muscular y metabolismo energético'
            ],
            'potassium' => [
                'target' => $potassiumTarget,
                'unit' => 'mg',
                'importance' => 'medium',
                'tip' => 'Balance hídrico y presión arterial'
            ],
            'sodium' => [
                'target' => $sodiumMax,
                'unit' => 'mg',
                'importance' => 'limit',
                'tip' => 'No exceder para evitar retención de líquidos'
            ]
        ];
    }

    private function getAgeGroup($age): string
    {
        if (!$age) return 'desconocido';
        if ($age < 20) return 'juvenil';
        if ($age < 30) return 'adulto_joven';
        if ($age < 50) return 'adulto';
        if ($age < 65) return 'adulto_mayor';
        return 'senior';
    }

    private function normalizeSex($sex)
    {
        if (!$sex) return null;
        $s = strtolower(trim($sex));
        if (in_array($s, ['masculino', 'male', 'hombre', 'm'])) return 'masculino';
        if (in_array($s, ['femenino', 'female', 'mujer', 'f'])) return 'femenino';
        return null;
    }

    private function validateAnthropometricData($profile): void
    {
        $errors = [];

        if (!$profile->age || $profile->age < 16 || $profile->age > 80) {
            $errors[] = "Edad inválida: {$profile->age}. Debe estar entre 16 y 80 años.";
        }

        if (!$profile->weight || $profile->weight < 30 || $profile->weight > 300) {
            $errors[] = "Peso inválido: {$profile->weight}kg. Debe estar entre 30 y 300 kg.";
        }

        if (!$profile->height || $profile->height < 120 || $profile->height > 250) {
            $errors[] = "Altura inválida: {$profile->height}cm. Debe estar entre 120 y 250 cm.";
        }

        if (!$this->normalizeSex($profile->sex)) {
            $errors[] = "Sexo inválido: {$profile->sex}. Debe ser Masculino o Femenino.";
        }

        if ($profile->weight && $profile->height) {
            $bmi = $this->calculateBMI($profile->weight, $profile->height);
            if ($bmi < 15 || $bmi > 50) {
                $errors[] = "BMI extremo: {$bmi}. Los cálculos pueden no ser precisos.";
            }
        }

        if (!empty($errors)) {
            Log::error("Datos antropométricos inválidos para usuario {$profile->user_id}", $errors);
            throw new \Exception("Datos antropométricos inválidos: " . implode(', ', $errors));
        }

        Log::info("Datos antropométricos validados correctamente", [
            'user_id' => $profile->user_id,
            'age' => $profile->age,
            'weight' => $profile->weight,
            'height' => $profile->height,
            'sex' => $profile->sex,
            'bmi' => round($this->calculateBMI($profile->weight, $profile->height), 2)
        ]);
    }

    private function calculateBMI($weight, $height): float
    {
        if (!$weight || !$height) return 0;
        $heightInMeters = $height / 100;
        return $weight / ($heightInMeters * $heightInMeters);
    }

    private function getWeightStatus($bmi): string
    {
        if ($bmi < 18.5) return 'bajo_peso';
        if ($bmi < 25) return 'peso_normal';
        if ($bmi < 30) return 'sobrepeso';
        if ($bmi < 35) return 'obesidad_grado_1';
        if ($bmi < 40) return 'obesidad_grado_2';
        return 'obesidad_grado_3';
    }

    private function calculateIdealWeightRange($height, $sex): array
    {
        if (!$height) return ['min' => 0, 'max' => 0];

        $heightInMeters = $height / 100;
        $minWeight = 18.5 * ($heightInMeters * $heightInMeters);
        $maxWeight = 24.9 * ($heightInMeters * $heightInMeters);

        return [
            'min' => round($minWeight, 1),
            'max' => round($maxWeight, 1)
        ];
    }

    private function getBMRCategory($age): string
    {
        if ($age < 20) return 'juvenil';
        if ($age < 30) return 'adulto_joven';
        if ($age < 50) return 'adulto';
        if ($age < 65) return 'adulto_mayor';
        return 'senior';
    }

    private function calculateTMB($sex, $weight, $height, $age): float
    {
        if ($sex === 'masculino') {
            return 66.473 + (13.751 * $weight) + (5.003 * $height) - (6.755 * $age);
        } else {
            return 655.0955 + (9.463 * $weight) + (1.8496 * $height) - (4.6756 * $age);
        }
    }

    private function getExactActivityFactor($weeklyActivity): float
    {
        $factorMap = [
            'No me muevo y no entreno' => 1.20,
            'Oficina + entreno 1-2 veces' => 1.37,
            'Oficina + entreno 3-4 veces' => 1.45,
            'Oficina + entreno 5-6 veces' => 1.48,
            'Trabajo activo + entreno 1-2 veces' => 1.48,
            'Trabajo activo + entreno 3-4 veces' => 1.68,
            'Trabajo muy físico + entreno 5-6 veces' => 1.80
        ];

        if (isset($factorMap[$weeklyActivity])) {
            return $factorMap[$weeklyActivity];
        }

        foreach ($factorMap as $activity => $factor) {
            if (str_contains($weeklyActivity, $activity)) {
                return $factor;
            }
        }

        Log::warning("Factor de actividad no encontrado: {$weeklyActivity}. Usando valor por defecto.");
        return 1.37;
    }
}