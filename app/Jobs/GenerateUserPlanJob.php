<?php
namespace App\Jobs;

use App\Models\User;
use App\Models\MealPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Jobs\EnrichPlanWithPricesJob;
use App\Jobs\GenerateRecipeImagesJob;
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
        Log::info('Iniciando GenerateUserPlanJob PERSONALIZADO', ['userId' => $this->userId]);
        
        $user = User::with('profile')->find($this->userId);
        if (!$user || !$user->profile) {
            Log::error('Usuario o perfil no encontrado.', ['userId' => $this->userId]);
            return;
        }
    
        // OBTENER EL NOMBRE DEL USUARIO DESDE LA TABLA users
        $userName = $user->name;
        Log::info('Nombre del usuario obtenido', ['userId' => $this->userId, 'name' => $userName]);
    
        try {
            // PASO 1: Calcular macros siguiendo la metodología del PDF con datos completos del perfil
            Log::info('Paso 1: Calculando TMB, GET y macros objetivo con perfil completo.', ['userId' => $user->id]);
            $nutritionalData = $this->calculateCompleteNutritionalPlan($user->profile, $userName);            
            // PASAR EL NOMBRE AL MÉTODO DE EXTRACCIÓN DE DATOS
            
            $personalizationData = $this->extractPersonalizationData($user->profile, $userName);

            // PASO 2: Generar plan ultra-personalizado con IA
            Log::info('Paso 2: Generando plan nutricional ULTRA-PERSONALIZADO.', ['userId' => $user->id]);
            $planData = $this->generateUltraPersonalizedNutritionalPlan($user->profile, $nutritionalData, $userName);
            
            if ($planData === null) {
                Log::warning('La IA no generó un plan válido. Usando plan de respaldo personalizado.', ['userId' => $user->id]);
                $planData = $this->getPersonalizedBackupPlan($user->profile, $nutritionalData, $userName);
            }
    
            // ... resto del código sin cambios
            // PASO 3: Generar recetas ultra-específicas con IA
            Log::info('Paso 3: Generando recetas ultra-específicas basadas en perfil completo.', ['userId' => $user->id]);
            $planWithRecipes = $this->generatePersonalizedRecipes($planData, $user->profile, $nutritionalData);
            
            // PASO 4: Guardado del plan completo
            Log::info('Almacenando plan ultra-personalizado en la base de datos.', ['userId' => $user->id]);
            MealPlan::where('user_id', $user->id)->update(['is_active' => false]);
            
            $mealPlan = MealPlan::create([
                'user_id' => $user->id,
                'plan_data' => $planWithRecipes,
                'nutritional_data' => $nutritionalData,
                'personalization_data' => $this->extractPersonalizationData($user->profile, $userName), // ✅ CAMBIO: Pasar el userName
                'is_active' => true,
            ]); 

            // PASO 5: Despachar jobs de enriquecimiento
            Log::info('Despachando jobs de enriquecimiento.', ['mealPlanId' => $mealPlan->id]);
            EnrichPlanWithPricesJob::dispatch($mealPlan->id);
            // GenerateRecipeImagesJob::dispatch($mealPlan->id)->onQueue('images');

            Log::info('Plan ULTRA-PERSONALIZADO generado exitosamente.', ['userId' => $user->id, 'mealPlanId' => $mealPlan->id]);
            
        } catch (\Exception $e) {
            Log::error('Excepción crítica en GenerateUserPlanJob', [
                'userId' => $this->userId, 
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

     private function extractPersonalizationData($profile, $userName): array
    {
        return [
            // Datos personales básicos (CRÍTICOS PARA CÁLCULOS)
            'personal_data' => [
                'name' => $userName, // Usar el nombre de la tabla users
                 'preferred_name' => $userName, // ✅ CAMBIO: Siempre usar el mismo nombre

                'goal' => $profile->goal,
                // DATOS CRÍTICOS PARA TMB Y MACROS
                'age' => (int)$profile->age,
                'sex' => strtolower($profile->sex), // Normalizado para cálculos
                'weight' => (float)$profile->weight, // En kg
                'height' => (float)$profile->height, // En cm
                'country' => $profile->pais ?? 'No especificado',
                // Datos derivados para validación
                'bmi' => $this->calculateBMI($profile->weight, $profile->height),
                'age_group' => $this->getAgeGroup($profile->age),
                'sex_normalized' => $profile->sex === 'Masculino' ? 'masculino' : 'femenino'
            ],
    
            // Actividad física y deportes
            'activity_data' => [
                'weekly_activity' => $profile->weekly_activity,
                'sports' => is_string($profile->sport) ? json_decode($profile->sport, true) : ($profile->sport ?? []),
                'training_frequency' => $profile->training_frequency ?? 'No especificado'
            ],
            
            // Estructura de comidas y horarios
            'meal_structure' => [
                'meal_count' => $profile->meal_count,
                'breakfast_time' => $profile->breakfast_time,
                'lunch_time' => $profile->lunch_time,
                'dinner_time' => $profile->dinner_time,
                'eats_out' => $profile->eats_out
            ],
            
            // Preferencias dietéticas y restricciones
            'dietary_preferences' => [
                'dietary_style' => $profile->dietary_style ?? 'Omnívoro',
                'budget' => $profile->budget,
                'disliked_foods' => $profile->disliked_foods ?? '',
                'has_allergies' => $profile->has_allergies ?? false,
                'allergies' => $profile->allergies ?? '',
                'has_medical_condition' => $profile->has_medical_condition ?? false,
                'medical_condition' => $profile->medical_condition ?? ''
            ],
            
            // Personalización emocional
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
    
    /**
     * Determinar grupo de edad
     */
    private function getAgeGroup($age): string
    {
        if (!$age) return 'desconocido';
        if ($age < 20) return 'juvenil';
        if ($age < 30) return 'adulto_joven';
        if ($age < 50) return 'adulto';
        if ($age < 65) return 'adulto_mayor';
        return 'senior';
    }
      
    /**
     * PASO 1-4 DEL PDF: Cálculo completo con TODOS los datos del perfil
     */
    private function calculateCompleteNutritionalPlan($profile, $userName): array
        {
        // VALIDACIÓN CRÍTICA DE DATOS ANTROPOMÉTRICOS
        $this->validateAnthropometricData($profile);
        $userName = $profile->user->name ?? 'Usuario';

        // 1. RECOLECCIÓN COMPLETA DE DATOS
        $basicData = [
            // DATOS CRÍTICOS PARA CÁLCULOS TMB (OBLIGATORIOS)
            'age' => (int)$profile->age,
            'sex' => strtolower($profile->sex), // Normalizado: masculino/femenino
            'weight' => (float)$profile->weight, // kg
            'height' => (float)$profile->height, // cm
            'activity_level' => $profile->weekly_activity,
            'goal' => $profile->goal,
            'country' => $profile->pais ?? 'No especificado',
            
            // DATOS DERIVADOS PARA ANÁLISIS
            'anthropometric_data' => [
                'bmi' => $this->calculateBMI($profile->weight, $profile->height),
                'bmr_category' => $this->getBMRCategory($profile->age),
                'weight_status' => $this->getWeightStatus($this->calculateBMI($profile->weight, $profile->height)),
                'ideal_weight_range' => $this->calculateIdealWeightRange($profile->height, $profile->sex)
            ],
            
            // Datos de salud
            'health_status' => [
                'medical_condition' => $profile->medical_condition ?? 'Ninguna',
                'allergies' => $profile->allergies ?? 'Ninguna',
                'has_medical_condition' => $profile->has_medical_condition ?? false,
                'has_allergies' => $profile->has_allergies ?? false
            ],
            
            // Preferencias completas
            'preferences' => [
                'name' => $userName, // Usar el nombre de la tabla users
                'preferred_name' => $userName, // ✅ CAMBIO: Usar siempre el mismo nombre

                'dietary_style' => $profile->dietary_style ?? 'Omnívoro',
                'disliked_foods' => $profile->disliked_foods ?? 'Ninguno',
                'budget' => $profile->budget ?? 'Medio',
                'meal_count' => $profile->meal_count ?? '3 comidas principales',
                'eats_out' => $profile->eats_out ?? 'A veces',
                'communication_style' => $profile->communication_style ?? 'Cercana'
            ],
            
            // Horarios de comida
            'meal_times' => [
                'breakfast_time' => $profile->breakfast_time ?? '07:00',
                'lunch_time' => $profile->lunch_time ?? '13:00',
                'dinner_time' => $profile->dinner_time ?? '20:00'
            ],
            
            // Actividad física específica
            'sports_data' => [
                'sports' => is_string($profile->sport) ? json_decode($profile->sport, true) : ($profile->sport ?? []),
                'training_frequency' => $profile->training_frequency ?? 'Moderado'
            ],
            
            // Personalización emocional
            'emotional_profile' => [
                'diet_difficulties' => is_string($profile->diet_difficulties) 
                    ? json_decode($profile->diet_difficulties, true) 
                    : ($profile->diet_difficulties ?? []),
                'diet_motivations' => is_string($profile->diet_motivations) 
                    ? json_decode($profile->diet_motivations, true) 
                    : ($profile->diet_motivations ?? [])
            ]
        ];

        // 2. CÁLCULO DE LA TASA METABÓLICA BASAL (TMB) - Fórmulas Harris-Benedict
        $tmb = $this->calculateTMB($basicData['sex'], $basicData['weight'], $basicData['height'], $basicData['age']);
        
        // 3. CÁLCULO DEL GASTO ENERGÉTICO TOTAL (GET) con factor exacto del PDF
        $activityFactor = $this->getExactActivityFactor($basicData['activity_level']);
        $get = $tmb * $activityFactor;
        
        // 4. AJUSTE SEGÚN EL OBJETIVO (progresivo por mes)
        $adjustedCalories = $this->adjustCaloriesForGoal($get, $basicData['goal'], $basicData['weight'], $basicData['anthropometric_data']['weight_status']);
        
        // 5. DISTRIBUCIÓN DE MACRONUTRIENTES con ajustes por antropometría
        $macros = $this->calculatePersonalizedMacronutrients($adjustedCalories, $basicData['weight'], $basicData['goal'], $basicData['preferences']['dietary_style'], $basicData['anthropometric_data']);
        
        return [
            'basic_data' => $basicData,
            'tmb' => round($tmb),
            'activity_factor' => $activityFactor,
            'get' => round($get),
            'target_calories' => round($adjustedCalories),
            'macros' => $macros,
            'month' => 1,
            'calculation_date' => now(),
            'personalization_level' => 'ultra_high',
            // DATOS ADICIONALES PARA VALIDACIÓN
            'anthropometric_analysis' => [
                'tmb_per_kg' => round($tmb / $basicData['weight'], 2),
                'calories_per_kg' => round($adjustedCalories / $basicData['weight'], 2),
                'protein_per_kg' => round($macros['protein']['grams'] / $basicData['weight'], 2),
                'recommended_adjustments' => $this->getAnthropometricRecommendations($basicData['anthropometric_data'], $basicData['goal'])
            ]
        ];
    }

    /**
     * VALIDACIÓN CRÍTICA de datos antropométricos
     */
    private function validateAnthropometricData($profile): void
    {
        $errors = [];
        
        // Validar edad
        if (!$profile->age || $profile->age < 16 || $profile->age > 80) {
            $errors[] = "Edad inválida: {$profile->age}. Debe estar entre 16 y 80 años.";
        }
        
        // Validar peso
        if (!$profile->weight || $profile->weight < 30 || $profile->weight > 300) {
            $errors[] = "Peso inválido: {$profile->weight}kg. Debe estar entre 30 y 300 kg.";
        }
        
        // Validar altura
        if (!$profile->height || $profile->height < 120 || $profile->height > 250) {
            $errors[] = "Altura inválida: {$profile->height}cm. Debe estar entre 120 y 250 cm.";
        }
        
        // Validar sexo
        if (!$profile->sex || !in_array(strtolower($profile->sex), ['masculino', 'femenino'])) {
            $errors[] = "Sexo inválido: {$profile->sex}. Debe ser Masculino o Femenino.";
        }
        
        // Validar BMI extremo
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

    /**
     * Calcular BMI
     */
    private function calculateBMI($weight, $height): float
    {
        if (!$weight || !$height) return 0;
        $heightInMeters = $height / 100;
        return $weight / ($heightInMeters * $heightInMeters);
    }

    /**
     * Determinar estado del peso según BMI
     */
    private function getWeightStatus($bmi): string
    {
        if ($bmi < 18.5) return 'bajo_peso';
        if ($bmi < 25) return 'peso_normal';
        if ($bmi < 30) return 'sobrepeso';
        if ($bmi < 35) return 'obesidad_grado_1';
        if ($bmi < 40) return 'obesidad_grado_2';
        return 'obesidad_grado_3';
    }

    /**
     * Calcular rango de peso ideal
     */
    private function calculateIdealWeightRange($height, $sex): array
    {
        if (!$height) return ['min' => 0, 'max' => 0];
        
        $heightInMeters = $height / 100;
        
        // Usar BMI 18.5-24.9 para rango saludable
        $minWeight = 18.5 * ($heightInMeters * $heightInMeters);
        $maxWeight = 24.9 * ($heightInMeters * $heightInMeters);
        
        return [
            'min' => round($minWeight, 1),
            'max' => round($maxWeight, 1)
        ];
    }

    /**
     * Categoría BMR según edad
     */
    private function getBMRCategory($age): string
    {
        if ($age < 20) return 'juvenil';
        if ($age < 30) return 'adulto_joven';
        if ($age < 50) return 'adulto';
        if ($age < 65) return 'adulto_mayor';
        return 'senior';
    }

    /**
     * Recomendaciones basadas en datos antropométricos
     */
    private function getAnthropometricRecommendations($anthropometricData, $goal): array
    {
        $recommendations = [];
        
        $bmi = $anthropometricData['bmi'];
        $weightStatus = $anthropometricData['weight_status'];
        
        if ($weightStatus === 'bajo_peso' && str_contains(strtolower($goal), 'bajar grasa')) {
            $recommendations[] = "ADVERTENCIA: BMI bajo ({$bmi}). Considerar objetivo de ganancia de peso saludable.";
        }
        
        if ($weightStatus === 'obesidad_grado_2' || $weightStatus === 'obesidad_grado_3') {
            $recommendations[] = "BMI alto ({$bmi}). Déficit calórico conservador recomendado.";
        }
        
        if ($bmi > 30 && str_contains(strtolower($goal), 'aumentar músculo')) {
            $recommendations[] = "Considerar recomposición corporal: pérdida de grasa + ganancia muscular simultánea.";
        }
        
        return $recommendations;
    }

    /**
     * Cálculo TMB usando las fórmulas exactas del PDF
     */
    private function calculateTMB($sex, $weight, $height, $age): float
    {
        if ($sex === 'masculino') {
            // TMB = 66.473 + (13.751 × peso en kg) + (5.003 × altura en cm) – (6.755 × edad en años)
            return 66.473 + (13.751 * $weight) + (5.003 * $height) - (6.755 * $age);
        } else {
            // TMB = 655.0955 + (9.463 × peso en kg) + (1.8496 × altura en cm) – (4.6756 × edad en años)
            return 655.0955 + (9.463 * $weight) + (1.8496 * $height) - (4.6756 * $age);
        }
    }

    /**
     * Factor de actividad EXACTO según las 7 opciones del PDF
     */
    private function getExactActivityFactor($weeklyActivity): float
    {
        // Mapeo exacto con las opciones que envía el frontend
        $factorMap = [
            'No me muevo y no entreno' => 1.20,
            'Oficina + entreno 1-2 veces' => 1.37,
            'Oficina + entreno 3-4 veces' => 1.45,
            'Oficina + entreno 5-6 veces' => 1.55,
            'Trabajo activo + entreno 1-2 veces' => 1.55,
            'Trabajo activo + entreno 3-4 veces' => 1.72,
            'Trabajo muy físico + entreno 5-6 veces' => 1.90
        ];

        // Buscar coincidencia exacta primero
        if (isset($factorMap[$weeklyActivity])) {
            return $factorMap[$weeklyActivity];
        }

        // Si no encuentra coincidencia exacta, buscar parcial
        foreach ($factorMap as $activity => $factor) {
            if (str_contains($weeklyActivity, $activity)) {
                return $factor;
            }
        }

        Log::warning("Factor de actividad no encontrado: {$weeklyActivity}. Usando valor por defecto.");
        return 1.37; // Por defecto: ligero
    }

    /**
     * Ajuste calórico según objetivo con consideraciones antropométricas
     */
    private function adjustCaloriesForGoal($get, $goal, $weight, $weightStatus): float
    {
        $goalLower = strtolower($goal);
        
        if (str_contains($goalLower, 'bajar grasa')) {
            // Ajuste del déficit según peso/BMI
            if ($weightStatus === 'obesidad_grado_2' || $weightStatus === 'obesidad_grado_3') {
                // Déficit más agresivo para obesidad severa
                return $get * 0.75; // -25% para casos severos
            } else {
                // Déficit estándar del PDF: -20% primer mes
                return $get * 0.80;
            }
        } elseif (str_contains($goalLower, 'aumentar músculo')) {
            // Ajuste del superávit según peso
            if ($weightStatus === 'bajo_peso') {
                // Superávit mayor para personas con bajo peso
                return $get * 1.15; // +15% para recuperar peso
            } else {
                // Superávit estándar del PDF: +10% primer mes
                return $get * 1.10;
            }
        } else {
            // Mantenimiento para: comer más saludable, mejorar rendimiento
            return $get;
        }
    }

    /**
     * Distribución de macronutrientes PERSONALIZADA con consideraciones antropométricas
     */
    private function calculatePersonalizedMacronutrients($calories, $weight, $goal, $dietaryStyle, $anthropometricData): array
    {
        $dietStyle = strtolower($dietaryStyle);
        $weightStatus = $anthropometricData['weight_status'];
        $bmi = $anthropometricData['bmi'];
        
        // Ajustar proteínas según objetivo, peso corporal y composición
        if (str_contains(strtolower($goal), 'bajar grasa')) {
            $proteinMultiplier = 2.2; // Máximo para déficit calórico (según PDF)
            
            // Ajuste adicional para personas con sobrepeso/obesidad
            if ($weightStatus === 'obesidad_grado_1' || $weightStatus === 'obesidad_grado_2' || $weightStatus === 'obesidad_grado_3') {
                $proteinMultiplier = 2.4; // Más proteína para preservar masa magra
            }
        } elseif (str_contains(strtolower($goal), 'aumentar músculo')) {
            $proteinMultiplier = 2.0; // Alto para ganancia muscular
            
            // Ajuste para personas con bajo peso
            if ($weightStatus === 'bajo_peso') {
                $proteinMultiplier = 1.8; // Menor para permitir más carbohidratos
            }
        } else {
            $proteinMultiplier = 1.8; // Moderado para mantenimiento
        }

        // Ajustar según estilo dietético
        if ($dietStyle === 'vegano' || $dietStyle === 'vegetariano') {
            $proteinMultiplier += 0.2; // Más proteína para dietas vegetales
        }

        $proteinGrams = $weight * $proteinMultiplier;
        $proteinCalories = $proteinGrams * 4;

        // Ajustar grasas según estilo dietético y peso corporal
        $fatPercentage = 0.25; // 25% por defecto
        
        if (str_contains($dietStyle, 'keto')) {
            $fatPercentage = 0.70; // 70% para keto
        } elseif ($dietStyle === 'vegano') {
            $fatPercentage = 0.30; // Más grasas vegetales
        } elseif ($weightStatus === 'bajo_peso') {
            $fatPercentage = 0.30; // Más grasas para ganancia de peso saludable
        }

        $minFatGrams = $weight * 0.8; // Mínimo según PDF
        $fatCalories = $calories * $fatPercentage;
        $fatGrams = max($minFatGrams, $fatCalories / 9);
        $fatCalories = $fatGrams * 9;

        // Carbohidratos: el resto
        if (str_contains($dietStyle, 'keto')) {
            // Keto: máximo 50g carbohidratos
            $carbGrams = min(50, max(20, ($calories - $proteinCalories - $fatCalories) / 4));
            $carbCalories = $carbGrams * 4;
            
            // Reajustar grasas para keto
            $fatCalories = $calories - $proteinCalories - $carbCalories;
            $fatGrams = $fatCalories / 9;
        } else {
            $carbCalories = $calories - $proteinCalories - $fatCalories;
            $carbGrams = max(0, $carbCalories / 4);
        }

        return [
            'calories' => round($calories),
            'protein' => [
                'grams' => round($proteinGrams),
                'calories' => round($proteinCalories),
                'percentage' => round(($proteinCalories / $calories) * 100, 1),
                'per_kg' => round($proteinMultiplier, 2)
            ],
            'fats' => [
                'grams' => round($fatGrams),
                'calories' => round($fatCalories),
                'percentage' => round(($fatCalories / $calories) * 100, 1),
                'per_kg' => round($fatGrams / $weight, 2)
            ],
            'carbohydrates' => [
                'grams' => round($carbGrams),
                'calories' => round($carbCalories),
                'percentage' => round(($carbCalories / $calories) * 100, 1),
                'per_kg' => round($carbGrams / $weight, 2)
            ],
            'dietary_adjustments' => [
                'style' => $dietaryStyle,
                'protein_multiplier' => $proteinMultiplier,
                'fat_percentage' => $fatPercentage,
                'anthropometric_considerations' => [
                    'weight_status' => $weightStatus,
                    'bmi' => round($bmi, 1),
                    'adjustments_applied' => $this->getAppliedAdjustments($weightStatus, $goal)
                ]
            ]
        ];
    }

    private function getAppliedAdjustments($weightStatus, $goal): array
    {
        $adjustments = [];
        
        if ($weightStatus === 'obesidad_grado_2' || $weightStatus === 'obesidad_grado_3') {
            $adjustments[] = 'Proteína aumentada para preservar masa magra';
            if (str_contains(strtolower($goal), 'bajar grasa')) {
                $adjustments[] = 'Déficit calórico conservador para obesidad severa';
            }
        }
        
        if ($weightStatus === 'bajo_peso') {
            $adjustments[] = 'Grasas aumentadas para ganancia de peso saludable';
            if (str_contains(strtolower($goal), 'aumentar músculo')) {
                $adjustments[] = 'Superávit calórico mayor para recuperación';
            }
        }
        
        return $adjustments;
    }

    /**
     * Generación del plan ULTRA-PERSONALIZADO
     */
    private function generateUltraPersonalizedNutritionalPlan($profile, $nutritionalData, $userName): ?array
    {
         
        $prompt = $this->buildUltraPersonalizedPrompt($profile, $nutritionalData, $userName);
        

        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->timeout(150) // Más tiempo para procesamiento complejo
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.3, // Menos creatividad, más precisión
            ]);

        if ($response->successful()) {
            $planData = json_decode($response->json('choices.0.message.content'), true);
            
            // Agregar datos nutricionales calculados al plan
            $planData['nutritional_calculations'] = $nutritionalData;
            
            return $planData;
        }
        
        Log::error("Fallo en la llamada a OpenAI para generar plan personalizado", [
            'status' => $response->status(), 
            'body' => $response->body()
        ]);
        return null;
    }

    /**
     * Prompt ULTRA-PERSONALIZADO con todos los datos del perfil
     */
    private function buildUltraPersonalizedPrompt($profile, $nutritionalData, $userName): string    {
        $macros = $nutritionalData['macros'];
        $basicData = $nutritionalData['basic_data'];
        
        // Extraer datos específicos
        $preferredName = $userName; // ✅ CAMBIO: Usar directamente el userName

        $communicationStyle = $basicData['preferences']['communication_style'];
        $sports = !empty($basicData['sports_data']['sports']) ? implode(', ', $basicData['sports_data']['sports']) : 'Ninguno especificado';
        $mealTimes = $basicData['meal_times'];
        $difficulties = !empty($basicData['emotional_profile']['diet_difficulties']) ? implode(', ', $basicData['emotional_profile']['diet_difficulties']) : 'Ninguna especificada';
        $motivations = !empty($basicData['emotional_profile']['diet_motivations']) ? implode(', ', $basicData['emotional_profile']['diet_motivations']) : 'Ninguna especificada';
        
        // Instrucciones específicas según presupuesto
        $budgetInstructions = $this->getDetailedBudgetInstructions($basicData['preferences']['budget'], $basicData['country']);
        
        // Instrucciones dietéticas específicas
        $dietaryInstructions = $this->getDetailedDietaryInstructions($basicData['preferences']['dietary_style']);
        
        // Instrucciones de comunicación personalizadas
        $communicationInstructions = $this->getCommunicationStyleInstructions($communicationStyle, $preferredName);
        
        // Alimentos específicos por país
        $countrySpecificFoods = $this->getCountrySpecificFoods($basicData['country'], $basicData['preferences']['budget']);

        return "
        Eres un nutricionista experto especializado en planes alimentarios ULTRA-PERSONALIZADOS. Tu cliente se llama {$preferredName} y has trabajado con él/ella durante meses, conoces perfectamente sus necesidades.
        
        **INFORMACIÓN NUTRICIONAL CALCULADA (USAR EXACTAMENTE):**
        - TMB (Tasa Metabólica Basal): {$nutritionalData['tmb']} kcal
        - Factor de Actividad: {$nutritionalData['activity_factor']} ({$basicData['activity_level']})
        - GET (Gasto Energético Total): {$nutritionalData['get']} kcal
        - Calorías Objetivo: {$nutritionalData['target_calories']} kcal
        
        **MACRONUTRIENTES CALCULADOS ESPECÍFICAMENTE (OBLIGATORIO CUMPLIR):**
        - Proteínas: {$macros['protein']['grams']}g ({$macros['protein']['calories']} kcal, {$macros['protein']['percentage']}%)
        - Grasas: {$macros['fats']['grams']}g ({$macros['fats']['calories']} kcal, {$macros['fats']['percentage']}%)
        - Carbohidratos: {$macros['carbohydrates']['grams']}g ({$macros['carbohydrates']['calories']} kcal, {$macros['carbohydrates']['percentage']}%)
        
        **PERFIL ANTROPOMÉTRICO COMPLETO DE {$preferredName}:**
        
        *Datos Físicos (Base para Cálculos):*
        - Edad: {$basicData['age']} años, {$basicData['sex']}
        - Peso: {$basicData['weight']} kg, Altura: {$basicData['height']} cm
        - BMI: {$basicData['anthropometric_data']['bmi']} ({$basicData['anthropometric_data']['weight_status']})
        - Peso ideal: {$basicData['anthropometric_data']['ideal_weight_range']['min']}-{$basicData['anthropometric_data']['ideal_weight_range']['max']} kg
        - País: {$basicData['country']}
        - Objetivo: {$basicData['goal']}
        
        *Actividad Física:*
        - Nivel semanal: {$basicData['activity_level']}
        - Deportes practicados: {$sports}
        - Frecuencia entrenamiento: {$basicData['sports_data']['training_frequency']}
        
        *Estructura de Comidas:*
        - Preferencia: {$basicData['preferences']['meal_count']}
        - Horario desayuno: {$mealTimes['breakfast_time']}
        - Horario almuerzo: {$mealTimes['lunch_time']}
        - Horario cena: {$mealTimes['dinner_time']}
        - Come fuera: {$basicData['preferences']['eats_out']}
        
        *Preferencias Dietéticas:*
        - Estilo alimentario: {$basicData['preferences']['dietary_style']}
        - Presupuesto: {$basicData['preferences']['budget']}
        - Alimentos que NO le gustan: {$basicData['preferences']['disliked_foods']}
        - Alergias: {$basicData['health_status']['allergies']} (Tiene alergias: " . ($basicData['health_status']['has_allergies'] ? 'Sí' : 'No') . ")
        - Condición médica: {$basicData['health_status']['medical_condition']} (Tiene condición: " . ($basicData['health_status']['has_medical_condition'] ? 'Sí' : 'No') . ")
        
        *Perfil Emocional y Personalización:*
        - Estilo de comunicación preferido: {$communicationStyle}
        - Sus principales dificultades: {$difficulties}
        - Sus motivaciones: {$motivations}
        
        **CONSIDERACIONES ANTROPOMÉTRICAS ESPECÍFICAS:**
        " . (!empty($basicData['anthropometric_data']['recommended_adjustments']) ? 
            "- " . implode("\n- ", $basicData['anthropometric_data']['recommended_adjustments']) : 
            "- Sin ajustes antropométricos especiales requeridos") . "
        
        {$budgetInstructions}
        {$dietaryInstructions}
        {$communicationInstructions}
        
        **ALIMENTOS ESPECÍFICOS PARA {$basicData['country']} Y SU PRESUPUESTO:**
        {$countrySpecificFoods}
        
        **REGLAS CRÍTICAS PARA EL PLAN DE {$preferredName}:**
        1. **MACROS EXACTOS CALCULADOS:** Los macros finales DEBEN coincidir exactamente con los calculados específicamente para su edad ({$basicData['age']}), peso ({$basicData['weight']}kg), altura ({$basicData['height']}cm) y sexo ({$basicData['sex']})
        2. **CONSIDERACIONES BMI:** BMI actual: {$basicData['anthropometric_data']['bmi']} - Status: {$basicData['anthropometric_data']['weight_status']} - Ajustar plan según estado corporal
        3. **HORARIOS PERSONALIZADOS:** Respeta sus horarios preferidos de comida
        4. **EVITAR ALIMENTOS:** NO incluir nunca: {$basicData['preferences']['disliked_foods']}
        5. **CONSIDERACIONES MÉDICAS:** " . ($basicData['health_status']['has_medical_condition'] ? "IMPORTANTE: Considerar su condición médica: {$basicData['health_status']['medical_condition']}" : "Sin restricciones médicas especiales") . "
        6. **ALERGIAS:** " . ($basicData['health_status']['has_allergies'] ? "CRÍTICO: Evitar completamente: {$basicData['health_status']['allergies']}" : "Sin alergias reportadas") . "
        7. **INTERCAMBIOS EQUIVALENTES:** Cada opción debe tener ±10 kcal, ±2g proteína máximo de diferencia
        8. **PESOS ESPECÍFICOS:** SIEMPRE especificar si es crudo/seco o cocido
        9. **MOTIVACIÓN:** Incluir elementos que apoyen sus motivaciones: {$motivations}
        10. **DIFICULTADES:** Considerar y facilitar soluciones para: {$difficulties}
        11. **EDAD Y METABOLISMO:** Plan adaptado para persona de {$basicData['age']} años ({$basicData['anthropometric_data']['bmr_category']})
        12. **COMPOSICIÓN CORPORAL:** Ajustes específicos para su estado: {$basicData['anthropometric_data']['weight_status']}
        
        **ESTRUCTURA JSON OBLIGATORIA PARA {$preferredName}:**
        ```json
        {
          \"nutritionPlan\": {
            \"personalizedMessage\": \"Mensaje personal directo para {$preferredName} usando el estilo {$communicationStyle}, mencionando su objetivo {$basicData['goal']} y considerando su perfil antropométrico específico (edad {$basicData['age']}, BMI {$basicData['anthropometric_data']['bmi']})...\",
            \"anthropometricSummary\": {
              \"clientName\": \"{$preferredName}\",
              \"age\": {$basicData['age']},
              \"sex\": \"{$basicData['sex']}\",
              \"weight\": {$basicData['weight']},
              \"height\": {$basicData['height']},
              \"bmi\": {$basicData['anthropometric_data']['bmi']},
              \"weightStatus\": \"{$basicData['anthropometric_data']['weight_status']}\",
              \"idealWeightRange\": {
                \"min\": {$basicData['anthropometric_data']['ideal_weight_range']['min']},
                \"max\": {$basicData['anthropometric_data']['ideal_weight_range']['max']}
              }
            },
            \"nutritionalSummary\": {
              \"tmb\": {$nutritionalData['tmb']},
              \"get\": {$nutritionalData['get']},
              \"targetCalories\": {$nutritionalData['target_calories']},
              \"goal\": \"{$basicData['goal']}\",
              \"monthlyProgression\": \"Mes 1 de 3 - Ajustes automáticos según progreso\",
              \"activityFactor\": \"{$nutritionalData['activity_factor']} ({$basicData['activity_level']})\",
              \"caloriesPerKg\": " . round($nutritionalData['target_calories'] / $basicData['weight'], 2) . ",
              \"proteinPerKg\": {$macros['protein']['per_kg']},
              \"specialConsiderations\": [
                // Array con consideraciones especiales basadas en su perfil antropométrico
              ]
            },
            \"targetMacros\": {
              \"calories\": {$macros['calories']},
              \"protein\": {$macros['protein']['grams']},
              \"fats\": {$macros['fats']['grams']},
              \"carbohydrates\": {$macros['carbohydrates']['grams']},
              \"detailedBreakdown\": {
                \"protein\": {
                  \"grams\": {$macros['protein']['grams']},
                  \"calories\": {$macros['protein']['calories']},
                  \"percentage\": {$macros['protein']['percentage']},
                  \"perKg\": {$macros['protein']['per_kg']}
                },
                \"fats\": {
                  \"grams\": {$macros['fats']['grams']},
                  \"calories\": {$macros['fats']['calories']},
                  \"percentage\": {$macros['fats']['percentage']},
                  \"perKg\": {$macros['fats']['per_kg']}
                },
                \"carbohydrates\": {
                  \"grams\": {$macros['carbohydrates']['grams']},
                  \"calories\": {$macros['carbohydrates']['calories']},
                  \"percentage\": {$macros['carbohydrates']['percentage']},
                  \"perKg\": {$macros['carbohydrates']['per_kg']}
                }
              }
            },
            \"mealSchedule\": {
              \"breakfast\": \"{$mealTimes['breakfast_time']}\",
              \"lunch\": \"{$mealTimes['lunch_time']}\",
              \"dinner\": \"{$mealTimes['dinner_time']}\"
            },
            \"meals\": {
              // ESTRUCTURA OBLIGATORIA CON GRUPOS DE INTERCAMBIO
              \"Desayuno\": {
                \"Proteínas\": {
                  \"options\": [
                    {\"name\": \"Huevo entero\", \"portion\": \"2 unidades grandes\", \"calories\": 140, \"protein\": 12, \"fats\": 10, \"carbohydrates\": 1},
                    {\"name\": \"Atún en lata\", \"portion\": \"80g escurrido\", \"calories\": 145, \"protein\": 30, \"fats\": 2, \"carbohydrates\": 0},
                    {\"name\": \"Pollo (muslos)\", \"portion\": \"60g (peso en crudo)\", \"calories\": 140, \"protein\": 20, \"fats\": 7, \"carbohydrates\": 0}
                  ]
                },
                \"Carbohidratos\": {
                  \"options\": [
                    {\"name\": \"Avena tradicional\", \"portion\": \"40g (peso en seco)\", \"calories\": 150, \"protein\": 5, \"fats\": 3, \"carbohydrates\": 27},
                    {\"name\": \"Pan integral\", \"portion\": \"2 rebanadas (60g)\", \"calories\": 155, \"protein\": 6, \"fats\": 2, \"carbohydrates\": 28},
                    {\"name\": \"Tortillas de maíz\", \"portion\": \"2 unidades medianas\", \"calories\": 150, \"protein\": 4, \"fats\": 2, \"carbohydrates\": 30}
                  ]
                },
                \"Grasas\": {
                  \"options\": [
                    {\"name\": \"Aceite de oliva\", \"portion\": \"1 cucharada (15ml)\", \"calories\": 135, \"protein\": 0, \"fats\": 15, \"carbohydrates\": 0},
                    {\"name\": \"Aguacate\", \"portion\": \"1/3 unidad mediana (70g)\", \"calories\": 140, \"protein\": 2, \"fats\": 13, \"carbohydrates\": 7}
                  ]
                },
                \"Vegetales\": {
                  \"options\": [
                    {\"name\": \"Ensalada LIBRE\", \"portion\": \"Sin restricción de cantidad\", \"calories\": 25, \"protein\": 2, \"fats\": 0, \"carbohydrates\": 5}
                  ]
                }
              },
              \"Almuerzo\": {
                \"Proteínas\": {
                  \"options\": [
                    {\"name\": \"Pollo (muslos)\", \"portion\": \"120g (peso en crudo)\", \"calories\": 280, \"protein\": 40, \"fats\": 14, \"carbohydrates\": 0},
                    {\"name\": \"Atún en lata\", \"portion\": \"150g escurrido\", \"calories\": 275, \"protein\": 55, \"fats\": 4, \"carbohydrates\": 0},
                    {\"name\": \"Huevos enteros\", \"portion\": \"4 unidades\", \"calories\": 280, \"protein\": 24, \"fats\": 20, \"carbohydrates\": 2}
                  ]
                },
                \"Carbohidratos\": {
                  \"options\": [
                    {\"name\": \"Arroz blanco\", \"portion\": \"80g (peso en crudo)\", \"calories\": 280, \"protein\": 6, \"fats\": 1, \"carbohydrates\": 62},
                    {\"name\": \"Papa cocida\", \"portion\": \"350g (peso cocido)\", \"calories\": 275, \"protein\": 7, \"fats\": 0, \"carbohydrates\": 63},
                    {\"name\": \"Tortillas de maíz\", \"portion\": \"4 unidades medianas\", \"calories\": 280, \"protein\": 8, \"fats\": 4, \"carbohydrates\": 56}
                  ]
                },
                \"Grasas\": {
                  \"options\": [
                    {\"name\": \"Aceite vegetal\", \"portion\": \"1.5 cucharadas (22ml)\", \"calories\": 195, \"protein\": 0, \"fats\": 22, \"carbohydrates\": 0},
                    {\"name\": \"Aguacate\", \"portion\": \"1/2 unidad grande (100g)\", \"calories\": 200, \"protein\": 3, \"fats\": 18, \"carbohydrates\": 10}
                  ]
                },
                \"Vegetales\": {
                  \"options\": [
                    {\"name\": \"Ensalada mixta LIBRE\", \"portion\": \"Sin restricción de cantidad\", \"calories\": 30, \"protein\": 2, \"fats\": 0, \"carbohydrates\": 6},
                    {\"name\": \"Verduras cocidas LIBRES\", \"portion\": \"Sin restricción de cantidad\", \"calories\": 35, \"protein\": 3, \"fats\": 0, \"carbohydrates\": 7}
                  ]
                }
              },
              \"Cena\": {
                \"Proteínas\": {
                  \"options\": [
                    {\"name\": \"Atún en lata\", \"portion\": \"120g escurrido\", \"calories\": 220, \"protein\": 44, \"fats\": 3, \"carbohydrates\": 0},
                    {\"name\": \"Pollo (muslos)\", \"portion\": \"100g (peso en crudo)\", \"calories\": 225, \"protein\": 32, \"fats\": 11, \"carbohydrates\": 0},
                    {\"name\": \"Huevos enteros\", \"portion\": \"3 unidades grandes\", \"calories\": 210, \"protein\": 18, \"fats\": 15, \"carbohydrates\": 2}
                  ]
                },
                \"Carbohidratos\": {
                  \"options\": [
                    {\"name\": \"Frijoles cocidos\", \"portion\": \"150g (peso cocido)\", \"calories\": 180, \"protein\": 12, \"fats\": 1, \"carbohydrates\": 32},
                    {\"name\": \"Arroz blanco\", \"portion\": \"50g (peso en crudo)\", \"calories\": 175, \"protein\": 4, \"fats\": 0, \"carbohydrates\": 39},
                    {\"name\": \"Tortillas de maíz\", \"portion\": \"2 unidades medianas\", \"calories\": 180, \"protein\": 5, \"fats\": 2, \"carbohydrates\": 36}
                  ]
                },
                \"Grasas\": {
                  \"options\": [
                    {\"name\": \"Aceite de cocina\", \"portion\": \"1 cucharada (15ml)\", \"calories\": 135, \"protein\": 0, \"fats\": 15, \"carbohydrates\": 0},
                    {\"name\": \"Aguacate pequeño\", \"portion\": \"1 unidad completa (80g)\", \"calories\": 130, \"protein\": 2, \"fats\": 12, \"carbohydrates\": 6}
                  ]
                },
                \"Vegetales\": {
                  \"options\": [
                    {\"name\": \"Ensalada verde LIBRE\", \"portion\": \"Sin restricción de cantidad\", \"calories\": 20, \"protein\": 2, \"fats\": 0, \"carbohydrates\": 4},
                    {\"name\": \"Verduras salteadas LIBRES\", \"portion\": \"Sin restricción de cantidad\", \"calories\": 25, \"protein\": 2, \"fats\": 0, \"carbohydrates\": 5}
                  ]
                }
              }
            },
            \"personalizedTips\": {
              \"anthropometricGuidance\": \"Consejos específicos basados en su edad ({$basicData['age']}), BMI ({$basicData['anthropometric_data']['bmi']}) y objetivo\",
              \"difficultySupport\": \"Consejos específicos para sus dificultades: {$difficulties}\",
              \"motivationalElements\": \"Elementos que refuerzan sus motivaciones: {$motivations}\",
              \"eatingOutGuidance\": \"Guía para cuando come fuera (frecuencia: {$basicData['preferences']['eats_out']})\",
              \"ageSpecificAdvice\": \"Recomendaciones para personas de {$basicData['age']} años\"
            }
          }
        }
        ```
        
        Genera el plan TOTALMENTE PERSONALIZADO para {$preferredName} en español, considerando específicamente su edad, peso, altura y BMI para crear un plan que sea perfecto para su composición corporal actual y objetivo, usando ingredientes locales de {$basicData['country']} y un tono {$communicationStyle}.
        ";
    }

    private function getDetailedBudgetInstructions($budget, $country): string
    {
        $budgetLevel = strtolower($budget);
        $countryContext = strtolower($country);
        
        if (str_contains($budgetLevel, 'bajo')) {
            $baseInstructions = "**PRESUPUESTO BAJO - ALIMENTOS OBLIGATORIOS:**
            - Proteínas: Huevo entero, Atún en lata, Pollo (muslos/encuentros), Lentejas, Frijoles, Carne molida
            - Carbohidratos: Arroz blanco, Avena tradicional, Papa, Camote, Pasta común, Pan de molde
            - Grasas: Aceite vegetal común, Mantequilla, Aguacate pequeño
            **PROHIBIDO:** Salmón, Lomo de res, Almendras, Proteína en polvo, Quinua, Yogur griego";
            
            // Ajustes por país
            if (str_contains($countryContext, 'perú')) {
                $baseInstructions .= "\n**ESPECÍFICO PERÚ:** Priorizar quinua local, camote, frejoles, pescado bonito, pollo de granja.";
            } elseif (str_contains($countryContext, 'méxico')) {
                $baseInstructions .= "\n**ESPECÍFICO MÉXICO:** Priorizar frijoles negros, tortillas, pollo local, huevos de rancho.";
            }
            
            return $baseInstructions;
        } elseif (str_contains($budgetLevel, 'alto')) {
            $baseInstructions = "**PRESUPUESTO ALTO - ALIMENTOS PREMIUM:**
            - Proteínas: Salmón, Lomo de res, Pechuga de pollo, Proteína en polvo, Yogur griego, Quesos finos
            - Carbohidratos: Quinua, Avena orgánica, Batata, Pan artesanal, Arroz integral
            - Grasas: Aceite de oliva extra virgen, Almendras, Nueces, Aguacate, Aceite de coco";
            
            // Ajustes por país
            if (str_contains($countryContext, 'perú')) {
                $baseInstructions .= "\n**ESPECÍFICO PERÚ:** Incluir superalimentos andinos, quinua roja, palta, pescados frescos del Pacífico.";
            } elseif (str_contains($countryContext, 'méxico')) {
                $baseInstructions .= "\n**ESPECÍFICO MÉXICO:** Incluir aguacate premium, chía, amaranto, pescados del Golfo.";
            }
            
            return $baseInstructions;
        }
        
        return "**PRESUPUESTO MEDIO:** Balance entre calidad y costo, alimentos nutritivos accesibles localmente.";
    }

    private function getDetailedDietaryInstructions($dietaryStyle): string
    {
        $style = strtolower($dietaryStyle);
        
        if ($style === 'vegano') {
            return "**OBLIGATORIO VEGANO:** 
            - Solo alimentos de origen vegetal
            - Proteínas: legumbres, tofu, seitán, quinua, frutos secos, semillas
            - B12 y hierro: considerar suplementación
            - Combinar proteínas para aminoácidos completos";
        } elseif ($style === 'vegetariano') {
            return "**OBLIGATORIO VEGETARIANO:** 
            - Sin carne ni pescado
            - Incluye: huevos, lácteos, legumbres, frutos secos
            - Asegurar hierro y B12 suficientes";
        } elseif (str_contains($style, 'keto')) {
            return "**OBLIGATORIO KETO:** 
            - Máximo 50g carbohidratos netos totales
            - 70% grasas, 25% proteínas, 5% carbohidratos
            - Priorizar: aguacate, aceites, frutos secos, carnes, pescados grasos
            - EVITAR: granos, frutas altas en azúcar, tubérculos";
        }
        
        return "**OMNÍVORO:** Todos los grupos de alimentos permitidos, priorizando variedad y calidad nutricional.";
    }

    private function getCommunicationStyleInstructions($communicationStyle, $preferredName): string
    {
        $style = strtolower($communicationStyle);
        
        if (str_contains($style, 'motivadora')) {
            return "**COMUNICACIÓN MOTIVADORA:** 
            - Usa frases empoderadoras y desafiantes
            - Recuerda sus logros y capacidades
            - Enfócate en el progreso y superación personal
            - Tono enérgico: '¡{$preferredName}, vas a lograr esto!', '¡Tu fuerza te llevará al éxito!'";
        } elseif (str_contains($style, 'cercana')) {
            return "**COMUNICACIÓN CERCANA:** 
            - Tono amigable y comprensivo
            - Usa su nombre frecuentemente
            - Comparte consejos como un amigo
            - Tono cálido: 'Hola {$preferredName}', 'Sabemos que puedes', 'Estamos aquí contigo'";
        } elseif (str_contains($style, 'directa')) {
            return "**COMUNICACIÓN DIRECTA:** 
            - Información clara y concisa
            - Sin rodeos ni frases suaves
            - Datos específicos y acciones concretas
            - Tono directo: '{$preferredName}, esto es lo que necesitas hacer', 'Plan claro y simple'";
        }
        
        return "**COMUNICACIÓN ADAPTATIVA:** Mezcla todos los estilos según el contexto, siendo versátil.";
    }

    private function getCountrySpecificFoods($country, $budget): string
    {
        $countryLower = strtolower($country);
        $budgetLower = strtolower($budget);
        
        $commonFoods = "Alimentos locales y tradicionales de temporada";
        
        if (str_contains($countryLower, 'perú')) {
            if (str_contains($budgetLower, 'bajo')) {
                $commonFoods = "Quinua local, camote, papa nativa, frejoles, bonito, pollo de corral, huevos, plátano, yuca.";
            } else {
                $commonFoods = "Quinua roja/negra, palta, pescados frescos (lenguado, corvina), mariscos, frutas exóticas, chía andina.";
            }
        } elseif (str_contains($countryLower, 'méxico')) {
            if (str_contains($budgetLower, 'bajo')) {
                $commonFoods = "Frijoles negros/bayos, tortillas de maíz, pollo local, huevos, nopales, chiles, jitomate.";
            } else {
                $commonFoods = "Aguacate hass, amaranto, chía, pescados del Golfo, quesos artesanales, cacao mexicano.";
            }
        } elseif (str_contains($countryLower, 'argentina')) {
            if (str_contains($budgetLower, 'bajo')) {
                $commonFoods = "Carne vacuna básica, huevos, papa, arroz, lentejas, yerba mate.";
            } else {
                $commonFoods = "Cortes premium de carne, salmón, quinua, vinos para cocinar, quesos argentinos.";
            }
        }
        
        return "Ingredientes específicos del país: " . $commonFoods;
    }

    /**
     * Generación de recetas ULTRA-PERSONALIZADAS
     */
    private function generatePersonalizedRecipes(array $planData, $profile, $nutritionalData): array
    {
        $mealsToSearch = array_keys($planData['nutritionPlan']['meals'] ?? []);
        if (empty($mealsToSearch)) {
            return $planData;
        }

        foreach ($mealsToSearch as $mealName) {
            if (isset($planData['nutritionPlan']['meals'][$mealName])) {
                $mealComponents = $planData['nutritionPlan']['meals'][$mealName];
                
                // Generar recetas personalizadas para cada comida
                $recipes = $this->generateUltraPersonalizedRecipesForMeal($mealComponents, $profile, $nutritionalData, $mealName);
                
                if (!empty($recipes)) {
                    // MANTENER la estructura de intercambios Y agregar las recetas sugeridas
                    $planData['nutritionPlan']['meals'][$mealName]['suggested_recipes'] = $recipes;
                    $planData['nutritionPlan']['meals'][$mealName]['meal_timing'] = $this->getMealTiming($mealName, $nutritionalData['basic_data']['meal_times'] ?? []);
                    $planData['nutritionPlan']['meals'][$mealName]['personalized_tips'] = $this->getMealSpecificTips($mealName, $profile, $nutritionalData);
                    
                    Log::info(count($recipes) . " recetas ultra-personalizadas generadas para {$mealName}.");
                }
            }
        }
        
        return $planData;
    }

    private function generateUltraPersonalizedRecipesForMeal(array $mealComponents, $profile, $nutritionalData, $mealName): ?array
    {
        // Extraer ingredientes disponibles de la estructura de intercambios
        $proteinOptions = [];
        $carbOptions = [];
        $fatOptions = [];
        
        // Buscar en la estructura de intercambios
        if (isset($mealComponents['Proteínas']['options'])) {
            $proteinOptions = array_map(fn($opt) => $opt['name'], $mealComponents['Proteínas']['options']);
        }
        if (isset($mealComponents['Carbohidratos']['options'])) {
            $carbOptions = array_map(fn($opt) => $opt['name'], $mealComponents['Carbohidratos']['options']);
        }
        if (isset($mealComponents['Grasas']['options'])) {
            $fatOptions = array_map(fn($opt) => $opt['name'], $mealComponents['Grasas']['options']);
        }

        // Si no hay suficientes componentes, usar valores por defecto según presupuesto
        if (empty($proteinOptions)) {
            $budget = strtolower($profile->budget ?? '');
            if (str_contains($budget, 'bajo')) {
                $proteinOptions = ['Huevo entero', 'Pollo muslo', 'Atún en lata'];
            } else {
                $proteinOptions = ['Pechuga de pollo', 'Pescado blanco', 'Huevo entero'];
            }
        }
        
        if (empty($carbOptions)) {
            $carbOptions = ['Arroz blanco', 'Papa', 'Avena'];
        }
        
        if (empty($fatOptions)) {
            $fatOptions = ['Aceite de oliva', 'Aguacate'];
        }

        Log::info("Ingredientes encontrados para {$mealName}", [
            'proteinas' => $proteinOptions,
            'carbohidratos' => $carbOptions,
            'grasas' => $fatOptions
        ]);

        // Continuar con el resto del método...

        // Crear datos específicos para el prompt
        $proteinString = implode(', ', array_unique($proteinOptions));
        $carbString = implode(', ', array_unique($carbOptions));
        $fatString = !empty($fatOptions) ? implode(', ', array_unique($fatOptions)) : 'aceite de oliva, aguacate';
        
        $basicData = $nutritionalData['basic_data'];
        $preferredName = $basicData['preferences']['preferred_name'];
        $country = $basicData['country'];
        $communicationStyle = $basicData['preferences']['communication_style'];
        $dislikedFoods = $basicData['preferences']['disliked_foods'];
        $allergies = $basicData['health_status']['allergies'];
        $dietaryStyle = $basicData['preferences']['dietary_style'];
        $budget = $basicData['preferences']['budget'];
        $sports = !empty($basicData['sports_data']['sports']) ? implode(', ', $basicData['sports_data']['sports']) : 'actividad general';

        // Prompt ultra-personalizado para recetas
        $prompt = "
        Eres un chef personal experto que conoce perfectamente a {$preferredName}. Vas a crear TRES recetas específicamente para su {$mealName}.
        
        **PERFIL COMPLETO DE {$preferredName}:**
        - País: {$country}
        - Objetivo: {$basicData['goal']}
        - Deportes: {$sports}
        - Estilo dietético: {$dietaryStyle}
        - Presupuesto: {$budget}
        - Comunicación preferida: {$communicationStyle}
        - NO le gusta: {$dislikedFoods}
        - Alergias: {$allergies}
        
        **CONTEXTO DE LA COMIDA:**
        - Comida: {$mealName}
        - Horario aproximado: " . $this->getMealTiming($mealName, $basicData['meal_times'] ?? []) . "
        
        **INGREDIENTES ESPECÍFICOS PARA {$preferredName}:**
        - Proteínas disponibles: {$proteinString}
        - Carbohidratos disponibles: {$carbString}
        - Grasas disponibles: {$fatString}
        
        **REGLAS CRÍTICAS PARA {$preferredName}:**
        1. Cada receta debe usar SOLO UN ingrediente de cada categoría (proteína/carbohidrato/grasa)
        2. NUNCA incluir: {$dislikedFoods}
        3. EVITAR COMPLETAMENTE si tiene alergias: {$allergies}
        4. Adaptar al estilo dietético: {$dietaryStyle}
        5. Respetar presupuesto: {$budget}
        6. Usar técnicas culinarias típicas de {$country}
        7. Especificar SIEMPRE si los ingredientes son en peso crudo/seco o cocido
        8. Crear recetas que apoyen su objetivo: {$basicData['goal']}
        
        **ESTRUCTURA JSON OBLIGATORIA:**
        ```json
        {
          \"recipes\": [
            {
              \"name\": \"Nombre atractivo en español, típico de {$country}\",
              \"personalizedNote\": \"Nota personal para {$preferredName} explicando por qué esta receta es perfecta para él/ella\",
              \"instructions\": \"Paso 1: preparación...\\nPaso 2: cocción...\\nPaso 3: presentación...\",
              \"readyInMinutes\": 25,
              \"servings\": 1,
              \"calories\": 520,
              \"protein\": 38,
              \"carbs\": 55,
              \"fats\": 22,
              \"extendedIngredients\": [
                {\"name\": \"pechuga de pollo\", \"original\": \"150g (peso en crudo)\", \"localName\": \"Nombre local en {$country}\"},
                {\"name\": \"arroz blanco\", \"original\": \"80g (peso en crudo)\", \"localName\": \"Arroz local\"},
                {\"name\": \"aceite de oliva\", \"original\": \"1 cucharada (15ml)\", \"localName\": \"Aceite local\"}
              ],
              \"cuisineType\": \"{$country}\",
              \"difficultyLevel\": \"Fácil/Intermedio/Avanzado\",
              \"goalAlignment\": \"Explicar cómo esta receta apoya su objetivo: {$basicData['goal']}\",
              \"sportsSupport\": \"Cómo esta comida apoya su entrenamiento: {$sports}\"
            }
          ]
        }
        ```
        
        Crea las 3 recetas MUY DIFERENTES entre sí, usando ingredientes locales de {$country} y técnicas que {$preferredName} pueda manejar según su perfil.
        Usa un tono {$communicationStyle} en las notas personalizadas.
        ";

        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->timeout(150)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.4,
            ]);

        if ($response->successful()) {
            $data = json_decode($response->json('choices.0.message.content'), true);
            
            if (json_last_error() === JSON_ERROR_NONE && isset($data['recipes']) && is_array($data['recipes'])) {
                $processedRecipes = [];
                foreach ($data['recipes'] as $recipeData) {
                    $recipeData['image'] = null;
                    $recipeData['analyzedInstructions'] = [];
                    $recipeData['personalizedFor'] = $preferredName;
                    $recipeData['mealType'] = $mealName;
                    $processedRecipes[] = $recipeData;
                }
                return $processedRecipes;
            } else {
                Log::warning("La IA no devolvió las recetas personalizadas como se esperaba.", ['response' => $data]);
            }
        }

        Log::error("Fallo al generar recetas ultra-personalizadas", [
            'status' => $response->status(), 
            'body' => $response->body(),
            'meal' => $mealName,
            'user' => $preferredName
        ]);
        return null;
    }

    private function getMealTiming($mealName, $mealTimes): string
    {
        $mealLower = strtolower($mealName);
        
        if (str_contains($mealLower, 'desayuno') && isset($mealTimes['breakfast_time'])) {
            return $mealTimes['breakfast_time'];
        } elseif (str_contains($mealLower, 'almuerzo') && isset($mealTimes['lunch_time'])) {
            return $mealTimes['lunch_time'];
        } elseif (str_contains($mealLower, 'cena') && isset($mealTimes['dinner_time'])) {
            return $mealTimes['dinner_time'];
        }
        
        return 'Horario flexible';
    }

    private function getMealSpecificTips($mealName, $profile, $nutritionalData): array
    {
        $basicData = $nutritionalData['basic_data'];
        $mealLower = strtolower($mealName);
        $goal = $basicData['goal'];
        $sports = $basicData['sports_data']['sports'] ?? [];
        
        $tips = [];
        
        if (str_contains($mealLower, 'desayuno')) {
            $tips[] = "Desayuno ideal para comenzar el día con energía";
            if (str_contains(strtolower($goal), 'bajar grasa')) {
                $tips[] = "Rico en proteínas para mantener saciedad toda la mañana";
            }
            if (!empty($sports)) {
                $tips[] = "Carbohidratos para energía pre-entrenamiento si entrenas en la mañana";
            }
        } elseif (str_contains($mealLower, 'almuerzo')) {
            $tips[] = "Comida principal para sostener la tarde";
            if (!empty($sports)) {
                $tips[] = "Ideal como comida post-entreno si entrenas en la mañana";
            }
        } elseif (str_contains($mealLower, 'cena')) {
            $tips[] = "Cena ligera pero nutritiva para buena recuperación nocturna";
            if (str_contains(strtolower($goal), 'aumentar músculo')) {
                $tips[] = "Rica en proteínas para síntesis muscular durante el sueño";
            }
        }
        
        return $tips;
    }

    /**
     * Plan de respaldo personalizado
     */
    private function getPersonalizedBackupPlan($profile, $nutritionalData, $userName): array
    {
        $basicData = $nutritionalData['basic_data'];
        $macros = $nutritionalData['macros'];
        $preferredName = $userName; // ✅ CAMBIO: Usar directamente el userName

        return [
            'nutritionPlan' => [
                'personalizedMessage' => "¡Hola {$preferredName}! Este es tu plan de respaldo mientras generamos tu plan personalizado completo. Está calculado específicamente para tu objetivo: {$basicData['goal']}.",
                'nutritionalSummary' => [
                    'clientName' => $preferredName,
                    'tmb' => $nutritionalData['tmb'],
                    'get' => $nutritionalData['get'],
                    'targetCalories' => $nutritionalData['target_calories'],
                    'goal' => $basicData['goal'],
                    'monthlyProgression' => "Plan temporal - se actualizará con tu perfil completo",
                    'activityFactor' => "{$nutritionalData['activity_factor']} ({$basicData['activity_level']})"
                ],
                'targetMacros' => [
                    'calories' => $macros['calories'],
                    'protein' => $macros['protein']['grams'],
                    'fats' => $macros['fats']['grams'],
                    'carbohydrates' => $macros['carbohydrates']['grams']
                ],
                'mealSchedule' => [
                    'breakfast' => $basicData['meal_times']['breakfast_time'] ?? '07:00',
                    'lunch' => $basicData['meal_times']['lunch_time'] ?? '13:00',
                    'dinner' => $basicData['meal_times']['dinner_time'] ?? '20:00'
                ],
                'meals' => [
                    'Desayuno' => [
                        'Proteínas' => [
                            'options' => [
                                ['name' => 'Huevo entero', 'portion' => '3 unidades grandes', 'calories' => 210, 'protein' => 18, 'fats' => 15, 'carbohydrates' => 2],
                                ['name' => 'Yogur natural', 'portion' => '200g', 'calories' => 215, 'protein' => 20, 'fats' => 12, 'carbohydrates' => 8]
                            ]
                        ],
                        'Carbohidratos' => [
                            'options' => [
                                ['name' => 'Avena tradicional', 'portion' => '60g (peso en seco)', 'calories' => 220, 'protein' => 8, 'fats' => 4, 'carbohydrates' => 40],
                                ['name' => 'Pan integral', 'portion' => '3 rebanadas (90g)', 'calories' => 225, 'protein' => 9, 'fats' => 3, 'carbohydrates' => 42]
                            ]
                        ]
                    ],
                    'Almuerzo' => [
                        'Proteínas' => [
                            'options' => [
                                ['name' => 'Pechuga de pollo', 'portion' => '180g (peso en crudo)', 'calories' => 300, 'protein' => 55, 'fats' => 8, 'carbohydrates' => 0],
                                ['name' => 'Pescado blanco', 'portion' => '200g (peso en crudo)', 'calories' => 310, 'protein' => 52, 'fats' => 12, 'carbohydrates' => 0]
                            ]
                        ],
                        'Carbohidratos' => [
                            'options' => [
                                ['name' => 'Arroz blanco', 'portion' => '100g (peso en crudo)', 'calories' => 350, 'protein' => 8, 'fats' => 1, 'carbohydrates' => 75],
                                ['name' => 'Papa cocida', 'portion' => '400g (peso cocido)', 'calories' => 340, 'protein' => 9, 'fats' => 0, 'carbohydrates' => 77]
                            ]
                        ]
                    ],
                    'Cena' => [
                        'Proteínas' => [
                            'options' => [
                                ['name' => 'Pescado a la plancha', 'portion' => '200g (peso en crudo)', 'calories' => 280, 'protein' => 50, 'fats' => 9, 'carbohydrates' => 0],
                                ['name' => 'Pechuga de pollo', 'portion' => '150g (peso en crudo)', 'calories' => 290, 'protein' => 48, 'fats' => 8, 'carbohydrates' => 0]
                            ]
                        ],
                        'Vegetales' => [
                            'options' => [
                                ['name' => 'Ensalada LIBRE', 'portion' => 'Sin restricción', 'calories' => 25, 'protein' => 2, 'fats' => 0, 'carbohydrates' => 5]
                            ]
                        ]
                    ]
                ],
                'personalizedTips' => [
                    'message' => "Este es un plan temporal para {$preferredName}. Tu plan personalizado completo estará listo pronto con tus preferencias específicas.",
                    'nextSteps' => "Revisaremos tu progreso y ajustaremos según tu respuesta al plan."
                ]
            ]
        ];
    }

    /**
     * MÉTODOS PARA FUTURAS ACTUALIZACIONES MENSUALES CON PERSONALIZACIÓN
     */
    public function updatePersonalizedMonthlyPlan($mealPlanId, $currentMonth, $hasBeenConsistent = true, $progressData = null): array
    {
        $mealPlan = MealPlan::find($mealPlanId);
        if (!$mealPlan) {
            throw new \Exception("Plan no encontrado");
        }

        $nutritionalData = $mealPlan->nutritional_data;
        $personalizationData = $mealPlan->personalization_data;
        $profile = $mealPlan->user->profile;
        
        $preferredName = $personalizationData['personal_data']['preferred_name'] ?? 'Usuario';
        $communicationStyle = $personalizationData['emotional_data']['communication_style'] ?? 'cercana';
        
        if (!$hasBeenConsistent) {
            Log::info("Usuario {$preferredName} no fue constante, manteniendo el plan actual", ['mealPlanId' => $mealPlanId]);
            
            // Mensaje personalizado según su estilo de comunicación
            $motivationalMessage = $this->generateMotivationalMessage($communicationStyle, $preferredName, 'consistency_issue');
            
            return array_merge($nutritionalData, [
                'motivational_message' => $motivationalMessage,
                'consistency_support' => $this->generateConsistencySupport($personalizationData['emotional_data']['diet_difficulties'] ?? [])
            ]);
        }

        $originalGet = $nutritionalData['get'];
        $goal = $nutritionalData['basic_data']['goal'];
        
        // Ajuste progresivo personalizado según el mes y objetivo
        $newTargetCalories = $this->calculateProgressiveCalories($originalGet, $goal, $currentMonth);
        
        // Recalcular macros con las nuevas calorías
        $newMacros = $this->calculatePersonalizedMacronutrients(
            $newTargetCalories, 
            $nutritionalData['basic_data']['weight'], 
            $goal,
            $nutritionalData['basic_data']['preferences']['dietary_style']
        );

        // Actualizar datos nutricionales
        $nutritionalData['target_calories'] = round($newTargetCalories);
        $nutritionalData['macros'] = $newMacros;
        $nutritionalData['month'] = $currentMonth;
        $nutritionalData['last_updated'] = now();
        
        // Mensaje personalizado de progreso
        $progressMessage = $this->generateProgressMessage($communicationStyle, $preferredName, $currentMonth, $progressData);
        $nutritionalData['progress_message'] = $progressMessage;

        // Actualizar el plan en la base de datos
        $mealPlan->update(['nutritional_data' => $nutritionalData]);

        Log::info("Plan personalizado actualizado para {$preferredName} - Mes {$currentMonth}", [
            'mealPlanId' => $mealPlanId,
            'newCalories' => $newTargetCalories,
            'previousCalories' => $nutritionalData['target_calories'] ?? 'N/A'
        ]);

        return $nutritionalData;
    }

    private function calculateProgressiveCalories($get, $goal, $month): float
    {
        $goalLower = strtolower($goal);
        
        if (str_contains($goalLower, 'bajar grasa')) {
            // Progresión del déficit: -20%, -25%, -30%
            switch ($month) {
                case 2: return $get * 0.75; // -25%
                case 3: return $get * 0.70; // -30%
                default: return $get * 0.80; // -20%
            }
        } elseif (str_contains($goalLower, 'aumentar músculo')) {
            // Progresión del superávit: +10%, +15%, +20%
            switch ($month) {
                case 2: return $get * 1.15; // +15%
                case 3: return $get * 1.20; // +20%
                default: return $get * 1.10; // +10%
            }
        }
        
        return $get; // Mantenimiento
    }

    private function generateMotivationalMessage($communicationStyle, $preferredName, $context): string
    {
        $style = strtolower($communicationStyle);
        
        if (str_contains($style, 'motivadora')) {
            switch ($context) {
                case 'consistency_issue':
                    return "¡{$preferredName}! Todos tenemos semanas difíciles, pero los campeones se levantan y siguen adelante. ¡Tu fuerza interior es más grande que cualquier obstáculo!";
                case 'progress':
                    return "¡{$preferredName}, eres imparable! Cada día te acercas más a tu mejor versión. ¡Sigue así, guerrero!";
            }
        } elseif (str_contains($style, 'cercana')) {
            switch ($context) {
                case 'consistency_issue':
                    return "Hola {$preferredName}, no te preocupes, todos pasamos por momentos así. Lo importante es que estás aquí y quieres seguir adelante. Estamos contigo en este proceso.";
                case 'progress':
                    return "¡Qué orgullo, {$preferredName}! Me da mucha alegría ver tu progreso. Sigues siendo constante y eso se nota. ¡Vamos por más!";
            }
        } elseif (str_contains($style, 'directa')) {
            switch ($context) {
                case 'consistency_issue':
                    return "{$preferredName}, la consistencia es clave. Identifica qué te está frenando y ajusta. El plan sigue igual, ahora toca ejecutar.";
                case 'progress':
                    return "{$preferredName}, buen progreso. Los números muestran que el plan funciona. Continuamos con los ajustes programados.";
            }
        }
        
        return "Hola {$preferredName}, seguimos trabajando juntos en tu objetivo. Cada paso cuenta.";
    }

    private function generateProgressMessage($communicationStyle, $preferredName, $month, $progressData): string
    {
        $baseMessage = $this->generateMotivationalMessage($communicationStyle, $preferredName, 'progress');
        
        $monthText = $month == 2 ? 'segundo' : ($month == 3 ? 'tercer' : 'primer');
        
        $additionalInfo = " Estamos en tu {$monthText} mes y los ajustes están diseñados para optimizar tus resultados.";
        
        if ($progressData) {
            $additionalInfo .= " Basándome en tu progreso actual, este ajuste es perfecto para ti.";
        }
        
        return $baseMessage . $additionalInfo;
    }

    private function generateConsistencySupport($difficulties): array
    {
        $support = [];
        
        foreach ($difficulties as $difficulty) {
            $difficultyLower = strtolower($difficulty);
            
            if (str_contains($difficultyLower, 'constante')) {
                $support[] = "Recordatorios diarios y tracking simplificado para mantener el hábito";
            } elseif (str_contains($difficultyLower, 'no tengo lo del plan')) {
                $support[] = "Lista de intercambios equivalentes y opciones de emergencia";
            } elseif (str_contains($difficultyLower, 'fuera de casa')) {
                $support[] = "Guía específica para restaurantes y comida fuera del hogar";
            } elseif (str_contains($difficultyLower, 'antojos')) {
                $support[] = "Estrategias para manejo de antojos y snacks saludables";
            } elseif (str_contains($difficultyLower, 'preparar')) {
                $support[] = "Recetas súper rápidas y meal prep simplificado";
            }
        }
        
        return $support;
    }

    /**
     * MÉTODO PARA VERIFICAR PROGRESO PERSONALIZADO Y AJUSTAR
     */
    public function checkPersonalizedProgressAndAdjust($mealPlanId, $progressData = []): bool
    {
        $mealPlan = MealPlan::find($mealPlanId);
        $nutritionalData = $mealPlan->nutritional_data;
        $personalizationData = $mealPlan->personalization_data;
        
        $preferredName = $personalizationData['personal_data']['preferred_name'] ?? 'Usuario';
        $goal = strtolower($nutritionalData['basic_data']['goal']);
        $communicationStyle = $personalizationData['emotional_data']['communication_style'] ?? 'cercana';
        
        $needsAdjustment = false;
        $adjustmentReason = '';
        
        // Verificar progreso según objetivo
        if (str_contains($goal, 'bajar grasa')) {
            $weightProgress = $progressData['weight_change'] ?? 0;
            $weeksWithoutProgress = $progressData['weeks_stagnant'] ?? 0;
            
            if ($weeksWithoutProgress >= 2 && $weightProgress >= 0) {
                $needsAdjustment = true;
                $adjustmentReason = 'Sin pérdida de peso por 2+ semanas';
            }
        } elseif (str_contains($goal, 'aumentar músculo')) {
            $muscleProgress = $progressData['muscle_gain'] ?? 0;
            $weeksWithoutProgress = $progressData['weeks_stagnant'] ?? 0;
            
            if ($weeksWithoutProgress >= 3 && $muscleProgress <= 0) {
                $needsAdjustment = true;
                $adjustmentReason = 'Sin ganancia muscular por 3+ semanas';
            }
        }
        
        if ($needsAdjustment) {
            $currentCalories = $nutritionalData['target_calories'];
            $adjustmentPercentage = str_contains($goal, 'bajar grasa') ? 0.95 : 1.05; // -5% o +5%
            $newCalories = $currentCalories * $adjustmentPercentage;
            
            // Recalcular macros
            $newMacros = $this->calculatePersonalizedMacronutrients(
                $newCalories,
                $nutritionalData['basic_data']['weight'],
                $nutritionalData['basic_data']['goal'],
                $nutritionalData['basic_data']['preferences']['dietary_style']
            );
            
            // Actualizar datos
            $nutritionalData['target_calories'] = round($newCalories);
            $nutritionalData['macros'] = $newMacros;
            $nutritionalData['adjustment_reason'] = $adjustmentReason;
            $nutritionalData['last_adjusted'] = now();
            
            // Mensaje personalizado de ajuste
            $adjustmentMessage = $this->generateAdjustmentMessage($communicationStyle, $preferredName, $adjustmentReason, $goal);
            $nutritionalData['adjustment_message'] = $adjustmentMessage;
            
            $mealPlan->update(['nutritional_data' => $nutritionalData]);
            
            Log::info("Plan personalizado ajustado para {$preferredName}", [
                'mealPlanId' => $mealPlanId,
                'previousCalories' => $currentCalories,
                'newCalories' => $newCalories,
                'reason' => $adjustmentReason
            ]);
            
            return true;
        }
        
        return false;
    }

    private function generateAdjustmentMessage($communicationStyle, $preferredName, $reason, $goal): string
    {
        $style = strtolower($communicationStyle);
        
        if (str_contains($style, 'motivadora')) {
            if (str_contains($reason, 'sin pérdida')) {
                return "¡{$preferredName}! Tu cuerpo se está adaptando, eso significa que está respondiendo al plan. Ahora vamos a darle el empujón extra que necesita. ¡Tu determinación va a romper esta meseta!";
            } else {
                return "¡{$preferredName}! Es hora de intensificar para alcanzar esa ganancia muscular que buscas. Tu cuerpo está listo para el siguiente nivel. ¡Vamos a por ello!";
            }
        } elseif (str_contains($style, 'cercana')) {
            if (str_contains($reason, 'sin pérdida')) {
                return "Hola {$preferredName}, he notado que tu progreso se ha estabilizado. Es completamente normal, así que he ajustado tu plan para que sigas avanzando. Estoy aquí para acompañarte en cada paso.";
            } else {
                return "Hola {$preferredName}, vamos a darle un impulso a tu plan para optimizar esa ganancia muscular. Cada ajuste nos acerca más a tu objetivo.";
            }
        } elseif (str_contains($style, 'directa')) {
            if (str_contains($reason, 'sin pérdida')) {
                return "{$preferredName}, meseta identificada. Calorías ajustadas para reactivar la pérdida de grasa. Continúa con la ejecución del plan.";
            } else {
                return "{$preferredName}, ajuste realizado para optimizar ganancia muscular. Nuevos macros calculados para mejor respuesta anabólica.";
            }
        }
        
        return "{$preferredName}, he ajustado tu plan basándome en tu progreso actual para optimizar tus resultados.";
    }

    /**
     * MÉTODO PARA GENERAR REPORTE PERSONALIZADO DE PROGRESO
     */
    public function generatePersonalizedProgressReport($mealPlanId, $timeframe = 'monthly'): array
    {
        $mealPlan = MealPlan::find($mealPlanId);
        $nutritionalData = $mealPlan->nutritional_data;
        $personalizationData = $mealPlan->personalization_data;
        
        $preferredName = $personalizationData['personal_data']['preferred_name'] ?? 'Usuario';
        $goal = $nutritionalData['basic_data']['goal'];
        $communicationStyle = $personalizationData['emotional_data']['communication_style'] ?? 'cercana';
        $currentMonth = $nutritionalData['month'] ?? 1;
        
        $report = [
            'client_name' => $preferredName,
            'goal' => $goal,
            'current_month' => $currentMonth,
            'nutritional_summary' => [
                'tmb' => $nutritionalData['tmb'],
                'get' => $nutritionalData['get'],
                'current_calories' => $nutritionalData['target_calories'],
                'macros' => $nutritionalData['macros']
            ],
            'personalized_insights' => [],
            'next_steps' => [],
            'motivational_message' => '',
            'generated_at' => now()
        ];
        
        // Insights personalizados según el perfil
        $motivations = $personalizationData['emotional_data']['diet_motivations'] ?? [];
        $difficulties = $personalizationData['emotional_data']['diet_difficulties'] ?? [];
        
        foreach ($motivations as $motivation) {
            if (str_contains(strtolower($motivation), 'resultados rápidos')) {
                $report['personalized_insights'][] = "Tu motivación por ver resultados rápidos se está canalizando perfectamente en este plan estructurado.";
            } elseif (str_contains(strtolower($motivation), 'sentirme mejor')) {
                $report['personalized_insights'][] = "El enfoque nutricional está optimizado para mejorar tu energía y digestión diarias.";
            }
        }
        
        foreach ($difficulties as $difficulty) {
            if (str_contains(strtolower($difficulty), 'constante')) {
                $report['next_steps'][] = "Implementar recordatorios automáticos y tracking simplificado";
            } elseif (str_contains(strtolower($difficulty), 'fuera de casa')) {
                $report['next_steps'][] = "Expandir la guía de opciones para comer fuera del hogar";
            }
        }
        
        // Mensaje motivacional personalizado
        $report['motivational_message'] = $this->generateMotivationalMessage(
            $communicationStyle, 
            $preferredName, 
            'progress_report'
        );
        
        return $report;
    }

    /**
     * MÉTODO PARA EXPORTAR PLAN PERSONALIZADO COMPLETO
     */
    public function exportPersonalizedPlan($mealPlanId): array
    {
        $mealPlan = MealPlan::find($mealPlanId);
        
        return [
            'plan_data' => $mealPlan->plan_data,
            'nutritional_data' => $mealPlan->nutritional_data,
            'personalization_data' => $mealPlan->personalization_data,
            'export_date' => now(),
            'plan_version' => 'ultra_personalized_v1.0'
        ];
    }
}