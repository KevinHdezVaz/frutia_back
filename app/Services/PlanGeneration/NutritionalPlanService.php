<?php

namespace App\Services\PlanGeneration;

use Illuminate\Support\Facades\Log;

class NutritionalPlanService
{
    private const FOOD_PREFERENCES = [
        'carbohydrates' => [
            'lunch_dinner' => ['Potato', 'White rice', 'Sweet potato', 'Noodles', 'Beans', 'Quinoa'],
            'breakfast' => ['Oats', 'Whole wheat bread', 'Corn tortilla'],
            'snacks' => ['Corn cereal', 'Cream of rice', 'Rice crackers', 'Oats']
        ],
        'proteins' => [
            'low' => [
                'breakfast' => ['Whole egg', 'Egg whites + Whole egg'],
                'lunch_dinner' => ['Chicken breast', 'Ground beef', 'Canned tuna', 'White fish'],
                'snacks' => ['Greek yogurt']
            ],
            'high' => [
                'breakfast' => ['Egg whites + Whole egg', 'Whey protein', 'High-protein Greek yogurt', 'Casein'],
                'lunch_dinner' => ['White fish', 'Chicken breast', 'Turkey breast', 'Lean beef', 'Fresh salmon'],
                'snacks' => ['High-protein Greek yogurt', 'Whey protein', 'Casein']
            ]
        ],
        'fats' => [
            'low' => ['Olive oil', 'Peanuts', 'Low-fat cheese', 'Homemade peanut butter', 'Sesame seeds', 'Olives'],
            'high' => ['Extra virgin olive oil', 'Avocado oil', 'Hass avocado', 'Almonds', 'Walnuts', 'Pistachios', 'Pecans', 'Organic chia seeds', 'Organic flaxseed', 'Peanut butter', 'Honey', '70% dark chocolate']
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
                'country' => $profile->pais ?? __('not_specified'),
                'bmi' => $this->calculateBMI($profile->weight, $profile->height),
                'age_group' => $this->getAgeGroup($profile->age),
                'sex_normalized' => $this->normalizeSex($profile->sex)
            ],
            'activity_data' => [
                'weekly_activity' => $profile->weekly_activity,
                'sports' => is_string($profile->sport) ? json_decode($profile->sport, true) : ($profile->sport ?? []),
                'training_frequency' => $profile->training_frequency ?? __('not_specified')
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
                'dietary_style' => $profile->dietary_style ?? __('omnivorous'),
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
            'country' => $profile->pais ?? __('not_specified'),
            'anthropometric_data' => [
                'bmi' => $this->calculateBMI($profile->weight, $profile->height),
                'bmr_category' => $this->getBMRCategory($profile->age),
                'weight_status' => $this->getWeightStatus($this->calculateBMI($profile->weight, $profile->height)),
                'ideal_weight_range' => $this->calculateIdealWeightRange($profile->height, $profile->sex)
            ],
            'health_status' => [
                'medical_condition' => $profile->medical_condition ?? __('none'),
                'allergies' => $profile->allergies ?? __('none'),
                'has_medical_condition' => $profile->has_medical_condition ?? false,
                'has_allergies' => $profile->has_allergies ?? false
            ],
            'preferences' => [
                'name' => $userName,
                'preferred_name' => $userName,
                'dietary_style' => $profile->dietary_style ?? __('omnivorous'),
                'disliked_foods' => $profile->disliked_foods ?? __('none'),
                'budget' => $profile->budget ?? __('medium'),
                'meal_count' => $profile->meal_count ?? __('3_main_meals'),
                'eats_out' => $profile->eats_out ?? __('sometimes'),
                'communication_style' => $profile->communication_style ?? __('close')
            ],
            'meal_times' => [
                'breakfast_time' => $profile->breakfast_time ?? '07:00',
                'lunch_time' => $profile->lunch_time ?? '13:00',
                'dinner_time' => $profile->dinner_time ?? '20:00'
            ],
            'sports_data' => [
                'sports' => is_string($profile->sport) ? json_decode($profile->sport, true) : ($profile->sport ?? []),
                'training_frequency' => $profile->training_frequency ?? __('moderate')
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

        if (str_contains($goalLower, 'lose fat')) {
            $proteinPercentage = 0.35;
            $carbPercentage = 0.40;
            $fatPercentage = 0.25;
        } elseif (str_contains($goalLower, 'increase muscle')) {
            $proteinPercentage = 0.25;
            $carbPercentage = 0.50;
            $fatPercentage = 0.25;
        } elseif (str_contains($goalLower, 'eat healthier')) {
            $proteinPercentage = 0.30;
            $carbPercentage = 0.40;
            $fatPercentage = 0.30;
        } elseif (str_contains($goalLower, 'improve performance')) {
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

        if (str_contains($goalLower, 'lose fat')) {
            if (strtolower($sex) === 'femenino') {
                return $get * 0.75;
            } else {
                return $get * 0.65;
            }
        } elseif (str_contains($goalLower, 'increase muscle')) {
            return $get * 1.15;
        } elseif (str_contains($goalLower, 'eat healthier')) {
            return $get * 0.95;
        } elseif (str_contains($goalLower, 'improve performance')) {
            return $get * 1.05;
        } else {
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

        if (str_contains($goal, 'lose fat')) {
            $fiberTarget += 5;
            $potassiumTarget += 500;
        } elseif (str_contains($goal, 'increase muscle')) {
            $magnesiumTarget += 100;
            $vitaminDTarget = 800;
        }

        return [
            'fiber' => [
                'target' => $fiberTarget,
                'unit' => 'g',
                'importance' => 'critical',
                'tip' => __('essential_for_satiety_and_digestive_health')
            ],
            'vitamin_c' => [
                'target' => $vitaminCTarget,
                'unit' => 'mg',
                'importance' => 'high',
                'tip' => __('antioxidant_and_immune_system')
            ],
            'vitamin_d' => [
                'target' => $vitaminDTarget,
                'unit' => 'IU',
                'importance' => 'high',
                'tip' => __('bone_health_and_muscle_function')
            ],
            'calcium' => [
                'target' => $calciumTarget,
                'unit' => 'mg',
                'importance' => 'high',
                'tip' => __('strong_bones_and_muscle_contraction')
            ],
            'iron' => [
                'target' => $ironTarget,
                'unit' => 'mg',
                'importance' => 'high',
                'tip' => __('oxygen_transport_and_energy')
            ],
            'magnesium' => [
                'target' => $magnesiumTarget,
                'unit' => 'mg',
                'importance' => 'high',
                'tip' => __('muscle_function_and_energy_metabolism')
            ],
            'potassium' => [
                'target' => $potassiumTarget,
                'unit' => 'mg',
                'importance' => 'medium',
                'tip' => __('water_balance_and_blood_pressure')
            ],
            'sodium' => [
                'target' => $sodiumMax,
                'unit' => 'mg',
                'importance' => 'limit',
                'tip' => __('do_not_exceed_to_avoid_fluid_retention')
            ]
        ];
    }

    private function getAgeGroup($age): string
    {
        if (!$age) return __('unknown');
        if ($age < 20) return __('youth');
        if ($age < 30) return __('young_adult');
        if ($age < 50) return __('adult');
        if ($age < 65) return __('older_adult');
        return __('senior');
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
            $errors[] = __("invalid_age: :age. Must be between 16 and 80 years.", ['age' => $profile->age]);
        }

        if (!$profile->weight || $profile->weight < 30 || $profile->weight > 300) {
            $errors[] = __("invalid_weight: :weight kg. Must be between 30 and 300 kg.", ['weight' => $profile->weight]);
        }

        if (!$profile->height || $profile->height < 120 || $profile->height > 250) {
            $errors[] = __("invalid_height: :height cm. Must be between 120 and 250 cm.", ['height' => $profile->height]);
        }

        if (!$this->normalizeSex($profile->sex)) {
            $errors[] = __("invalid_sex: :sex. Must be Male or Female.", ['sex' => $profile->sex]);
        }

        if ($profile->weight && $profile->height) {
            $bmi = $this->calculateBMI($profile->weight, $profile->height);
            if ($bmi < 15 || $bmi > 50) {
                $errors[] = __("extreme_bmi: :bmi. Calculations may not be accurate.", ['bmi' => $bmi]);
            }
        }

        if (!empty($errors)) {
            Log::error("Invalid anthropometric data for user {$profile->user_id}", $errors);
            throw new \Exception(__("invalid_anthropometric_data: :errors", ['errors' => implode(', ', $errors)]));
        }

        Log::info("Anthropometric data validated successfully", [
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
        if ($bmi < 18.5) return __('underweight');
        if ($bmi < 25) return __('normal_weight');
        if ($bmi < 30) return __('overweight');
        if ($bmi < 35) return __('obesity_grade_1');
        if ($bmi < 40) return __('obesity_grade_2');
        return __('obesity_grade_3');
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
        if ($age < 20) return __('youth');
        if ($age < 30) return __('young_adult');
        if ($age < 50) return __('adult');
        if ($age < 65) return __('older_adult');
        return __('senior');
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

        Log::warning("Activity factor not found: {$weeklyActivity}. Using default value.");
        return 1.37;
    }
}