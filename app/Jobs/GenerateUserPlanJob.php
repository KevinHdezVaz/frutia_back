<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\MealPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Jobs\EnrichPlanWithPricesJob;
use App\Services\NutritionalCalculator;
use Illuminate\Support\Facades\Cache;
use App\Jobs\GenerateRecipeImagesJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class GenerateUserPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;
    protected $locale;  // ← AGREGAR ESTA LÍNEA (la propiedad faltante)

    public $timeout = 400;
    public $tries = 2;
    


  private function normalizeForComparison(string $text): string
{
    $text = trim(strtolower($text));
    $unwanted = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u',
        'ñ'=>'n','Ñ'=>'n','ü'=>'u','Ü'=>'u'
    ];
    $text = strtr($text, $unwanted);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = str_replace(['-', '_', '/', '  '], ' ', $text);
    return trim($text);
}

    private function isSimilar(string $str1, string $str2, int $levenshteinMax = 4, float $similarPercent = 0.70): bool
{
    $norm1 = $this->normalizeForComparison($str1);
    $norm2 = $this->normalizeForComparison($str2);

    if (str_contains($norm1, $norm2) || str_contains($norm2, $norm1)) return true;

    similar_text($norm1, $norm2, $percent);
    if ($percent > $similarPercent * 100) return true;

    if (levenshtein($norm1, $norm2) <= $levenshteinMax) return true;

    return false;
}

private function isDislikedOrAllergic(string $foodName, array $dislikedNorm, array $allergiesNorm): bool
{
    $foodNorm = $this->normalizeForComparison($foodName);

    $forbidden = array_merge($dislikedNorm, $allergiesNorm);
    $forbidden = array_filter($forbidden);

    foreach ($forbidden as $item) {
        if (empty($item)) continue;

        if ($this->isSimilar($foodNorm, $item)) {
            Log::info("Filtrado robusto", [
                'food' => $foodName,
                'match' => $item,
                'type' => in_array($item, $allergiesNorm) ? 'alergia' : 'disliked'
            ]);
            return true;
        }
    }
    return false;
}

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
                'desayuno' => ['Claras + Huevo entero', 'Proteína whey', 'Yogurt griego alto en proteínas', 'Caseína'],  // ⭐ CAMBIADO
                'almuerzo_cena' => ['Pescado blanco', 'Pechuga de pollo', 'Pechuga de pavo', 'Carne de res magra', 'Salmón fresco'],
                'snacks' => ['Yogurt griego alto en proteínas', 'Proteína whey', 'Caseína']
            ]
        ],
        'grasas' => [
            'bajo' => ['Aceite de oliva', 'Maní', 'Queso bajo en grasa', 'Mantequilla de maní casera', 'Semillas de ajonjolí', 'Aceitunas'],

            'alto' => ['Aceite de oliva extra virgen', 'Aceite de palta', 'Palta', 'Almendras', 'Nueces', 'Pistachos', 'Pecanas', 'Semillas de chía orgánicas', 'Linaza orgánica', 'Mantequilla de maní', 'Miel', 'Chocolate negro 70%']
        ]
    ];


    

    // -------------------------------------------------------------------------
// 1. Agrega este mapeo completo al inicio de la clase (después de las constantes)
// -------------------------------------------------------------------------

/**
 * Mapeo completo: cualquier variación que llegue del front (en español)
 * se convierte al nombre canónico interno usado en FOOD_PREFERENCES y lógica
 */private $foodCanonicalMapping = [
    // Tortillas
    'tortilla de maíz' => 'Tortilla de maíz',
    'tortillas de maíz' => 'Tortilla de maíz',
    'tortilla' => 'Tortilla de maíz',
    'tortillas' => 'Tortilla de maíz',
    'tortilla maíz' => 'Tortilla de maíz',
    'tortillita' => 'Tortilla de maíz',

    // Quinua
    'quinua' => 'Quinua',
    'quinoa' => 'Quinua',
    'quinua peruana' => 'Quinua',
    'quinoa peruana' => 'Quinua',

    // Proteínas
    'huevo entero' => 'Huevo entero',
    'huevos enteros' => 'Huevo entero',
    'claras + huevo entero' => 'Claras + Huevo Entero',
    'claras + huevo' => 'Claras + Huevo Entero',
    'claras huevo' => 'Claras + Huevo Entero',
    'atún en lata' => 'Atún en lata',
    'atun en lata' => 'Atún en lata',
    'atún' => 'Atún en lata',
    'muslo de pollo' => 'Pollo muslo',
    'pollo muslo' => 'Pollo muslo',
    'carne molida' => 'Carne molida',
    'yogur griego' => 'Yogurt griego',
    'yogurt griego' => 'Yogurt griego',
    'yogurt griego alto en proteínas' => 'Yogurt griego alto en proteínas',
    'yogur griego alto en proteínas' => 'Yogurt griego alto en proteínas',
    'proteína whey' => 'Proteína whey',
    'proteina whey' => 'Proteína whey',
    'whey' => 'Proteína whey',
    'caseína' => 'Caseína',
    'caseina' => 'Caseína',
    'carne de res magra' => 'Carne de res magra',
    'carne magra' => 'Carne de res magra',
    'carne magra de res' => 'Carne de res magra',
    'pescado blanco' => 'Pescado blanco',
    'pechuga de pollo' => 'Pechuga de pollo',
    'pollo pechuga' => 'Pechuga de pollo',
    'pechuga pollo' => 'Pechuga de pollo',
    'pollo' => 'Pechuga de pollo',
    'salmón fresco' => 'Salmón fresco',
    'salmon fresco' => 'Salmón fresco',
    'salmón' => 'Salmón fresco',
    'pechuga de pavo' => 'Pechuga de pavo',
    'yogur natural' => 'Yogur natural',
    'proteína vegetal en polvo' => 'Proteína en polvo',
    'queso fresco' => 'Queso fresco',
    'queso cottage' => 'Queso cottage',
    'queso panela' => 'Queso panela',
    'ricotta' => 'Ricotta',
    'muslo de pollo con piel' => 'Pollo muslo con piel',
    'carne molida 80/20' => 'Carne molida 80/20',
    'ribeye' => 'Ribeye',
    'pechuga de pato' => 'Pechuga de pato',
    'queso añejo' => 'Queso añejo',
    'pechuga o muslo de pollo' => 'Pechuga de pollo',

    // Carbohidratos
    'arroz blanco' => 'Arroz blanco',
    'arroz' => 'Arroz blanco',
    'papa' => 'Papa',
    'papas' => 'Papa',
    'avena tradicional' => 'Avena tradicional',
    'avena' => 'Avena tradicional',
    'tortillas de maíz' => 'Tortilla de maíz',
    'fideos básicos' => 'Fideo',
    'fideos' => 'Fideo',
    'frijoles' => 'Frijoles',
    'camote' => 'Camote',
    'galletas de arroz' => 'Galletas de arroz',
    'crema de arroz' => 'Crema de arroz',
    'quinua' => 'Quinua',
    'avena orgánica' => 'Avena orgánica',
    'pan integral artesanal' => 'Pan integral artesanal',
    'pan integral' => 'Pan integral artesanal',

    // Grasas
    'aceite de oliva' => 'Aceite de oliva',
    'maní / mantequilla de maní' => 'Mantequilla de maní',
    'aguacate pequeño' => 'Aguacate',
    'semillas de sésamo' => 'Semillas de ajonjolí',
    'aceite de oliva extra virgen' => 'Aceite de oliva extra virgen',
    'aceite de aguacate' => 'Aceite de palta',
    'almendras' => 'Almendras',
    'nueces' => 'Nueces',
    'aguacate hass' => 'Aguacate hass',
    'chía/linaza orgánica' => 'Semillas de chía orgánicas',
    'nueces premium' => 'Nueces',
    'manteca' => 'Manteca de cerdo',
    'mantequilla' => 'Mantequilla',
    'aguacate' => 'Aguacate',
    'aceite mct' => 'Aceite MCT',
    'mantequilla ghee' => 'Mantequilla ghee',
    'aceitunas' => 'Aceitunas',
    'aguacate / aguacate hass' => 'Aguacate hass',
    'miel' => 'Miel',
    'chocolate 70%' => 'Chocolate negro 70%',

    // Frutas
    'fresas' => 'Fresas',
    'arándanos' => 'Arándanos',
    'moras' => 'Moras',
    'plátano' => 'Plátano',
    'manzana' => 'Manzana',
    'mango' => 'Mango',
    'sandía' => 'Sandía',
    'pera' => 'Pera',

    // Vegetales (agregados del front)
    'brócoli' => 'Brócoli',
    'coliflor' => 'Coliflor',
    'espinacas' => 'Espinacas',
    'lechuga' => 'Lechuga',
    'calabacín' => 'Calabacín',

    // Otras (vegan/vegetarian)
    'tofu' => 'Tofu',
    'tempeh' => 'Tempeh',
    'seitán' => 'Seitán',
    'lentejas' => 'Lentejas',
    'garbanzos' => 'Garbanzos',
 
];
 

    public function __construct($userId, $locale)
    {
        $this->userId = $userId;
        $this->locale = $locale;
    }

    public function handle()
    {
        Log::info('Iniciando GenerateUserPlanJob PERSONALIZADO', ['userId' => $this->userId]);

        $user = User::with('profile')->find($this->userId);
        if (!$user || !$user->profile) {
            Log::error('Usuario o perfil no encontrado.', ['userId' => $this->userId]);
            return;
        }

        $userName = $user->name;
        Log::info('Nombre del usuario obtenido', ['userId' => $this->userId, 'name' => $userName]);
 $foodPreferences = [
        'proteins' => $user->profile->favorite_proteins ?? [],
        'carbs' => $user->profile->favorite_carbs ?? [],
        'fats' => $user->profile->favorite_fats ?? [],
        'fruits' => $user->profile->favorite_fruits ?? [],
    ];

    Log::info('Preferencias de alimentos extraídas', [
        'userId' => $this->userId,
        'preferences' => $foodPreferences
    ]);



        try {
            // PASO 1: Calcular macros siguiendo la metodología del PDF
     Log::info('Paso 1: Calculando TMB, GET y macros objetivo con perfil completo.', ['userId' => $user->id]);
        $nutritionalData = $this->calculateCompleteNutritionalPlan($user->profile, $userName);

        // ⭐ AGREGAR PREFERENCIAS A nutritionalData
        $nutritionalData['food_preferences'] = $foodPreferences;

        $personalizationData = $this->extractPersonalizationData($user->profile, $userName);

        // PASO 2: Generar plan con validación obligatoria
        Log::info('Paso 2: Generando plan nutricional ULTRA-PERSONALIZADO con validación.', ['userId' => $user->id]);
        $planData = $this->generateAndValidatePlan($user->profile, $nutritionalData, $userName);


            // PASO 3: Generar recetas si tiene suscripción activa
            if ($this->userHasActiveSubscription($user)) {
                Log::info('Paso 3: Generando recetas ultra-específicas - Usuario con suscripción activa.', ['userId' => $user->id]);
                $planWithRecipes = $this->generatePersonalizedRecipes($planData, $user->profile, $nutritionalData);
            } else {
                Log::info('Paso 3: Omitiendo generación de recetas - Usuario en periodo de prueba.', ['userId' => $user->id]);
                $planWithRecipes = $this->addTrialMessage($planData, $userName);
            }

            // PASO 4: Guardado del plan completo con datos de validación
            Log::info('Almacenando plan ultra-personalizado en la base de datos.', ['userId' => $user->id]);
            MealPlan::where('user_id', $user->id)->update(['is_active' => false]);

            $mealPlan = MealPlan::create([
                'user_id' => $user->id,
                'plan_data' => $planWithRecipes,
                'nutritional_data' => $nutritionalData,
                'personalization_data' => $personalizationData,
                'validation_data' => $planWithRecipes['validation_data'] ?? null,
                'generation_method' => $planWithRecipes['generation_method'] ?? 'ai',
                'is_active' => true,
            ]);

            // PASO 5: Despachar jobs de enriquecimiento
            Log::info('Despachando jobs de enriquecimiento.', ['mealPlanId' => $mealPlan->id]);
            EnrichPlanWithPricesJob::dispatch($mealPlan->id);
        //    GenerateRecipeImagesJob::dispatch($mealPlan->id)->onQueue('images');

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


    private function generateAndValidatePlan($profile, $nutritionalData, $userName): array
{
    $maxAttempts = 2; // ✅ Reducido para híbrido optimizado
    $attempt = 0;

    while ($attempt < $maxAttempts) {
        $attempt++;
        Log::info("Intento #{$attempt} de generar plan válido", ['userId' => $profile->user_id]);

        // Generar plan con IA
        $planData = $this->generateUltraPersonalizedNutritionalPlan($profile, $nutritionalData, $userName, $attempt);

        if ($planData === null) {
            Log::warning("La IA no generó un plan válido en intento #{$attempt}", ['userId' => $profile->user_id]);
            continue;
        }

        // Validar el plan generado
        $validation = $this->validateGeneratedPlan($planData, $nutritionalData);

        if ($validation['is_valid']) {
            Log::info('✅ Plan con IA validado exitosamente', [
                'userId' => $profile->user_id,
                'attempt' => $attempt,
                'total_macros' => $validation['total_macros']
            ]);

            $planData['validation_data'] = $validation;
            $planData['generation_method'] = 'ai_validated';

            // 🔥 FILTRAR OPCIONES DE IA SEGÚN PREFERENCIAS Y ALERGIAS
            $dislikedFoods = $profile->disliked_foods ?? '';
            $allergies = $profile->allergies ?? '';

            if (isset($planData['nutritionPlan']['meals'])) {
                // Filtrar alimentos que NO le gustan
                if (!empty($dislikedFoods)) {
                    Log::info("Aplicando filtro de preferencias al plan de IA", [
                        'user_id' => $profile->user_id,
                        'disliked_foods' => $dislikedFoods
                    ]);

                    foreach ($planData['nutritionPlan']['meals'] as $mealName => &$mealData) {
                        $mealData = $this->filterOptionsByPreferences($mealData, $dislikedFoods);
                    }
                }

                // 🚨 FILTRAR ALERGIAS (MÁS CRÍTICO)
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

    // Si ambos intentos fallan, usar plan determinístico
    Log::info('🔄 Usando plan determinístico optimizado (backup garantizado)', ['userId' => $profile->user_id]);
    return $this->generateDeterministicPlan($nutritionalData, $profile, $userName);
}
 

private function generateDeterministicPlan($nutritionalData, $profile, $userName): array
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
        // DISTRIBUCIÓN BASE
        $mealDistribution = [
            'Desayuno' => 0.30,
            'Almuerzo' => 0.40,
            'Cena' => 0.20
        ];
        if ($preferredSnackTime === 'Snack AM') {
            $mealDistribution['Snack AM'] = 0.10;
            $personalizedMessage = "Hola {$userName}, tu plan incluye 3 comidas principales y un snack en la media mañana.";
        } elseif ($preferredSnackTime === 'Snack PM') {
            $mealDistribution['Snack PM'] = 0.10;
            $personalizedMessage = "Hola {$userName}, tu plan incluye 3 comidas principales y un snack en la media tarde.";
        } else {
            $mealDistribution['Snack AM'] = 0.10;
            $personalizedMessage = "Hola {$userName}, tu plan incluye 3 comidas principales y un snack en la media mañana.";
            Log::warning("Preferencia de snack no válida, usando Snack AM por defecto", ['received' => $preferredSnackTime, 'user_id' => $profile->user_id]);
        }
        $budget = strtolower($nutritionalData['basic_data']['preferences']['budget'] ?? '');
        $isLowBudget = str_contains($budget, 'bajo');
        $dietaryStyle = strtolower($nutritionalData['basic_data']['preferences']['dietary_style'] ?? 'omnívoro');
        $dislikedFoods = $nutritionalData['basic_data']['preferences']['disliked_foods'] ?? '';
        $allergies = $nutritionalData['basic_data']['health_status']['allergies'] ?? '';
        // Normalización (computar arrays normalizados para pasarlos)
        $dislikedArray = array_filter(array_map('trim', explode(',', $dislikedFoods)), fn($item) => !empty($item));
        $dislikedNorm = array_map([$this, 'normalizeForComparison'], $dislikedArray);
        $allergiesArray = array_filter(array_map('trim', explode(',', $allergies)), fn($item) => !empty($item));
        $allergiesNorm = array_map([$this, 'normalizeForComparison'], $allergiesArray);
        Log::info("🔍 Restricciones alimentarias del usuario", ['user_id' => $profile->user_id, 'disliked_foods' => $dislikedFoods, 'allergies' => $allergies]);
        $meals = [];
        foreach ($mealDistribution as $mealName => $percentage) {
            $mealProtein = round($macros['protein']['grams'] * $percentage);
            $mealCarbs = round($macros['carbohydrates']['grams'] * $percentage);
            $mealFats = round($macros['fats']['grams'] * $percentage);
            $mealCalories = round($macros['calories'] * $percentage);
            if (str_contains($mealName, 'Snack')) {
                $snackType = str_contains($mealName, 'AM') ? 'AM' : 'PM';
                $meals[$mealName] = $this->generateSnackOptions($mealCalories, $isLowBudget, $snackType, $dislikedFoods, $allergies);
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
                    $allergies,
                    $dislikedNorm,
                    $allergiesNorm
                );
            }
            // Asegurar metadata
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
        // Horarios
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
        // Recomendaciones y resumen
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



private function validateGeneratedPlan($planData, $nutritionalData): array
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

    // Analizar cada comida
    foreach ($planData['nutritionPlan']['meals'] as $mealName => $mealData) {
        foreach ($mealData as $category => $categoryData) {
            if (!isset($categoryData['options']) || !is_array($categoryData['options'])) {
                continue;
            }

            // Tomar la primera opción de cada categoría para el cálculo
            $firstOption = $categoryData['options'][0] ?? null;
            if ($firstOption) {
                $totalMacros['protein'] += $firstOption['protein'] ?? 0;
                $totalMacros['carbs'] += $firstOption['carbohydrates'] ?? 0;
                $totalMacros['fats'] += $firstOption['fats'] ?? 0;
                $totalMacros['calories'] += $firstOption['calories'] ?? 0;

                // ✅ NUEVO: Acumular fibra
                if (isset($firstOption['fiber'])) {
                    $totalMacros['fiber'] += $firstOption['fiber'];
                }

                // Registrar apariciones de alimentos
                foreach ($categoryData['options'] as $option) {
                    $foodName = strtolower($option['name'] ?? '');
                    if (!isset($foodAppearances[$foodName])) {
                        $foodAppearances[$foodName] = [];
                    }
                    $foodAppearances[$foodName][] = $mealName;

                    // Validar presupuesto
                    if ($isLowBudget) {
                        if ($this->isFoodHighBudget($foodName)) {
                            $errors[] = "Alimento de presupuesto alto en plan bajo: {$option['name']} en {$mealName}";
                        }
                    } else {
                        if ($this->isFoodLowBudget($foodName)) {
                            $warnings[] = "Alimento de presupuesto bajo en plan alto: {$option['name']} en {$mealName}";
                        }
                    }
                }
            }
        }
    }

    // ✅ VALIDACIÓN: Huevos máximo 1 vez al día
    $mealsWithEggs = [];
    foreach ($planData['nutritionPlan']['meals'] as $mealName => $mealData) {
        $hasEggInMeal = false;

        foreach ($mealData as $category => $categoryData) {
            if (!isset($categoryData['options']) || !is_array($categoryData['options'])) {
                continue;
            }

            foreach ($categoryData['options'] as $option) {
                if ($this->isEggProduct($option['name'] ?? '')) {
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

    // ✅ VALIDACIÓN: Alergias mortales
    $allergies = $nutritionalData['basic_data']['health_status']['allergies'] ?? '';

    if (!empty($allergies)) {
        $allergiesList = array_map('trim', array_map('strtolower', explode(',', $allergies)));

        foreach ($planData['nutritionPlan']['meals'] as $mealName => $mealData) {
            foreach ($mealData as $category => $categoryData) {
                foreach ($categoryData['options'] ?? [] as $option) {
                    $foodName = $this->removeAccents(strtolower($option['name'] ?? ''));

                    foreach ($allergiesList as $allergen) {
                        if (empty($allergen)) continue;

                        $allergenNormalized = $this->removeAccents($allergen);

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

    // ✅ VALIDACIÓN: Quinua NUNCA en desayuno
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

    // ✅ VALIDACIÓN: Prioridad de alimentos
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

    // ✅ VALIDACIÓN: Pesos cocido vs crudo
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

    // ✅ NUEVO: Validar vegetales obligatorios en comidas principales
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

    // ✅ VALIDACIÓN: Macros totales (tolerancia 5%)
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

    // ✅ NUEVO: Validar fibra total
    if ($totalMacros['fiber'] > 0) {
        $sex = $nutritionalData['basic_data']['sex'] ?? 'masculino';
        $targetFiber = (strtolower($sex) === 'masculino') ? 38 : 25;

        if ($totalMacros['fiber'] < $targetFiber * 0.8) { // 80% del objetivo mínimo
            $warnings[] = sprintf(
                'Fibra baja: %dg (objetivo: %dg diarios)',
                $totalMacros['fiber'],
                $targetFiber
            );
        }
    }

    // ✅ VALIDACIÓN: Balance entre comidas
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
    private function isFoodHighBudget($foodName): bool
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



    private function isFoodLowBudget($foodName): bool
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

  private function extractPersonalizationData($profile, $userName): array
{
    return [
        'personal_data' => [
            'name' => $userName,
            'preferred_name' => $userName,
            'goal' => $profile->goal,
            'age' => (int)$profile->age,
            'sex' => strtolower($profile->sex),
            'weight' => (float)$profile->weight,
            'height' => (float)$profile->height,
            'country' => $profile->pais ?? 'No especificado',
            'bmi' => $this->calculateBMI($profile->weight, $profile->height),
            'age_group' => $this->getAgeGroup($profile->age),
            'sex_normalized' => $profile->sex === 'Masculino' ? 'masculino' : 'femenino'
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
            'preferred_snack_time' => $profile->preferred_snack_time ?? 'Snack PM', // ✅ AGREGAR
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

    private function getAgeGroup($age): string
    {
        if (!$age) return 'desconocido';
        if ($age < 20) return 'juvenil';
        if ($age < 30) return 'adulto_joven';
        if ($age < 50) return 'adulto';
        if ($age < 65) return 'adulto_mayor';
        return 'senior';
    }

    private function calculateCompleteNutritionalPlan($profile, $userName): array
    {
        $this->validateAnthropometricData($profile);

        $basicData = [
            'age' => (int)$profile->age,
            'sex' => strtolower($profile->sex),
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

        // NUEVO: Ajustar calorías según objetivo con porcentajes fijos
$adjustedCalories = $this->adjustCaloriesForGoalFixed(
    $get,
    $basicData['goal'],
    $basicData['sex']  // ← AGREGAR PARÁMETRO
);
        // NUEVO: Calcular macros con porcentajes fijos según objetivo
        $macros = $this->calculateFixedMacronutrients($adjustedCalories, $basicData['goal']);

       // Calcular micronutrientes
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

    // ✅ MODIFICACIÓN APLICADA AQUÍ - DEFINICIÓN
    if (str_contains($goalLower, 'bajar grasa')) {
        // ANTES: 40/40/20
        // AHORA: 35/40/25
        $proteinPercentage = 0.35;  // ← Era 0.40 (-5%)
        $carbPercentage = 0.40;     // Mantiene
        $fatPercentage = 0.25;      // ← Era 0.20 (+5%)
    }
    // ✅ MODIFICACIÓN APLICADA AQUÍ - VOLUMEN
    elseif (str_contains($goalLower, 'aumentar músculo')) {
        // ANTES: 30/45/25
        // AHORA: 25/50/25
        $proteinPercentage = 0.25;  // ← Era 0.30 (-5%)
        $carbPercentage = 0.50;     // ← Era 0.45 (+5%)
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
        // ✅ SOLUCIÓN: Déficit diferente según sexo
        if (strtolower($sex) === 'femenino') {
            return $get * 0.75;  // 25% déficit para mujeres (SIN el 10% extra)
        } else {
            return $get * 0.65;  // 35% déficit para hombres (CON el 10% extra)
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

/**
 * Prioriza alimentos favoritos del usuario
 * Los favoritos aparecen primero en la lista de opciones
 */
private function prioritizeFoodOptions(array $allOptions, array $favoriteNames): array
{
    if (empty($favoriteNames)) {
        return $allOptions; // Sin preferencias, orden normal
    }

    $favorites = [];
    $others = [];

    foreach ($allOptions as $option) {
        $isFavorite = false;
        $optionName = strtolower($this->normalizeText($option['name']));

        // Verificar si este alimento está en favoritos
        foreach ($favoriteNames as $favName) {
            $favNameNormalized = strtolower($this->normalizeText($favName));

            // Comparación flexible
            if (
                strpos($optionName, $favNameNormalized) !== false ||
                strpos($favNameNormalized, $optionName) !== false ||
                $this->areNamesEquivalent($optionName, $favNameNormalized)
            ) {
                $isFavorite = true;
                break;
            }
        }

        if ($isFavorite) {
            $favorites[] = $option;
        } else {
            $others[] = $option;
        }
    }

    // Mezclar: favoritos primero, luego el resto
    $prioritized = array_merge($favorites, $others);

    Log::info('Alimentos priorizados', [
        'total' => count($allOptions),
        'favorites_found' => count($favorites),
        'others' => count($others)
    ]);

    return $prioritized;
}



private function normalizeText($text): string
{
    if (!is_string($text)) {
        if (is_array($text) && isset($text['name'])) {
            $text = $text['name']; // Usa 'name' si es opción de alimento
        } else {
            Log::warning("normalizeText recibió tipo inválido", ['type' => gettype($text), 'value' => json_encode($text)]);
            return '';
        }
    }
    $text = trim(strtolower($text));
    $unwanted = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
        'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u',
        'ñ'=>'n','Ñ'=>'n','ü'=>'u','Ü'=>'u'
    ];
    $text = strtr($text, $unwanted);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = str_replace(['-', '_', '/'], ' ', $text);
    return trim($text);
}


/**
 * Verificar equivalencias de nombres
 */
private function areNamesEquivalent(string $name1, string $name2): bool
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

        if (!$profile->sex || !in_array(strtolower($profile->sex), ['masculino', 'femenino'])) {
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


    /**
     * NUEVO: Obtener alimentos respetando orden de preferencias del PDF
     */
    private function getFoodsByPreference($category, $mealType, $isLowBudget, $dislikedFoods = [], $maxOptions = 3): array
    {
        $budget = $isLowBudget ? 'bajo' : 'alto';
        $foodList = [];

        if ($category === 'carbohidratos') {
            if ($mealType === 'Desayuno') {
                $foodList = self::FOOD_PREFERENCES['carbohidratos']['desayuno'];
            } elseif (str_contains($mealType, 'Snack')) {
                $foodList = self::FOOD_PREFERENCES['carbohidratos']['snacks'];
            } else {
                $foodList = self::FOOD_PREFERENCES['carbohidratos']['almuerzo_cena'];
            }
        } elseif ($category === 'proteinas') {
            if ($mealType === 'Desayuno') {
                $foodList = self::FOOD_PREFERENCES['proteinas'][$budget]['desayuno'];
            } elseif (str_contains($mealType, 'Snack')) {
                $foodList = self::FOOD_PREFERENCES['proteinas'][$budget]['snacks'];
            } else {
                $foodList = self::FOOD_PREFERENCES['proteinas'][$budget]['almuerzo_cena'];
            }
        } elseif ($category === 'grasas') {
            $foodList = self::FOOD_PREFERENCES['grasas'][$budget];
        }

        // Filtrar alimentos que no le gustan al usuario
        $selectedFoods = [];
        foreach ($foodList as $food) {
            // Saltar si no le gusta
            if ($this->foodIsDisliked($food, $dislikedFoods)) {
                continue;
            }

            // VALIDACIÓN CRÍTICA: Quinua NUNCA en desayuno
            if ($food === 'Quinua' && $mealType === 'Desayuno') {
                continue;
            }

            $selectedFoods[] = $food;

            if (count($selectedFoods) >= $maxOptions) {
                break;
            }
        }

        return $selectedFoods;
    }

private function foodIsDisliked($foodName, $dislikedFoods): bool
{
    if (empty($dislikedFoods)) {
        return false;
    }

    $dislikedArray = is_array($dislikedFoods)
        ? $dislikedFoods
        : array_filter(array_map('trim', explode(',', $dislikedFoods)));

    $foodNorm = $this->normalizeForComparison($foodName);

    foreach ($dislikedArray as $disliked) {
        if (empty($disliked) || !is_string($disliked)) continue;
        $dislikedNorm = $this->normalizeForComparison($disliked);
        if ($this->isSimilar($foodNorm, $dislikedNorm)) {
            return true;
        }
    }
    return false;
}


    private function filterAllergens(array $foodList, string $allergies): array
    {
        if (empty($allergies)) {
            return $foodList;
        }

        $allergensList = array_map('trim', array_map('strtolower', explode(',', $allergies)));
        $filtered = [];

        foreach ($foodList as $food) {
            $foodNormalized = $this->removeAccents(strtolower($food));
            $isAllergen = false;

            foreach ($allergensList as $allergen) {
                if (empty($allergen)) continue;

                $allergenNormalized = $this->removeAccents($allergen);

                // ⭐ APLICAR MISMA LÓGICA
                $isMatch = str_contains($foodNormalized, $allergenNormalized) ||
                    str_contains($allergenNormalized, $foodNormalized) ||
                    $this->containsAllKeywords($foodNormalized, $allergenNormalized);

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
        'Oficina + entreno 5-6 veces' => 1.48,  // ✅ ACTUALIZADO
        'Trabajo activo + entreno 1-2 veces' => 1.48,  // ✅ ACTUALIZADO
        'Trabajo activo + entreno 3-4 veces' => 1.68,  // ✅ ACTUALIZADO
        'Trabajo muy físico + entreno 5-6 veces' => 1.80  // ✅ ACTUALIZADO
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


    private function generateUltraPersonalizedNutritionalPlan($profile, $nutritionalData, $userName, $attemptNumber = 1): ?array
    {
        $prompt = $this->buildUltraPersonalizedPrompt($profile, $nutritionalData, $userName, $attemptNumber);

        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->timeout(150)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.3, // Baja temperatura para más precisión
            ]);

        if ($response->successful()) {
            $planData = json_decode($response->json('choices.0.message.content'), true);

            // Agregar datos nutricionales calculados al plan
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


    /**
 * Calcular porción de proteína según alimento específico
 */
private function calculateProteinPortionByFood($foodName, $targetProtein, $isLowBudget = true): ?array
{
    // Datos nutricionales por 100g
    $nutritionMapLow = [
        'Huevo entero' => [
            'protein' => 13,
            'calories' => 155,
            'fats' => 11,
            'carbs' => 1,
            'weigh_raw' => false,
            'unit' => 'unidad',
            'unit_weight' => 50 // gramos por unidad
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
            'calories' => 153,  // ⭐ ACTUALIZADO (era 200)
            'fats' => 7,         // ⭐ ACTUALIZADO (era 10)
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
        'Claras + Huevo entero' => [  // ⭐ NUEVO
            'protein' => 11,
            'calories' => 90,
            'fats' => 3,
            'carbs' => 1,
            'weigh_raw' => false,
            'unit' => 'mezcla',
            'unit_weight' => 55,
            'description' => '3 claras + 1 huevo entero'
        ],
         'Yogurt griego' => [  // Para presupuesto bajo en snacks
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
        'Yogurt griego alto en proteína' => [  // 🔴 SIN 's' final
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
            'protein' => 26,     // ⭐ Ya estaba bien
            'calories' => 153,   // ⭐ ACTUALIZADO (era 250)
            'fats' => 7,         // ⭐ ACTUALIZADO (era 15)
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
            'unit_weight' => 33 // gramos por clara
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
        // Calcular mezcla óptima: 70% claras, 30% huevo entero
        $totalUnits = round($targetProtein / 6.5); // Promedio de proteína por unidad
        if ($totalUnits < 3) $totalUnits = 3; // Mínimo 3 unidades

        $eggWholeUnits = max(1, round($totalUnits * 0.3)); // 30% huevos enteros
        $eggWhiteUnits = $totalUnits - $eggWholeUnits; // Resto claras

        $portion = sprintf('%d claras + %d huevo%s entero%s',
            $eggWhiteUnits,
            $eggWholeUnits,
            $eggWholeUnits > 1 ? 's' : '',
            $eggWholeUnits > 1 ? 's' : ''
        );

        // Calcular macros de la mezcla
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

    // Formatear porción
    if (isset($nutrition['unit']) && isset($nutrition['unit_weight'])) {
        // Calcular unidades
        $units = round($gramsNeeded / $nutrition['unit_weight']);
        if ($units < 1) $units = 1;

        $portion = "{$units} " . ($units == 1 ? $nutrition['unit'] : $nutrition['unit'] . 's');

        // Recalcular con unidades exactas
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


/**
 * Calcular porción de grasa según alimento específico
 */
private function calculateFatPortionByFood($foodName, $targetFats, $isLowBudget = true): ?array
{
    $nutritionMapLow = [
        'Aceite de oliva' => [  // ⭐ CAMBIADO de 'Aceite vegetal'
            'protein' => 0,
            'calories' => 884,
            'fats' => 100,
            'carbs' => 0,
            'density' => 0.92 // g/ml
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
            'unit_weight' => 200 // gramos por unidad promedio
        ],
         // ✅ AGREGAR ESTE:
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

    // Formatear porción según tipo de alimento
    if (str_contains(strtolower($foodName), 'aceite')) {
        // Aceites: mostrar en ml y cucharadas
        $ml = round($gramsNeeded * (1 / ($nutrition['density'] ?? 0.92)));
        $tbsp = max(1, round($ml / 15)); // 1 cucharada = 15ml
        $portion = "{$tbsp} " . ($tbsp == 1 ? 'cucharada' : 'cucharadas') . " ({$ml}ml)";

    } elseif (isset($nutrition['unit']) && isset($nutrition['unit_weight'])) {
        // Alimentos por unidad (aguacate)
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
        // Frutos secos: mostrar en gramos
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


    /**
     * Base de datos nutricional específica por presupuesto
     */

    private function generateSnackOptions($targetCalories, $isLowBudget, $snackType = 'AM', $dislikedFoods = '', $allergies = ''): array  // ⭐ AGREGAR PARÁMETRO
    {
        $targetProtein = round($targetCalories * 0.30 / 4);
        $targetCarbs = round($targetCalories * 0.50 / 4);
        $targetFats = round($targetCalories * 0.20 / 9);

        $options = [];

        // ===== PROTEÍNAS - CON FILTRO =====
        if ($isLowBudget) {
            $proteinOptions = ['Yogurt griego', 'Atún en lata'];
        } else {
            $proteinOptions = ['Proteína en polvo', 'Yogurt griego alto en proteína', 'Caseína'];
        }

        $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);  // ⭐ AGREGAR $allergies

        if (!empty($filteredProteins)) {
            $options['Proteínas'] = ['options' => []];

            foreach ($filteredProteins as $proteinName) {
                $portionData = $this->calculateProteinPortionByFood($proteinName, $targetProtein, $isLowBudget);
                if ($portionData) {
                    $options['Proteínas']['options'][] = $portionData;
                }
            }
        }

        // ===== CARBOHIDRATOS - CON FILTRO =====
        $carbOptions = ['Cereal de maíz', 'Crema de arroz', 'Galletas de arroz', 'Avena'];
        $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 4);  // ⭐ AGREGAR $allergies

        if (!empty($filteredCarbs)) {
            $options['Carbohidratos'] = ['options' => []];

            foreach ($filteredCarbs as $carbName) {
                $portionData = $this->calculateCarbPortionByFood($carbName, $targetCarbs);
                if ($portionData) {
                    $options['Carbohidratos']['options'][] = $portionData;
                }
            }
        }

        // ===== GRASAS - CON FILTRO =====
        if ($isLowBudget) {
            $fatOptions = ['Mantequilla de maní casera', 'Maní'];
        } else {
            $fatOptions = ['Mantequilla de maní', 'Miel', 'Chocolate negro 70%'];
        }

        $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));  // ⭐ AGREGAR $allergies
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
                    $portionData = $this->calculateFatPortionByFood($fatName, $targetFats, $isLowBudget);
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


    // Agregar después de la línea ~1070 aproximadamente, dentro de la clase

    private function getLowBudgetFruits($targetCalories): array
    {
        // Frutas económicas comunes
        return [
            [
                'name' => 'Plátano',
                'portion' => $this->calculateFruitPortion('platano', $targetCalories),
                'calories' => $targetCalories,
                'protein' => round($targetCalories * 0.01),
                'fats' => 0,
                'carbohydrates' => round($targetCalories * 0.23)
            ],
            [
                'name' => 'Manzana',
                'portion' => $this->calculateFruitPortion('manzana', $targetCalories),
                'calories' => $targetCalories,
                'protein' => round($targetCalories * 0.005),
                'fats' => 0,
                'carbohydrates' => round($targetCalories * 0.25)
            ],
            [
                'name' => 'Naranja',
                'portion' => $this->calculateFruitPortion('naranja', $targetCalories),
                'calories' => $targetCalories,
                'protein' => round($targetCalories * 0.02),
                'fats' => 0,
                'carbohydrates' => round($targetCalories * 0.24)
            ]
        ];
    }

    private function getHighBudgetFruits($targetCalories): array
    {
        // Frutas premium/orgánicas
        return [
            [
                'name' => 'Berries mix orgánico',
                'portion' => $this->calculateFruitPortion('berries', $targetCalories),
                'calories' => $targetCalories,
                'protein' => round($targetCalories * 0.015),
                'fats' => 0,
                'carbohydrates' => round($targetCalories * 0.22)
            ],
            [
                'name' => 'Mango',
                'portion' => $this->calculateFruitPortion('mango', $targetCalories),
                'calories' => $targetCalories,
                'protein' => round($targetCalories * 0.01),
                'fats' => 0,
                'carbohydrates' => round($targetCalories * 0.25)
            ],
            [
                'name' => 'Papaya',
                'portion' => $this->calculateFruitPortion('papaya', $targetCalories),
                'calories' => $targetCalories,
                'protein' => round($targetCalories * 0.01),
                'fats' => 0,
                'carbohydrates' => round($targetCalories * 0.24)
            ]
        ];
    }



    private function getFruitOptions($targetCalories, $isLowBudget, $country = 'Mexico'): array
    {
        $baseOptions = [];

        // Determinar frutas según presupuesto
        $fruits = $isLowBudget
            ? $this->getLowBudgetFruits($targetCalories)
            : $this->getHighBudgetFruits($targetCalories);

        // Agregar precios dinámicos
        foreach ($fruits as &$fruit) {
            $fruit['prices'] = $this->getDynamicPrices($fruit['name'], $country);
        }

        return $fruits;
    }


    private function getDynamicPrices($foodName, $country): array
    {
        $stores = match ($country) {
            'Peru' => ['Plaza Vea', 'Tottus', 'Wong'],
            'Argentina' => ['Coto', 'Carrefour', 'Dia'],
            'Chile' => ['Jumbo', 'Lider', 'Santa Isabel'],
            'Mexico' => ['Walmart', 'Soriana', 'Chedraui'],
            'Colombia' => ['Éxito', 'Jumbo', 'Carulla'],
            default => ['Walmart', 'Soriana', 'Chedraui'],
        };

        // Cache key para evitar llamadas repetidas
        $cacheKey = "prices_{$country}_{$foodName}";

        return Cache::remember($cacheKey, 3600, function () use ($foodName, $stores, $country) {
            return $this->fetchPricesFromAI($foodName, $stores, $country);
        });
    }



    private function fetchPricesFromAI($foodName, $stores, $country): array
    {
        $basePrices = [
            'platano' => 3.5,
            'manzana' => 4.0,
            'naranja' => 3.0,
            'berries' => 12.0,
            'mango' => 5.0,
            'papaya' => 4.5,
            'sandia' => 3.0,
            'melon' => 3.5,
            'pera' => 4.0,
            'uvas' => 8.0
        ];

        $foodKey = strtolower($foodName);
        $basePrice = $basePrices[$foodKey] ?? 5.0;

        $prices = [];
        foreach ($stores as $store) {
            $variation = rand(-15, 15) / 100;
            $prices[] = [
                'store' => $store,
                'price' => round($basePrice * (1 + $variation), 2)
            ];
        }

        return $prices;
    }


    private function calculateFruitPortion($fruitType, $targetCalories): string
    {
        // Calorías por 100g de cada fruta
        $caloriesPer100g = [
            'platano' => 89,
            'manzana' => 52,
            'naranja' => 47,
            'berries' => 57,
            'mango' => 60,
            'papaya' => 43,
            'sandia' => 30,
            'melon' => 34,
            'pera' => 57,
            'uvas' => 67
        ];

        $calPer100 = $caloriesPer100g[$fruitType] ?? 60;
        $grams = round(($targetCalories / $calPer100) * 100);

        // Convertir a porciones amigables
        if ($fruitType === 'platano') {
            $units = round($grams / 120); // ~120g por plátano
            return $units > 1 ? "{$units} unidades medianas" : "1 unidad mediana";
        } elseif ($fruitType === 'manzana') {
            $units = round($grams / 180); // ~180g por manzana
            return $units > 1 ? "{$units} unidades" : "1 unidad";
        } elseif ($fruitType === 'naranja') {
            $units = round($grams / 150); // ~150g por naranja
            return $units > 1 ? "{$units} unidades" : "1 unidad";
        } else {
            return "{$grams}g";
        }
    }



    private function buildUltraPersonalizedPrompt($profile, $nutritionalData, $userName, $attemptNumber = 1): string
{
    $macros = $nutritionalData['macros'];
    $basicData = $nutritionalData['basic_data'];
 $foodPreferences = $nutritionalData['food_preferences'] ?? [
        'proteins' => [],
        'carbs' => [],
        'fats' => [],
        'fruits' => []
    ];

   $favoritesSection = $this->buildFavoritesPromptSection($foodPreferences, $userName);

    $preferredName = $userName;
    $communicationStyle = $basicData['preferences']['communication_style'];

    $sports = !empty($basicData['sports_data']['sports']) ? implode(', ', $basicData['sports_data']['sports']) : 'Ninguno especificado';
    $mealTimes = $basicData['meal_times'];
    $difficulties = !empty($basicData['emotional_profile']['diet_difficulties']) ? implode(', ', $basicData['emotional_profile']['diet_difficulties']) : 'Ninguna especificada';
    $motivations = !empty($basicData['emotional_profile']['diet_motivations']) ? implode(', ', $basicData['emotional_profile']['diet_motivations']) : 'Ninguna especificada';

    $dislikedFoodsPrompt = '';
    if (!empty($basicData['preferences']['disliked_foods'])) {
        $dislikedList = $basicData['preferences']['disliked_foods'];

        $dislikedFoodsPrompt = "
🔴 **ALIMENTOS QUE {$userName} NO QUIERE COMER:**
{$dislikedList}

⚠️ PROHIBICIÓN ABSOLUTA - NUNCA VIOLAR:
- NUNCA uses estos alimentos en ninguna receta
- Si un alimento prohibido es clave para una categoría, usa alternativas:
  * NO pollo → USA: Atún, huevo, carne molida, pescado
  * NO arroz → USA: Papa, camote, fideo, quinua
  * NO aguacate → USA: Maní, aceite vegetal, almendras
  * NO huevo → USA: Atún, pollo, yogurt griego
  * NO lácteos → USA: Leches vegetales, tofu, legumbres
- Cada receta debe respetar estas restricciones
- Si no hay suficientes alternativas, informa al usuario
";
    }

    $allergiesPrompt = '';
    if (!empty($basicData['health_status']['allergies'])) {
        $allergiesList = $basicData['health_status']['allergies'];

        $allergiesPrompt = "
🚨 **ALERGIAS ALIMENTARIAS CRÍTICAS (PELIGRO DE MUERTE):**
{$allergiesList}

⚠️⚠️⚠️ ADVERTENCIA MÁXIMA:
- Estos alimentos pueden MATAR a {$userName}
- NUNCA incluyas ni rastros de estos ingredientes
- REVISA ingredientes ocultos (ej: trazas de frutos secos en productos)
- Ante la MÍNIMA duda, NO incluyas el ingrediente
";
    }

    $budget = $basicData['preferences']['budget'];
    $budgetType = str_contains(strtolower($budget), 'bajo') ? 'BAJO' : 'ALTO';

    $allowedFoods = $this->getAllowedFoodsByBudget($budgetType);
    $prohibitedFoods = $this->getProhibitedFoodsByBudget($budgetType);

    $dietaryInstructions = $this->getDetailedDietaryInstructions($basicData['preferences']['dietary_style']);
    $budgetInstructions = $this->getDetailedBudgetInstructions($budget, $basicData['country']);
    $communicationInstructions = $this->getCommunicationStyleInstructions($communicationStyle, $preferredName);
    $countrySpecificFoods = $this->getCountrySpecificFoods($basicData['country'], $budget);

    $attemptEmphasis = $attemptNumber > 1 ? "

    ⚠️ ATENCIÓN: Este es el intento #{$attemptNumber}. Los intentos anteriores fallaron por no cumplir las reglas.
    ES CRÍTICO que sigas TODAS las instrucciones AL PIE DE LA LETRA.
    " : "";

    // AGREGA DESPUÉS:
$deficitInfo = '';
if (str_contains(strtolower($basicData['goal']), 'bajar grasa')) {
    $sex = strtolower($basicData['sex']);
    $deficitPercentage = ($sex === 'femenino') ? '25%' : '35%';
    $deficitInfo = "

    📊 **DÉFICIT CALÓRICO APLICADO:**
    - Sexo: {$basicData['sex']}
    - Déficit: {$deficitPercentage} (GET: {$nutritionalData['get']} kcal → Objetivo: {$nutritionalData['target_calories']} kcal)
    " . (($sex === 'femenino') ?
        "- Para mujeres se usa un déficit moderado del 25% para evitar calorías muy bajas" :
        "- Para hombres se usa un déficit más agresivo del 35%");
}



    return "
    Eres un nutricionista experto especializado en planes alimentarios ULTRA-PERSONALIZADOS.
    Tu cliente se llama {$preferredName} y has trabajado con él/ella durante meses.

    {$attemptEmphasis}
    {$deficitInfo}
        {$favoritesSection}

    🔴 REGLAS CRÍTICAS OBLIGATORIAS - PRESUPUESTO {$budgetType} 🔴

    **REGLA #1: ALIMENTOS SEGÚN PRESUPUESTO {$budgetType}**
    **REGLA #1.5: RESTRICCIONES ESPECIALES DE ALIMENTOS**
- ❌ QUINUA: PROHIBIDA en Desayuno. Solo permitida en Almuerzo y Cena
- ⚠️ CAMOTE y MANÍ: Usar solo como ÚLTIMA opción si no hay alternativas

    " . ($budgetType === 'ALTO' ? "
    ✅ OBLIGATORIO usar ESTOS alimentos premium:
    PROTEÍNAS DESAYUNO: Claras + Huevo Entero, Yogurt griego, Proteína whey
    PROTEÍNAS ALMUERZO/CENA: Pechuga de pollo, Salmón fresco, Atún fresco, Carne de res magra
    CARBOHIDRATOS: Quinua, Avena orgánica, Pan integral artesanal, Camote, arroz blanco
    GRASAS: Aceite de oliva extra virgen, Almendras, Nueces, Aguacate hass

    ❌ PROHIBIDO usar: Huevo entero, Pollo muslo, Atún en lata, Aceite vegetal, Maní, Arroz blanco, Pan de molde
    " : "
    ✅ OBLIGATORIO usar ESTOS alimentos económicos:
    PROTEÍNAS: Huevo entero (MAX 1 comida), Pollo muslo, Atún en lata, Carne molida
    CARBOHIDRATOS: Arroz blanco, Papa, Avena tradicional, Tortillas de maíz, Fideos, Frijoles
    GRASAS: Aceite vegetal, Maní, Aguacate pequeño (cuando esté en temporada)

    ❌ PROHIBIDO usar: Salmón, Pechuga de pollo, Quinua, Almendras, Aceite de oliva extra virgen, Proteína en polvo
    ") . "

    **REGLA #2: VARIEDAD OBLIGATORIA**
    - Huevos (cualquier tipo): MÁXIMO 1 comida del día
    - NO repetir la misma proteína en más de 2 comidas
    - Cada comida debe tener opciones diferentes

    **REGLA #3: MACROS EXACTOS QUE DEBEN CUMPLIRSE**
    La suma total del día DEBE ser:
    - Proteínas: {$macros['protein']['grams']}g (tolerancia máxima ±5g)
    - Carbohidratos: {$macros['carbohydrates']['grams']}g (tolerancia máxima ±10g)
    - Grasas: {$macros['fats']['grams']}g (tolerancia máxima ±5g)
- Calorías totales: {$macros['calories']} kcal


**REGLA #4: CONTABILIZAR TODO (OBLIGATORIO)**
- ✅ OBLIGATORIO: Incluir MÍNIMO 100 kcal de vegetales en cada comida principal (Desayuno, Almuerzo, Cena)
- Las verduras NO son \"libres\", tienen un consumo mínimo obligatorio
- INCLUYE calorías de salsas y aderezos:
  * Aceite en ensalada: 10ml = 90 kcal
  * Salsa de tomate casera: 50ml = 25 kcal
  * Limón: despreciable
  * Vinagre balsámico: 15ml = 15 kcal
  * Mayonesa light: 15g = 30 kcal
- Rango de vegetales: 100-150 kcal por comida principal
- SUMA TODOS los componentes (proteína + carbos + grasas + vegetales + salsas) para llegar a los macros objetivo

**PORCIONES DE VEGETALES (100 kcal equivale a):**
- 2.5 tazas de ensalada mixta con tomate (350g)
- 2 tazas de vegetales al vapor: brócoli, zanahoria, ejotes (300g)
- 400g de ensalada verde: lechuga, espinaca, pepino
- 2 tazas de vegetales salteados con especias (280g)

**IMPORTANTE:** Estos vegetales DEBEN sumarse a los macros totales de la comida.

**REGLA #5: MICRONUTRIENTES OBLIGATORIOS**
- Fibra: Mínimo 10g por comida principal (objetivo diario: 30-40g total)
- Vitaminas: Incluir fuentes de vitamina C (cítricos, pimiento), D (pescado, huevo) y hierro (carnes/legumbres)
- Minerales: Asegurar calcio (lácteos, vegetales verdes), magnesio (frutos secos, semillas) y potasio (plátano, papa, vegetales)
- Cada comida debe aportar variedad de colores para diferentes fitonutrientes
- Los vegetales de 100 kcal aportan aproximadamente 6-9g de fibra


⚠️⚠️⚠️ ERROR COMÚN QUE DEBES EVITAR:
Los planes anteriores FALLARON porque pusieron:
- ❌ Grasas muy altas (59-65g cuando deberían ser {$macros['fats']['grams']}g)
- ❌ Carbohidratos muy bajos (164-165g cuando deberían ser {$macros['carbohydrates']['grams']}g)

✅ FÓRMULA CORRECTA 40/40/20:
- Proteínas = {$macros['calories']} kcal * 0.40 ÷ 4 cal/g = {$macros['protein']['grams']}g
- Carbohidratos = {$macros['calories']} kcal * 0.40 ÷ 4 cal/g = {$macros['carbohydrates']['grams']}g
- Grasas = {$macros['calories']} kcal * 0.20 ÷ 9 cal/g = {$macros['fats']['grams']}g

Si tus cálculos dan DIFERENTE, revisa tu matemática ANTES de responder.

**DISTRIBUCIÓN POR COMIDA:**
    - Desayuno: 30% de los macros totales
    - Almuerzo: 40% de los macros totales
    - Cena: 30% de los macros totales

    **INFORMACIÓN NUTRICIONAL CALCULADA:**
    - TMB: {$nutritionalData['tmb']} kcal
    - GET: {$nutritionalData['get']} kcal
    - Calorías Objetivo: {$nutritionalData['target_calories']} kcal
    - Factor de Actividad: {$nutritionalData['activity_factor']}

    **PERFIL DE {$preferredName}:**
    - Edad: {$basicData['age']} años, {$basicData['sex']}
    - Peso: {$basicData['weight']} kg, Altura: {$basicData['height']} cm
    - BMI: {$basicData['anthropometric_data']['bmi']} ({$basicData['anthropometric_data']['weight_status']})
    - País: {$basicData['country']}
    - Objetivo: {$basicData['goal']}
    - Deportes: {$sports}
    - Estilo alimentario: {$basicData['preferences']['dietary_style']}
    - Alimentos que NO le gustan: {$basicData['preferences']['disliked_foods']}
    - Alergias: {$basicData['health_status']['allergies']}
    - Come fuera: {$basicData['preferences']['eats_out']}
    - Dificultades: {$difficulties}
    - Motivaciones: {$motivations}
    {$dislikedFoodsPrompt}
    {$allergiesPrompt}
    {$budgetInstructions}
    {$dietaryInstructions}
    {$communicationInstructions}

    **ALIMENTOS ESPECÍFICOS PARA {$basicData['country']}:**
    {$countrySpecificFoods}

    **VERIFICACIÓN OBLIGATORIA ANTES DE RESPONDER:**

    🔴🔴🔴 CÁLCULO MATEMÁTICO PASO A PASO 🔴🔴🔴

    **PASO 1: MACROS POR COMIDA (YA CALCULADOS)**
    Desayuno (30% del total):
    - Proteínas: " . round($macros['protein']['grams'] * 0.30) . "g
    - Carbohidratos: " . round($macros['carbohydrates']['grams'] * 0.30) . "g
    - Grasas: " . round($macros['fats']['grams'] * 0.30) . "g
    - Calorías: ~" . round($macros['calories'] * 0.30) . " kcal

    Almuerzo (40% del total):
    - Proteínas: " . round($macros['protein']['grams'] * 0.40) . "g
    - Carbohidratos: " . round($macros['carbohydrates']['grams'] * 0.40) . "g
    - Grasas: " . round($macros['fats']['grams'] * 0.40) . "g
    - Calorías: ~" . round($macros['calories'] * 0.40) . " kcal

    Cena (30% del total):
    - Proteínas: " . round($macros['protein']['grams'] * 0.30) . "g
    - Carbohidratos: " . round($macros['carbohydrates']['grams'] * 0.30) . "g
    - Grasas: " . round($macros['fats']['grams'] * 0.30) . "g
    - Calorías: ~" . round($macros['calories'] * 0.30) . " kcal

    **PASO 2: FÓRMULA PARA CALCULAR PORCIONES**
    Para CADA alimento, usa esta fórmula obligatoria:

    Porción (gramos) = (Macro objetivo de la comida ÷ Macro por 100g del alimento) × 100

    📝 EJEMPLOS REALES para que entiendas:

    Desayuno Proteínas (necesitas " . round($macros['protein']['grams'] * 0.30) . "g):
    • Si usas Claras pasteurizadas (11g proteína/100g):
      → Porción = (" . round($macros['protein']['grams'] * 0.30) . " ÷ 11) × 100 = " . round(($macros['protein']['grams'] * 0.30 / 11) * 100) . "g

    • Si usas Yogurt griego alto en proteínas (20g proteína/100g):
      → Porción = (" . round($macros['protein']['grams'] * 0.30) . " ÷ 20) × 100 = " . round(($macros['protein']['grams'] * 0.30 / 20) * 100) . "g

    Desayuno Carbohidratos (necesitas " . round($macros['carbohydrates']['grams'] * 0.30) . "g):
    • Si usas Avena orgánica (67g carbos/100g):
      → Porción = (" . round($macros['carbohydrates']['grams'] * 0.30) . " ÷ 67) × 100 = " . round(($macros['carbohydrates']['grams'] * 0.30 / 67) * 100) . "g

    **PASO 3: VERIFICAR SUMA TOTAL (CRÍTICO)**
    Después de calcular TODAS las porciones, SUMA los macros de las opciones primarias:

    ✓ Total Proteínas = {$macros['protein']['grams']}g (tolerancia: ±5g)
    ✓ Total Carbohidratos = {$macros['carbohydrates']['grams']}g (tolerancia: ±10g)
    ✓ Total Grasas = {$macros['fats']['grams']}g (tolerancia: ±5g)

    ⚠️⚠️⚠️ SI LA SUMA NO CUMPLE, AJUSTA LAS PORCIONES HASTA QUE SÍ ⚠️⚠️⚠️

    **PASO 4: CHECKLIST FINAL**
    Antes de generar el JSON, verifica:
    1. ✓ ¿Todos los alimentos son del presupuesto {$budgetType}?
    2. ✓ ¿Los huevos aparecen máximo 1 vez al día?
    3. ✓ ¿Hay variedad entre las comidas?
    4. ✓ ¿La quinua NO está en desayuno?
    5. ✓ ¿Los pesos están correctos (cocido vs crudo)?
    6. ✓ ¿La suma de proteínas = {$macros['protein']['grams']}g ±5g?
    7. ✓ ¿La suma de carbos = {$macros['carbohydrates']['grams']}g ±10g?
    8. ✓ ¿La suma de grasas = {$macros['fats']['grams']}g ±5g?

    🔴 RESTRICCIONES ABSOLUTAS - NUNCA VIOLAR:
    " . ($allergiesPrompt ? "- ALERGIAS MORTALES ya especificadas arriba ☝️" : "- No hay alergias reportadas") . "
    " . ($dislikedFoodsPrompt ? "- ALIMENTOS NO DESEADOS ya especificados arriba ☝️" : "- No hay alimentos que evitar") . "

    **ESTRUCTURA JSON OBLIGATORIA:**
```json
    {
      \"nutritionPlan\": {
        \"personalizedMessage\": \"Mensaje personal para {$preferredName}...\",
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
          \"proteinPerKg\":0,
          \"specialConsiderations\": []
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
              \"perKg\": 0
            },
            \"fats\": {
              \"grams\": {$macros['fats']['grams']},
              \"calories\": {$macros['fats']['calories']},
              \"percentage\": {$macros['fats']['percentage']},
              \"perKg\": 0
            },
            \"carbohydrates\": {
              \"grams\": {$macros['carbohydrates']['grams']},
              \"calories\": {$macros['carbohydrates']['calories']},
              \"percentage\": {$macros['carbohydrates']['percentage']},
              \"perKg\": 0
            }
          }
        },
        \"mealSchedule\": {
          \"breakfast\": \"{$mealTimes['breakfast_time']}\",
          \"lunch\": \"{$mealTimes['lunch_time']}\",
          \"dinner\": \"{$mealTimes['dinner_time']}\"
        },
        \"meals\": {
          \"Desayuno\": {
            \"Proteínas\": {
              \"options\": [
                // 3 opciones diferentes, respetando presupuesto {$budgetType}
              ]
            },
            \"Carbohidratos\": {
              \"options\": [
                // 3 opciones diferentes
              ]
            },
            \"Grasas\": {
              \"options\": [
                // 2-3 opciones diferentes
              ]
            },
            \"Vegetales\": {
              \"options\": [
                {\"name\": \"Ensalada LIBRE\", \"portion\": \"Sin restricción\", \"calories\": 25, \"protein\": 2, \"fats\": 0, \"carbohydrates\": 5}
              ]
            }
          },
          \"Almuerzo\": {
            // Similar estructura, DIFERENTES proteínas que en desayuno
          },
          \"Cena\": {
            // Similar estructura, DIFERENTES proteínas que en almuerzo
          }
        },
        \"personalizedTips\": {
          \"anthropometricGuidance\": \"Consejos basados en BMI {$basicData['anthropometric_data']['bmi']}\",
          \"difficultySupport\": \"Apoyo para: {$difficulties}\",
          \"motivationalElements\": \"Reforzando: {$motivations}\",
          \"eatingOutGuidance\": \"Guía para comer fuera ({$basicData['preferences']['eats_out']})\",
          \"ageSpecificAdvice\": \"Recomendaciones para {$basicData['age']} años\"
        }
      }
    }
```

    🔴 RECUERDA:
    - Presupuesto {$budgetType} = usar SOLO alimentos de ese presupuesto
    - Los macros DEBEN sumar EXACTAMENTE (usa la fórmula del PASO 2)
    - Calcula bien las porciones antes de responder

Genera el plan COMPLETO en el siguiente idioma para {$preferredName}: " . 
($this->locale === 'en' ? 'inglés (US)' : 'español (Latinoamérica)') . "
Usa nombres de ingredientes y medidas locales según el idioma.
";
}



    private function getAllowedFoodsByBudget($budgetType): array
    {
        if ($budgetType === 'ALTO') {
            return [
                'proteinas' => ['Claras + Huevo Entero', 'Yogurt griego', 'Proteína whey', 'Pechuga de pollo', 'Salmón', 'Atún fresco'],
                'carbohidratos' => ['Quinua', 'Avena orgánica', 'Pan integral artesanal', 'Camote', 'arroz blanco'],
                'grasas' => ['Aceite de oliva extra virgen', 'Almendras', 'Nueces', 'Aguacate hass']
            ];
        } else {
            return [
                'proteinas' => ['Huevo entero', 'Pollo muslo', 'Atún en lata', 'Carne molida'],
                'carbohidratos' => ['Arroz blanco', 'Papa', 'Avena tradicional', 'Tortillas de maíz', 'Fideos'],
                'grasas' => ['Aceite vegetal', 'Maní', 'Aguacate pequeño']
            ];
        }
    }

    private function getProhibitedFoodsByBudget($budgetType): array
    {
        if ($budgetType === 'ALTO') {
            return ['Huevo entero', 'Pollo muslo', 'Atún en lata', 'Aceite vegetal', 'Maní', 'Arroz blanco'];
        } else {
            return ['Salmón', 'Pechuga de pollo', 'Quinua', 'Almendras', 'Aceite de oliva extra virgen'];
        }
    }



    /**
 * Determina la estructura óptima de comidas según los macros
 */
private function determineOptimalMealStructure(array $macros): array
{
    $totalCalories = $macros['calories'];

    // Estructura de 5 comidas optimizada
    return [
        'structure' => '5_comidas',
        'distribution' => [
            'Desayuno' => 0.25,   // 25%
            'Snack AM' => 0.10,   // 10%
            'Almuerzo' => 0.35,   // 35%
            'Snack PM' => 0.10,   // 10%
            'Cena' => 0.20        // 20%
        ],
        'total_calories' => $totalCalories,
        'rationale' => 'Distribución optimizada para mantener energía constante y controlar el apetito'
    ];
}
 


    // Helper method para obtener tips personalizados
    private function getMealTips($mealName, $structure): array
    {
        $tips = [];

        switch ($mealName) {
            case 'Desayuno':
                $tips[] = 'Desayuno diseñado para darte energía sostenida hasta el almuerzo';
                $tips[] = 'Alto en proteína para activar tu metabolismo desde temprano';
                break;
            case 'Almuerzo':
                $tips[] = 'Tu comida principal del día con el 40% de tus nutrientes';
                break;
            case 'Cena':
                $tips[] = 'Cena balanceada para recuperación nocturna óptima';
                break;
            case 'Snack Proteico':
                $tips[] = 'Snack estratégico para completar tus macros diarios';
                $tips[] = 'Consume entre comidas o después de entrenar';
                break;
        }

        return $tips;
    }


    // LÍNEA ~1241 - Nuevos métodos auxiliares
    private function isEggProduct($foodName): bool
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

    /**
 * NUEVO: Filtrar alimentos según preferencias del usuario
 */
/**
 * Filtrar alimentos que NO le gustan al usuario
 */

    private function filterFoodOptions($foodList, $dislikedFoods, $maxOptions = 4): array
    {
        $dislikedArray = is_array($dislikedFoods)
            ? $dislikedFoods
            : array_map('trim', explode(',', strtolower($dislikedFoods)));

        $filtered = [];

        foreach ($foodList as $food) {
            $foodNormalized = $this->removeAccents(strtolower($food));
            $isDisliked = false;

            foreach ($dislikedArray as $disliked) {
                $dislikedNormalized = $this->removeAccents(strtolower(trim($disliked)));

                if (!empty($dislikedNormalized)) {
                    // ⭐ NUEVA LÓGICA: Coincidencia bidireccional O por palabras clave
                    $isMatch = str_contains($foodNormalized, $dislikedNormalized) ||
                        str_contains($dislikedNormalized, $foodNormalized) ||
                        $this->containsAllKeywords($foodNormalized, $dislikedNormalized);

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

    /**
     * ⭐ NUEVO MÉTODO: Verificar si todas las palabras clave están presentes
     */
    private function containsAllKeywords(string $text, string $keywords): bool
    {
        // Dividir keywords en palabras individuales
        $keywordWords = array_filter(explode(' ', $keywords), function($word) {
            // Ignorar palabras muy cortas (de, el, la, etc)
            return strlen($word) > 2;
        });

        // Verificar que TODAS las palabras clave estén en el texto
        foreach ($keywordWords as $word) {
            if (!str_contains($text, $word)) {
                return false;
            }
        }

        return !empty($keywordWords); // Solo true si hay palabras válidas
    }



    /**
     * Filtrar alimentos por ALERGIAS y GUSTOS (en ese orden)
     * Esta función combina ambos filtros para asegurar que NUNCA se incluyan
     * alimentos peligrosos (alergias) o no deseados (gustos)
     */
    private function getFilteredFoodOptions(
        array $foodList,
        string $dislikedFoods,
        string $allergies,
        int $maxOptions = 4
    ): array {
        // PASO 1: Filtrar ALERGIAS (crítico - primero)
        if (!empty($allergies)) {
            $foodList = $this->filterAllergens($foodList, $allergies);

            Log::info("🚨 Alimentos después de filtrar alergias", [
                'remaining' => $foodList,
                'allergies' => $allergies
            ]);
        }

        // PASO 2: Filtrar GUSTOS
        if (!empty($dislikedFoods)) {
            $foodList = $this->filterFoodOptions($foodList, $dislikedFoods, count($foodList));

            Log::info("❌ Alimentos después de filtrar gustos", [
                'remaining' => $foodList,
                'disliked' => $dislikedFoods
            ]);
        }

        // PASO 3: Limitar cantidad
        $result = array_slice($foodList, 0, $maxOptions);

        // PASO 4: Si se filtraron TODOS, devolver fallback seguro
        if (empty($result)) {
            Log::warning("⚠️ Todos los alimentos fueron filtrados, usando fallback", [
                'original_list' => func_get_arg(0),
                'allergies' => $allergies,
                'disliked' => $dislikedFoods
            ]);

            // Devolver opciones genéricas seguras según el contexto
            return ['Arroz blanco', 'Papa', 'Lentejas'];
        }

        return $result;
    }


    /**
     * Filtrar opciones de alimentos según preferencias del usuario
     */
    /**
     * Filtrar opciones de alimentos según preferencias del usuario
     * Soporta: disliked_foods y allergies
     */
 private function filterOptionsByPreferences(array $mealOptions, string $dislikedFoods): array
{
    if (empty($dislikedFoods)) {
        return $mealOptions;
    }

    // Dividir y limpiar UNA VEZ
    $dislikedList = array_filter(
        array_map('trim', explode(',', $dislikedFoods)),
        fn($item) => is_string($item) && !empty($item)
    );

    // Normalizar cada disliked
    $dislikedNorm = array_map([$this, 'normalizeForComparison'], $dislikedList);

    foreach ($mealOptions as $category => &$categoryData) {
        if (!isset($categoryData['options']) || !is_array($categoryData['options'])) {
            continue;
        }

        $filteredOptions = array_filter(
            $categoryData['options'],
            function ($option) use ($dislikedNorm, $category) {
                $foodName = $option['name'] ?? '';
                if (!is_string($foodName)) {
                    Log::warning("Nombre de opción no es string", ['option' => $option]);
                    return true;
                }

                $foodNorm = $this->normalizeForComparison($foodName);

                foreach ($dislikedNorm as $dislikedNormItem) {
                    if ($this->isSimilar($foodNorm, $dislikedNormItem)) {
                        Log::info("Opción filtrada por disliked", [
                            'food' => $foodName,
                            'disliked_match' => $dislikedNormItem,
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
            Log::warning("Categoría quedó sin opciones tras filtro", [
                'category' => $category,
                'original_disliked' => $dislikedFoods
            ]);
        }
    }

    return $mealOptions;
}

    /**
     * Eliminar acentos de una cadena para comparación flexible
     */
    private function removeAccents(string $text): string
    {
        $unwanted = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'ñ' => 'n', 'Ñ' => 'n'
        ];

        return strtr(strtolower($text), $unwanted);
    }

/**
 * Aplicar sistema de preferencias: priorizar alimentos preferidos
 * y usar los menos preferidos solo si no hay suficientes opciones
 */
private function applyFoodPreferenceSystem($foodList, $mealType, $dislikedFoods, $minOptions = 3): array
{
    // 🔴 Alimentos que son ÚLTIMA OPCIÓN (solo usar si no hay alternativas)
    $leastPreferredFoods = [
        'Camote',
        'Maní',
        'Mantequilla de maní',
        'Mantequilla de maní casera'
    ];

    // Paso 1: Filtrar alimentos que NO le gustan al usuario
    $filtered = $this->filterFoodOptions($foodList, $dislikedFoods, count($foodList));

    // Paso 2: Separar alimentos en preferidos y menos preferidos
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

    // Paso 3: Si tenemos suficientes preferidos, usar solo esos
    if (count($preferred) >= $minOptions) {
        Log::info("Usando solo alimentos preferidos para {$mealType}", [
            'preferred' => $preferred,
            'excluded' => $lessPreferred
        ]);
        return array_slice($preferred, 0, $minOptions);
    }

    // Paso 4: Si no, complementar con menos preferidos
    $result = array_merge($preferred, $lessPreferred);

    Log::info("Complementando con alimentos menos preferidos para {$mealType}", [
        'preferred_count' => count($preferred),
        'less_preferred_used' => array_slice($lessPreferred, 0, $minOptions - count($preferred))
    ]);

    return array_slice($result, 0, $minOptions);
}

    private function addFoodMetadata(&$option, $isLowBudget = false)
    {
        $foodName = $option['name'] ?? '';
        $option['isEgg'] = $this->isEggProduct($foodName);
        $option['isHighBudget'] = $this->isFoodHighBudget($foodName);
        $option['isLowBudget'] = $this->isFoodLowBudget($foodName);
        $option['budgetAppropriate'] = $isLowBudget ? !$option['isHighBudget'] : !$option['isLowBudget'];
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
    // 🔴 NUEVA LÓGICA: Forzar 40/40/20 en CADA comida
    $mealCalories = ($targetProtein * 4) + ($targetCarbs * 4) + ($targetFats * 9);
    // Recalcular macros para que esta comida específica sea 40/40/20
    $targetProtein = round(($mealCalories * 0.40) / 4);
    $targetCarbs = round(($mealCalories * 0.40) / 4);
    $targetFats = round(($mealCalories * 0.20) / 9);
    Log::info("Macros recalculados para {$mealName} con ratio 40/40/20", [
        'calories' => $mealCalories,
        'protein' => $targetProtein,
        'carbs' => $targetCarbs,
        'fats' => $targetFats
    ]);
    
    // NUEVO: Usar calculadora de ajuste
    $calculator = new NutritionalCalculator();
    $adjustedTargets = $calculator->calculateAdjustedPortions(
        [
            'protein' => $targetProtein,
            'carbohydrates' => $targetCarbs,
            'fats' => $targetFats
        ],
        1.0
    );
    
    // Usar los valores ajustados
    $adjustedProtein = round($adjustedTargets['protein']['primary']);
    $adjustedCarbs = round($adjustedTargets['carbohydrates']['primary']);
    $adjustedFats = round($adjustedTargets['fats']['primary']);
    
    $dietaryStyle = strtolower($dietaryStyle);
    $dietaryStyle = preg_replace('/[^\w\s]/u', '', $dietaryStyle);
    $dietaryStyle = trim($dietaryStyle);
    
    $options = [];
    
    if (str_contains($dietaryStyle, 'vegano')) {
        $options = $this->getVeganOptions($mealName, $adjustedProtein, $adjustedCarbs, $adjustedFats, $isLowBudget, $dislikedFoods, $foodPreferences, $allergies);
    } elseif (str_contains($dietaryStyle, 'vegetariano')) {
        $options = $this->getVegetarianOptions($mealName, $adjustedProtein, $adjustedCarbs, $adjustedFats, $isLowBudget, $dislikedFoods, $foodPreferences, $allergies);
    } elseif (str_contains($dietaryStyle, 'keto')) {
        $options = $this->getKetoOptions($mealName, $adjustedProtein, $adjustedCarbs, $adjustedFats, $isLowBudget, $dislikedFoods, $foodPreferences, $allergies);
    } else {
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
    
    // ⭐ Normalizar disliked/allergies ANTES de usarlos
    $dislikedArray = array_filter(array_map('trim', explode(',', $dislikedFoods ?? '')), fn($item) => !empty($item));
    $dislikedNorm = array_map([$this, 'normalizeForComparison'], $dislikedArray);

    $allergiesArray = array_filter(array_map('trim', explode(',', $allergies ?? '')), fn($item) => !empty($item));
    $allergiesNorm = array_map([$this, 'normalizeForComparison'], $allergiesArray);
    
    // ⭐ ELIMINADO: El bloque de "FORZAR FAVORITOS GENÉRICOS" que estaba aquí
    // Los favoritos ya se fuerzan dentro de getOmnivorousOptions() y similares
    
    // EXCLUSIÓN FORZADA DE DISLIKED/ALERGIAS (genérica y robusta)
    foreach (['Proteínas', 'Carbohidratos', 'Grasas'] as $cat) {
        if (isset($options[$cat]['options'])) {
            $filtered = [];
            foreach ($options[$cat]['options'] as $opt) {
                // ⭐ FIX 1: Validar que sea un array
                if (is_string($opt)) {
                    Log::warning("⚠️ Elemento es string en lugar de array, omitiendo", [
                        'category' => $cat,
                        'value' => $opt
                    ]);
                    continue; // Saltar este elemento
                }
                
                // ⭐ FIX 2: Validar que tenga la clave 'name'
                if (!isset($opt['name'])) {
                    Log::warning("⚠️ Elemento array sin 'name', omitiendo", [
                        'category' => $cat,
                        'opt' => $opt
                    ]);
                    continue;
                }
                
                // Ahora sí validar si está en disliked/allergies
                if (!$this->isDislikedOrAllergic($opt['name'], $dislikedNorm, $allergiesNorm)) {
                    $filtered[] = $opt;
                } else {
                    Log::info("Eliminado por disliked/allergy", ['food' => $opt['name']]);
                }
            }
            
            $options[$cat]['options'] = $filtered;
            
            // Fallback si se filtró todo
            if (empty($filtered)) {
                $fallbackName = match ($cat) {
                    'Proteínas' => 'Pechuga de pollo',
                    'Carbohidratos' => 'Arroz blanco',
                    'Grasas' => 'Aceite de oliva',
                    default => 'Arroz blanco'
                };
                $fallback = $this->calculateGenericPortion($fallbackName);
                if ($fallback) {
                    $options[$cat]['options'][] = $fallback;
                    Log::info("Fallback agregado tras filtrado completo", ['category' => $cat]);
                }
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

        // ===== CARBOHIDRATOS KETO =====
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

        // ===== PROTEÍNAS KETO =====
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

        // ===== GRASAS KETO =====
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

        // Agregar metadata
        foreach ($options as $category => &$categoryData) {
            if (isset($categoryData['options'])) {
                foreach ($categoryData['options'] as &$option) {
                    $this->addFoodMetadata($option, $isLowBudget);
                }
            }
        }

        return $options;
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
    // ⭐ AGREGAR ESTO AL INICIO - Normalizar alergias y disgustos
    $dislikedArray = array_filter(array_map('trim', explode(',', $dislikedFoods)), fn($item) => !empty($item));
    $dislikedNorm = array_map([$this, 'normalizeForComparison'], $dislikedArray);

    $allergiesArray = array_filter(array_map('trim', explode(',', $allergies)), fn($item) => !empty($item));
    $allergiesNorm = array_map([$this, 'normalizeForComparison'], $allergiesArray);
    // ⭐ FIN DE LO QUE AGREGASTE

    $options = [];

    if ($isLowBudget) {
        // PRESUPUESTO BAJO - OMNÍVORO
        if ($mealName === 'Desayuno') {
            // ===== PROTEÍNAS =====
            $proteinOptions = ['Huevo entero', 'Atún en lata', 'Pollo muslo'];
            $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            $options['Proteínas'] = ['options' => []];
            foreach ($filteredProteins as $proteinName) {
                $portionData = $this->calculateProteinPortionByFood($proteinName, $targetProtein);
                if ($portionData) {
                    $options['Proteínas']['options'][] = $portionData;
                }
            }

            // ===== CARBOHIDRATOS =====
            $carbOptions = ['Avena', 'Pan integral', 'Tortilla de maíz'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            $options['Carbohidratos'] = ['options' => []];
            foreach ($filteredCarbs as $carbName) {
                $portionData = $this->calculateCarbPortionByFood($carbName, $targetCarbs);
                if ($portionData) {
                    $options['Carbohidratos']['options'][] = $portionData;
                }
            }

            // ===== GRASAS =====
            $fatOptions = ['Aceite vegetal', 'Maní', 'Aguacate'];
            $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
            $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
            $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'Desayuno-Grasas', '', 3);

            $options['Grasas'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                $portionData = $this->calculateFatPortionByFood($fatName, $targetFats);
                if ($portionData) {
                    $options['Grasas']['options'][] = $portionData;
                }
            }

        } elseif ($mealName === 'Almuerzo') {
            // ===== PROTEÍNAS =====
             $proteinOptions = ['Pollo muslo', 'Carne molida', 'Atún en lata', 'Pechuga de pollo'];

            $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            $options['Proteínas'] = ['options' => []];
            foreach ($filteredProteins as $proteinName) {
                $portionData = $this->calculateProteinPortionByFood($proteinName, $targetProtein);
                if ($portionData) {
                    $options['Proteínas']['options'][] = $portionData;
                }
            }

            // ===== CARBOHIDRATOS =====
            $carbOrderPreference = ['Papa', 'Arroz blanco', 'Camote', 'Fideo', 'Frijoles', 'Quinua'];
            $carbOrderPreference = $this->prioritizeFoodList($carbOrderPreference, $foodPreferences['carbs'] ?? []);
            $selectedCarbs = $this->getFilteredFoodOptions($carbOrderPreference, $dislikedFoods, $allergies, 6);
            $selectedCarbs = $this->applyFoodPreferenceSystem($selectedCarbs, 'Almuerzo-Carbos', '', 6);

            $options['Carbohidratos'] = ['options' => []];
            foreach ($selectedCarbs as $foodName) {
                $portionData = $this->calculateCarbPortionByFood($foodName, $targetCarbs);
                if ($portionData) {
                    $options['Carbohidratos']['options'][] = $portionData;
                }
            }

            // ===== GRASAS =====
            $fatOptions = ['Aceite vegetal', 'Maní', 'Aguacate'];
            $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
            $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
            $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'Almuerzo-Grasas', '', 3);

            $options['Grasas'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                $portionData = $this->calculateFatPortionByFood($fatName, $targetFats);
                if ($portionData) {
                    $options['Grasas']['options'][] = $portionData;
                }
            }

        } else { // Cena
            // ===== PROTEÍNAS =====
            $proteinOptions = ['Atún en lata', 'Pollo muslo', 'Carne molida', 'Huevo entero'];

            $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);
            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            $options['Proteínas'] = ['options' => []];
            foreach ($filteredProteins as $proteinName) {
                $portionData = $this->calculateProteinPortionByFood($proteinName, $targetProtein);
                if ($portionData) {
                    $options['Proteínas']['options'][] = $portionData;
                }
            }

            // ===== CARBOHIDRATOS =====
            $carbOptions = ['Arroz blanco', 'Frijoles', 'Tortilla de maíz', 'Papa'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 4);
            $filteredCarbs = $this->applyFoodPreferenceSystem($filteredCarbs, 'Cena-Carbos', '', 3);

            $options['Carbohidratos'] = ['options' => []];
            foreach ($filteredCarbs as $carbName) {
                $portionData = $this->calculateCarbPortionByFood($carbName, $targetCarbs);
                if ($portionData) {
                    $options['Carbohidratos']['options'][] = $portionData;
                }
            }

            // ===== GRASAS =====
            $fatOptions = ['Aceite vegetal', 'Maní', 'Aguacate'];
            $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
            $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
            $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'Cena-Grasas', '', 3);

            $options['Grasas'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                $portionData = $this->calculateFatPortionByFood($fatName, $targetFats);
                if ($portionData) {
                    $options['Grasas']['options'][] = $portionData;
                }
            }
        }
    } else {
        // ===== PRESUPUESTO ALTO - OMNÍVORO =====
        if ($mealName === 'Desayuno') {
            // PROTEÍNAS
            $proteinOptions = ['Claras + Huevo entero', 'Yogurt griego alto en proteínas', 'Proteína whey'];
            $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);

            // ⭐ CORRECCIÓN AQUÍ - Cambiar $allergies y $dislikedFoods por los arrays normalizados
            $forcedFavorites = $this->getForcedFavoritesForMeal('Desayuno', 'Proteínas', $foodPreferences['proteins'] ?? []);
            $proteinOptions = $this->ensureForcedFavoritesInList($proteinOptions, $forcedFavorites, $allergiesNorm, $dislikedNorm);

            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            $options['Proteínas'] = ['options' => []];
            foreach ($filteredProteins as $proteinName) {
                $portionData = $this->calculateProteinPortionByFood($proteinName, $targetProtein, false);
                if ($portionData) {
                    $options['Proteínas']['options'][] = $portionData;
                }
            }

            // CARBOHIDRATOS
            $carbOptions = ['Avena orgánica', 'Pan integral artesanal'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            $options['Carbohidratos'] = ['options' => []];
            foreach ($filteredCarbs as $carbName) {
                $portionData = $this->calculateCarbPortionByFood($carbName, $targetCarbs);
                if ($portionData) {
                    $options['Carbohidratos']['options'][] = $portionData;
                }
            }

            // GRASAS
            $fatOptions = ['Aceite de oliva extra virgen', 'Almendras', 'Aguacate hass'];
            $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
            $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
            $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'Desayuno-Grasas', '', 3);

            $options['Grasas'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                $portionData = $this->calculateFatPortionByFood($fatName, $targetFats, false);
                if ($portionData) {
                    $options['Grasas']['options'][] = $portionData;
                }
            }

        } elseif ($mealName === 'Almuerzo') {
            // PROTEÍNAS
            $proteinOptions = ['Pechuga de pollo', 'Salmón fresco', 'Carne de res magra', 'Atún en lata', 'Pechuga de pavo'];
            $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);

            // ⭐ CORRECCIÓN AQUÍ
            $forcedFavorites = $this->getForcedFavoritesForMeal('Almuerzo', 'Proteínas', $foodPreferences['proteins'] ?? []);
            $proteinOptions = $this->ensureForcedFavoritesInList($proteinOptions, $forcedFavorites, $allergiesNorm, $dislikedNorm);

            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            $options['Proteínas'] = ['options' => []];
            foreach ($filteredProteins as $proteinName) {
                $portionData = $this->calculateProteinPortionByFood($proteinName, $targetProtein, false);
                if ($portionData) {
                    $options['Proteínas']['options'][] = $portionData;
                }
            }

            // CARBOHIDRATOS
            $carbOrderPreference = ['Papa', 'Arroz blanco', 'Camote', 'Fideo', 'Frijoles', 'Quinua'];
            $carbOrderPreference = $this->prioritizeFoodList($carbOrderPreference, $foodPreferences['carbs'] ?? []);
            $selectedCarbs = $this->getFilteredFoodOptions($carbOrderPreference, $dislikedFoods, $allergies, 6);
            $selectedCarbs = $this->applyFoodPreferenceSystem($selectedCarbs, 'Almuerzo-Carbos', '', 6);

            $options['Carbohidratos'] = ['options' => []];
            foreach ($selectedCarbs as $foodName) {
                $portionData = $this->calculateCarbPortionByFood($foodName, $targetCarbs);
                if ($portionData) {
                    $options['Carbohidratos']['options'][] = $portionData;
                }
            }

            // GRASAS
            $fatOptions = ['Aceite de oliva extra virgen', 'Almendras', 'Nueces', 'Aguacate hass'];
            $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
            $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
            $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, 'Almuerzo-Grasas', '', 3);

            $options['Grasas'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                $portionData = $this->calculateFatPortionByFood($fatName, $targetFats, false);
                if ($portionData) {
                    $options['Grasas']['options'][] = $portionData;
                }
            }

        } else { // Cena
            // PROTEÍNAS
            $proteinOptions = [
                'Pescado blanco',
                'Pechuga de pavo',
                'Claras + Huevo entero',
                'Pechuga de pollo',
                'Atún en lata',
                'Carne de res magra'
            ];
            $proteinOptions = $this->filterAllergens($proteinOptions, $allergies);

            // ⭐ CORRECCIÓN AQUÍ
            $forcedFavorites = $this->getForcedFavoritesForMeal('Cena', 'Proteínas', $foodPreferences['proteins'] ?? []);
            $proteinOptions = $this->ensureForcedFavoritesInList($proteinOptions, $forcedFavorites, $allergiesNorm, $dislikedNorm);

            $proteinOptions = $this->prioritizeFoodList($proteinOptions, $foodPreferences['proteins'] ?? []);
            $filteredProteins = $this->getFilteredFoodOptions($proteinOptions, $dislikedFoods, $allergies, 3);

            $options['Proteínas'] = ['options' => []];
            foreach ($filteredProteins as $proteinName) {
                $portionData = $this->calculateProteinPortionByFood($proteinName, $targetProtein, false);
                if ($portionData) {
                    $options['Proteínas']['options'][] = $portionData;
                }
            }

            // CARBOHIDRATOS
            $carbOptions = ['Arroz blanco', 'Quinua', 'Frijoles'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            $options['Carbohidratos'] = ['options' => []];
            foreach ($filteredCarbs as $carbName) {
                $portionData = $this->calculateCarbPortionByFood($carbName, $targetCarbs);
                if ($portionData) {
                    $options['Carbohidratos']['options'][] = $portionData;
                }
            }

            // GRASAS
            $fatOptions = ['Aceite de oliva extra virgen', 'Almendras', 'Nueces'];
            $fatOptions = $this->prioritizeFoodList($fatOptions, $foodPreferences['fats'] ?? []);
            $fatOptions = $this->getFilteredFoodOptions($fatOptions, $dislikedFoods, $allergies, count($fatOptions));
            $filteredFats = $this->applyFoodPreferenceSystem($fatOptions, "{$mealName}-Grasas", '', 3);

            $options['Grasas'] = ['options' => []];
            foreach ($filteredFats as $fatName) {
                $portionData = $this->calculateFatPortionByFood($fatName, $targetFats, false);
                if ($portionData) {
                    $options['Grasas']['options'][] = $portionData;
                }
            }
        }
    }

    // Agregar metadata a todas las opciones
    foreach ($options as $category => &$categoryData) {
        if (isset($categoryData['options'])) {
            foreach ($categoryData['options'] as &$option) {
                $this->addFoodMetadata($option, $isLowBudget);
            }
        }
    }

    return $options;
}




    /**
     * NUEVO: Calcular porción dinámica según alimento con peso COCIDO
     */

    /**
 * Priorizar lista de alimentos según favoritos del usuario
 * Versión simplificada para listas pequeñas (3-6 items)
 */
private function prioritizeFoodList(array $foodList, array $favoriteNames): array
{
    if (empty($favoriteNames)) {
        return $foodList;
    }
    $favorites = [];
    $others = [];
    $favoriteNorm = array_map(fn($f) => $this->normalizeText($f), $favoriteNames);
    foreach ($foodList as $food) {
        $foodNorm = $this->normalizeText($food);
        $isFavorite = false;
        foreach ($favoriteNorm as $favNorm) {
            if (str_contains($foodNorm, $favNorm) || str_contains($favNorm, $foodNorm) || $this->isSimilarProtein($foodNorm, $favNorm)) {
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
    Log::info('Priorización completada', ['total' => count($foodList), 'favorites_found' => count($favorites)]);
    return array_merge(array_unique($favorites), $others);
}


// ⭐ NUEVO MÉTODO AUXILIAR
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




private function calculateCarbPortionByFood($foodName, $targetCarbs): ?array
{
    // DATOS NUTRICIONALES por 100g COCIDOS (excepto avena y crema de arroz)
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

        // EXCEPCIONES (se pesan en CRUDO):
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

    // Formatear porción según tipo de alimento
    if (isset($nutrition['unit']) && isset($nutrition['unit_weight'])) {
        // Alimentos por unidades (pan, tortillas, galletas)
        $units = round($gramsNeeded / $nutrition['unit_weight']);
        if ($units < 1) $units = 1;

        $portion = "{$units} " . ($units == 1 ? $nutrition['unit'] : $nutrition['unit'] . 's');

        // Recalcular con unidades exactas
        $gramsNeeded = $units * $nutrition['unit_weight'];
        $calories = ($gramsNeeded / 100) * $nutrition['calories'];
        $protein = ($gramsNeeded / 100) * $nutrition['protein'];
        $fats = ($gramsNeeded / 100) * $nutrition['fats'];
        $actualCarbs = ($gramsNeeded / 100) * $nutrition['carbs'];

    } else {
        // Etiqueta correcta según si es crudo o cocido
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
        'is_raw_weight' => $nutrition['weigh_raw'] // Para validaciones
    ];
}
    /**
     * OPCIONES PARA VEGETARIANOS - Completamente dinámico
     */

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
            // PROTEÍNAS
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

            // CARBOHIDRATOS
            $carbOptions = $isLowBudget
                ? ['Avena', 'Pan integral', 'Tortilla de maíz']
                : ['Avena orgánica', 'Pan integral artesanal', 'Quinua'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredCarbs)) {
                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }
            }

        } elseif ($mealName === 'Almuerzo') {
            // PROTEÍNAS
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

            // CARBOHIDRATOS
            $carbOptions = ['Papa', 'Arroz blanco', 'Camote', 'Pasta integral', 'Quinua'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 5);

            if (!empty($filteredCarbs)) {
                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }
            }

        } else { // Cena
            // PROTEÍNAS
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

            // CARBOHIDRATOS
            $carbOptions = ['Arroz blanco', 'Quinua', 'Frijoles'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredCarbs)) {
                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }
            }
        }

        // GRASAS (para todas las comidas)
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
                $portionData = $this->calculateFatPortionByFood($fatName, $targetFats, $isLowBudget);
                if ($portionData) {
                    $options['Grasas']['options'][] = $portionData;
                }
            }
        }

        // Agregar metadata
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
            // PROTEÍNAS VEGANAS
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

            // CARBOHIDRATOS
            $carbOptions = ['Avena tradicional', 'Pan integral', 'Quinua cocida'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredCarbs)) {
                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }
            }

        } elseif ($mealName === 'Almuerzo' || $mealName === 'Cena') {
            // PROTEÍNAS
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

            // CARBOHIDRATOS
            $carbOptions = ['Arroz blanco', 'Papa', 'Quinua'];
            $carbOptions = $this->prioritizeFoodList($carbOptions, $foodPreferences['carbs'] ?? []);
            $filteredCarbs = $this->getFilteredFoodOptions($carbOptions, $dislikedFoods, $allergies, 3);

            if (!empty($filteredCarbs)) {
                $options['Carbohidratos'] = ['options' => []];
                foreach ($filteredCarbs as $carbName) {
                    $portionData = $this->calculateCarbPortionByFood($carbName, $targetCarbs);
                    if ($portionData) {
                        $options['Carbohidratos']['options'][] = $portionData;
                    }
                }
            }
        }

        // GRASAS VEGANAS (para todas las comidas)
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
                $portionData = $this->calculateFatPortionByFood($fatName, $targetFats, $isLowBudget);
                if ($portionData) {
                    $options['Grasas']['options'][] = $portionData;
                }
            }
        }

        // Agregar metadata
        foreach ($options as $category => &$categoryData) {
            if (isset($categoryData['options'])) {
                foreach ($categoryData['options'] as &$option) {
                    $this->addFoodMetadata($option, $isLowBudget);
                }
            }
        }

        return $options;
    }


    private function getDetailedBudgetInstructions($budget, $country): string
    {
        $budgetLevel = strtolower($budget);

        if (str_contains($budgetLevel, 'bajo')) {
            $baseInstructions = "**PRESUPUESTO BAJO - ALIMENTOS OBLIGATORIOS:**

            **PROTEÍNAS ECONÓMICAS:**
            - Huevo entero (siempre disponible y económico)
            - Carne molida (en lugar de cortes premium)
            - Pollo (muslos/encuentros, NO pechuga)
            - Pescado económico local (bonito, jurel, caballa - NO salmón)
            - Atún en lata (opción práctica)
            - Legumbres: lentejas, frijoles, garbanzos

            **CARBOHIDRATOS BÁSICOS:**
            - Arroz blanco (base alimentaria)
            - Fideos/pasta común (opción económica)
            - Papa (tubérculo básico)
            - Camote (alternativa nutritiva)
            - Avena tradicional (no instantánea)
            - Pan de molde común

            **GRASAS ACCESIBLES:**
            - Aceite vegetal común (NO aceite de oliva extra virgen)
            - Maní (en lugar de almendras)
            - Aguacate pequeño (cuando esté en temporada)

            **PROHIBIDO EN PRESUPUESTO BAJO:**
            Salmón, lomo de res, pechuga de pollo, almendras, nueces, frutos rojos, quinua importada, yogur griego, quesos premium, aceite de oliva extra virgen, proteína en polvo";
        } else {
            $baseInstructions = "**PRESUPUESTO ALTO - ALIMENTOS PREMIUM:**

            **PROTEÍNAS PREMIUM:**
            - Salmón fresco (en lugar de pescado básico)
            - Lomo de res (en lugar de carne molida)
            - Pechuga de pollo (corte premium)
            - Pescados finos (corvina, lenguado, róbalo)
            - Proteína en polvo (suplementación)
            - Yogur griego (alta proteína)
            - Quesos finos y madurados

            **CARBOHIDRATOS GOURMET:**
            - Quinua (superfood andino)
            - Avena orgánica
            - arroz blanco/basmati
            - Camote morado
            - Pan artesanal/integral premium
            - Pasta integral o de legumbres

            **GRASAS PREMIUM:**
            - Aceite de oliva extra virgen
            - Almendras, nueces, pistachos
            - Aguacate hass grande
            - Aceite de coco orgánico
            - Semillas premium (chía, linaza)

            **FRUTAS GOURMET:**
            - Frutos rojos (arándanos, frambuesas)
            - Frutas importadas de calidad
            - Frutas orgánicas
            - Superfoods (açaí, goji)";
        }

        return $baseInstructions;
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

        $budgetFoodMatrix = [
            'bajo' => [
                'proteinas' => 'Huevo entero, Atún en lata, Pechuga de pollo, Queso fresco, Pescado bonito, Carne molida común',
                'carbohidratos' => 'Quinua, Lentejas, Frejoles, Camote, Papa, Arroz blanco, Fideos, Avena, Tortilla de maíz, Pan integral',
                'grasas' => 'Maní, Mantequilla de maní casera, Semillas de ajonjolí, Aceitunas, Aceite de oliva'
            ],
            'alto' => [
                'proteinas' => 'Claras + Huevo Entero, Proteína en polvo (whey), Yogurt griego alto en proteínas, Pechuga de pollo premium, Pechuga de pavo, Carne de res magra, Salmón fresco, Lenguado fresco',
                'carbohidratos' => 'Quinua, Lentejas, Frejoles, Camote, Papa, Arroz blanco, Fideos, Avena, Tortilla de maíz, Pan integral',
                'grasas' => 'Aceite de oliva extra virgen, Aceite de palta, Palta (aguacate Hass), Almendras, Nueces, Pistachos, Pecanas, Semillas de chía orgánicas, Linaza orgánica'
            ]
        ];

        $budgetLevel = str_contains($budgetLower, 'bajo') ? 'bajo' : 'alto';
        $foods = $budgetFoodMatrix[$budgetLevel];

        return "**INGREDIENTES ESPECÍFICOS DE " . strtoupper($country) . ":**\nProteínas: {$foods['proteinas']}\nCarbohidratos: {$foods['carbohidratos']}\nGrasas: {$foods['grasas']}";
    }

    private function generatePersonalizedRecipes(array $planData, $profile, $nutritionalData): array
    {

        // Obtener todas las comidas EXCEPTO los snacks de frutas
        $allMeals = array_keys($planData['nutritionPlan']['meals'] ?? []);
        $mealsToSearch = array_filter($allMeals, function ($mealName) {
            return !str_contains(strtolower($mealName), 'snack de frutas') &&
                !str_contains(strtolower($mealName), 'fruta');
        });
        if (empty($mealsToSearch)) {
            return $planData;
        }

        // Extraer y estructurar TODOS los datos del perfil para máxima personalización
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

                // Calcular macros específicos para esta comida
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

                // Generar recetas ultra-personalizadas
          //    $recipes = $this->generateUltraPersonalizedRecipesForMeal(
            //    $mealComponents,
              //  $profileData,
                //$nutritionalData,
                //$mealName,
                //$this->locale  // ← Agrega este parámetro
                //);
            // Generar recetas ultra-personalizadas
// Log para depurar: vemos el idioma recibido y el nombre de la comida
 
$recipes = $this->locale === 'en'
    ? $this->generateUltraPersonalizedRecipes_en($mealComponents, $profileData, $nutritionalData, $mealName)
    : $this->generateUltraPersonalizedRecipes_es($mealComponents, $profileData, $nutritionalData, $mealName);
 

                if (!empty($recipes)) {
                    // Validar cada receta antes de incluirla
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


    private function generateUltraPersonalizedRecipes_en(array $mealComponents, array $profileData, $nutritionalData, $mealName): ?array
{
    $languageName = $this->locale === 'en' ? 'English (US)' : 'Spanish (Latin America)';
    $languageInstruction = "
        🔴 **MANDATORY LANGUAGE - NEVER CHANGE:**
        Generate ALL texts (recipe names, instructions, ingredients, personalized notes, tips) DIRECTLY in {$languageName}.
        DO NOT generate in another language and then translate. Write natively in the requested language from the beginning.
        Use common ingredient names and measurements in {$languageName} (e.g., 'tablespoon' and 'cup' in English, 'cucharada' and 'taza' in Spanish).
    ";

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
        if (str_contains($budget, 'low') || str_contains($budget, 'bajo')) {
            $proteinOptions = ['Whole egg', 'Chicken thigh', 'Canned tuna', 'Beans'];
        } else {
            $proteinOptions = ['Chicken breast', 'Salmon', 'Egg whites + Whole eggs', 'Greek yogurt'];
        }
    }
    if (empty($carbOptions)) {
        $dietStyle = strtolower($profileData['dietary_style']);
        if (str_contains($dietStyle, 'keto')) {
            $carbOptions = ['Green vegetables', 'Cauliflower', 'Broccoli', 'Spinach'];
        } else {
            $carbOptions = ['Rice', 'Quinoa', 'Potato', 'Oats', 'Whole grain bread'];
        }
    }
    if (empty($fatOptions)) {
        $budget = strtolower($profileData['budget']);
        if (str_contains($budget, 'low') || str_contains($budget, 'bajo')) {
            $fatOptions = ['Vegetable oil', 'Peanuts', 'Small avocado'];
        } else {
            $fatOptions = ['Extra virgin olive oil', 'Almonds', 'Hass avocado', 'Walnuts'];
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

    $needsPortable = str_contains(strtolower($profileData['eats_out']), 'almost all') ||
                     str_contains(strtolower($profileData['eats_out']), 'most times');
    $needsQuick = in_array('Prepare the food', $profileData['diet_difficulties']) ||
                  in_array('I don’t have time to cook', $profileData['diet_difficulties']);
    $needsAlternatives = in_array('Know what to eat when I don’t have the plan items', $profileData['diet_difficulties']);

    $communicationTone = '';
    if (str_contains(strtolower($profileData['communication_style']), 'motivational')) {
        $communicationTone = "Use a MOTIVATIONAL and ENERGETIC tone: 'Let's go {$profileData['name']}!', 'This recipe will take you to the next level!'";
    } elseif (str_contains(strtolower($profileData['communication_style']), 'direct')) {
        $communicationTone = "Use a DIRECT and CLEAR tone: No fluff, precise instructions, concrete data.";
    } elseif (str_contains(strtolower($profileData['communication_style']), 'friendly') || str_contains(strtolower($profileData['communication_style']), 'close')) {
        $communicationTone = "Use a FRIENDLY and WARM tone: Like a friend cooking with you.";
    }

    $snackRules = '';
    if ($isSnack) {
        $snackRules = "
    🍎 **CRITICAL SNACK RULES - MUST BE FOLLOWED:**
    ⚠️ THIS IS A SNACK, NOT A FULL MEAL
    **PROHIBITED INGREDIENTS IN SNACKS:**
    - ❌ NEVER use: Meats (chicken, beef, pork, fish)
    - ❌ NEVER use: Complex cooking preparations
    - ❌ NEVER use: More than 5 ingredients
    **ALLOWED INGREDIENTS IN SNACKS:**
    - ✅ Greek yogurt / Protein powder / Casein
    - ✅ Fresh fruits (banana, apple, strawberries, mango)
    - ✅ Cereals (oats, granola, rice cakes)
    - ✅ Nuts (almonds, walnuts, peanuts)
    - ✅ Peanut butter / Honey / Dark chocolate
    **MANDATORY FEATURES:**
    - Preparation: MAXIMUM 10 minutes
    - Ingredients: MAXIMUM 5 ingredients
    - Must be 100% PORTABLE (to take to work)
    - No cooking or minimal cooking (blender/microwave)
    - Calories: EXACTLY {$profileData['meal_target_calories']} kcal (no more than 220)
    **CORRECT SNACK EXAMPLES:**
    ✅ Greek yogurt + granola + strawberries + honey
    ✅ Protein shake + banana + peanut butter
    ✅ Overnight oats with milk + blueberries + almonds
    ✅ Rice cakes + cottage cheese + fruit
    **PROHIBITED SNACK RECIPES EXAMPLES:**
    ❌ Chicken tacos (that's a full meal)
    ❌ Salmon salad (that's a full meal)
    ❌ Beef bowl (that's a full meal)
        ";
    }

    $prompt = "
    You are {$profileData['name']}'s personal chef and nutritionist for years. You know PERFECTLY all their tastes, routines and needs.
{$languageInstruction}
    🔴 **ABSOLUTE RESTRICTIONS - NEVER VIOLATE:**
    " . (!empty($dislikedFoodsList) ?
"- PROHIBITED to use these foods they DON'T like: " . implode(', ', $dislikedFoodsList) :
"- No foods to avoid due to preference") . "
    " . (!empty($allergiesList) ?
"- DEADLY ALLERGIES (NEVER include): " . implode(', ', $allergiesList) :
"- No reported allergies") . "
    " . (!empty($profileData['medical_condition']) ?
"- Medical condition to consider: {$profileData['medical_condition']}" :
"- No special medical conditions") . "
    📊 **COMPLETE PROFILE OF {$profileData['name']}:**
    - Age: {$profileData['age']} years, Sex: {$profileData['sex']}
    - Weight: {$profileData['weight']}kg, Height: {$profileData['height']}cm, BMI: " . round($profileData['bmi'], 1) . "
    - Fitness status: {$profileData['weight_status']}
    - Country: {$profileData['country']} (use locally available ingredients)
    - Main goal: {$profileData['goal']}
    - Weekly activity: {$profileData['weekly_activity']}
    - Sports they practice: " . (!empty($profileData['sports']) ? implode(', ', $profileData['sports']) : 'None specific') . "
    - Dietary style: {$profileData['dietary_style']}
    - Budget: {$profileData['budget']}
    - Eats out: {$profileData['eats_out']}
    - Meal structure: {$profileData['meal_count']}
    - Specific time for {$mealName}: " . $this->getMealTiming($mealName, $profileData['meal_times']) . "
    🎯 **NUTRITIONAL TARGETS FOR THIS {$mealName}:**
    - Target calories: {$profileData['meal_target_calories']} kcal
    - Target protein: {$profileData['meal_target_protein']}g
    - Target carbs: {$profileData['meal_target_carbs']}g
    - Target fats: {$profileData['meal_target_fats']}g
    💪 **SPECIFIC DIFFICULTIES TO SOLVE:**
    " . (!empty($profileData['diet_difficulties']) ?
implode("\n", array_map(fn($d) => "- {$d} → Propose specific solution", $profileData['diet_difficulties'])) :
"- No specific difficulties reported") . "
    🌟 **MOTIVATIONS TO REINFORCE:**
    " . (!empty($profileData['diet_motivations']) ?
implode("\n", array_map(fn($m) => "- {$m} → Connect the recipe with this motivation", $profileData['diet_motivations'])) :
"- General health motivation") . "
    🛒 **BASE INGREDIENTS AVAILABLE FOR {$profileData['name']}:**
    - Proteins: {$proteinString}
    - Carbohydrates: {$carbString}
    - Fats: {$fatString}
    {$snackRules}
    📋 **SPECIAL GENERATION RULES:**
    " . ($needsPortable ? "- INCLUDE at least 1 PORTABLE recipe to take to work/eat out" : "") . "
    " . ($needsQuick ? "- Recipes must be FAST (maximum 20 minutes)" : "") . "
    " . ($needsAlternatives ? "- PROVIDE ALTERNATIVES for each main ingredient" : "") . "
    " . (str_contains(strtolower($profileData['dietary_style']), 'keto') ?
"- STRICT KETO: Maximum 5g net carbs per recipe" : "") . "
    " . (str_contains(strtolower($profileData['dietary_style']), 'vegan') ?
"- VEGAN: Only plant-based ingredients" : "") . "
    " . (str_contains(strtolower($profileData['dietary_style']), 'vegetarian') ?
"- VEGETARIAN: No meat or fish" : "") . "
    {$communicationTone}
    **MANDATORY JSON STRUCTURE:**
    Generate EXACTLY 3 DIFFERENT and CREATIVE recipes that {$profileData['name']} would love:
```json
    {
      \"recipes\": [
        {
          \"name\": \"Creative name in {$languageName}, authentic from {$profileData['country']}\",
          \"personalizedNote\": \"PERSONAL note for {$profileData['name']} explaining why this recipe is PERFECT for him/her, mentioning the goal '{$profileData['goal']}' and their motivations\",
          \"instructions\": \"Step 1: [Clear and specific instruction]\\nStep 2: [Next step]\\nStep 3: [Finishing]\\nPersonal tip: [Specific advice for {$profileData['name']}]\",
          \"readyInMinutes\": " . ($isSnack ? "10" : "20") . ",
          \"servings\": 1,
          \"calories\": {$profileData['meal_target_calories']},
          \"protein\": {$profileData['meal_target_protein']},
          \"carbs\": {$profileData['meal_target_carbs']},
          \"fats\": {$profileData['meal_target_fats']},
          \"extendedIngredients\": [
            {
              \"name\": \"main ingredient\",
              \"original\": \"specific amount (weight/measure)\",
              \"localName\": \"Local name in {$profileData['country']}\",
              \"alternatives\": \"Alternatives if not available\"
            }
          ],
          \"cuisineType\": \"{$profileData['country']}\",
          \"difficultyLevel\": \"Easy/Intermediate/Advanced\",
          \"goalAlignment\": \"Specific explanation of how this recipe helps with: {$profileData['goal']}\",
          \"sportsSupport\": \"How it supports training in: " . implode(', ', $profileData['sports']) . "\",
          \"portableOption\": " . ($needsPortable || $isSnack ? "true" : "false") . ",
          \"quickRecipe\": " . ($needsQuick || $isSnack ? "true" : "false") . ",
          \"dietCompliance\": \"Compliant with {$profileData['dietary_style']} diet\",
          \"specialTips\": \"Tips to overcome: " . implode(', ', array_slice($profileData['diet_difficulties'], 0, 2)) . "\"
        }
      ]
    }

            IMPORTANT:

        - The 3 recipes must be VERY different from each other
        - NEVER use ingredients from the prohibited lists
        - Macros must be exact or very close to targets
        - Use creative and appetizing recipe names in English
        - Instructions must be clear and easy to follow
        - Mention {$profileData['name']} by name in personalized notes
        " . ($isSnack ? "\n⚠️ REMEMBER: This is a SNACK, not a full meal. ONLY simple ingredients, NO meats." : "") . "
        ";
        
   try {
    $response = Http::withToken(env('OPENAI_API_KEY'))
        ->timeout(150)
        ->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are an expert personalized chef nutritionist.'
                        . ($isSnack
                            ? ' You specialize in creating simple, portable SNACKS, NEVER use meats in snacks.'
                            : '')
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.6,
            'max_tokens' => 4000
        ]);

    if ($response->successful()) {
        $data = json_decode(
            $response->json('choices.0.message.content'),
            true
        );

        if (
            json_last_error() === JSON_ERROR_NONE &&
            isset($data['recipes']) &&
            is_array($data['recipes'])
        ) {
            $processedRecipes = [];

            foreach ($data['recipes'] as $recipeData) {
                /* =========================
                 * SNACK VALIDATION
                 * ========================= */
                if ($isSnack) {
                    $hasProhibitedIngredient = false;
                    $prohibitedInSnacks = [
                        'chicken',
                        'beef',
                        'pork',
                        'fish',
                        'salmon',
                        'tuna fresh',
                        'turkey'
                    ];

                    foreach ($recipeData['extendedIngredients'] ?? [] as $ingredient) {
                        $ingredientName = strtolower($ingredient['name'] ?? '');
                        foreach ($prohibitedInSnacks as $prohibited) {
                            if (str_contains($ingredientName, $prohibited)) {
                                $hasProhibitedIngredient = true;
                                Log::warning(
                                    'Snack rejected due to prohibited ingredient',
                                    [
                                        'recipe' => $recipeData['name'] ?? 'No name',
                                        'ingredient' => $ingredient['name'],
                                        'prohibited' => $prohibited
                                    ]
                                );
                                break 2;
                            }
                        }
                    }

                    if ($hasProhibitedIngredient) {
                        continue;
                    }
                }

                /* =========================
                 * ENRICH RECIPE DATA
                 * ========================= */
                $recipeData['image'] = null;
                $recipeData['analyzedInstructions'] =
                    $this->parseInstructionsToSteps(
                        $recipeData['instructions'] ?? ''
                    );
                $recipeData['personalizedFor'] = $profileData['name'];
                $recipeData['mealType'] = $mealName;
                $recipeData['generatedAt'] = now()->toIso8601String();
                $recipeData['profileGoal'] = $profileData['goal'];
                $recipeData['budgetLevel'] = $profileData['budget'];

                /* =========================
                 * FINAL VALIDATION
                 * ========================= */
                if ($this->validateRecipeIngredients($recipeData, $profileData)) {
                    $processedRecipes[] = $recipeData;
                } else {
                    Log::warning(
                        'Generated recipe rejected due to prohibited ingredients',
                        [
                            'recipe_name' => $recipeData['name'] ?? 'No name'
                        ]
                    );
                }
            }

            return $processedRecipes;
        }
    }

    Log::error('Error generating personalized recipes', [
        'status' => $response->status(),
        'response' => $response->body(),
        'meal' => $mealName,
        'user' => $profileData['name']
    ]);
} catch (\Exception $e) {
    Log::error('Exception generating recipes', [
        'error' => $e->getMessage(),
        'meal' => $mealName,
        'user' => $profileData['name']
    ]);
}

return null;
}


    private function generateUltraPersonalizedRecipes_es(array $mealComponents, 
    array $profileData, 
    $nutritionalData,
    $mealName): ?array

{

        // === Instrucción dinámica de idioma ===
        $languageName = $this->locale === 'en' ? 'inglés (US)' : 'español (Latinoamérica)';
        $languageInstruction = "
        🔴 **IDIOMA OBLIGATORIO - NUNCA CAMBIAR:**
        Genera TODOS los textos (nombres de recetas, instrucciones, ingredientes, notas personalizadas, tips) DIRECTAMENTE en {$languageName}.
        NO generes en otro idioma y luego traduzcas. Escribe nativamente en el idioma solicitado desde el principio.
        Usa nombres de ingredientes y medidas comunes en {$languageName} (ej: 'tablespoon' y 'cup' en inglés, 'cucharada' y 'taza' en español).
        ";
    // Extraer opciones de alimentos disponibles de los componentes de la comida
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

    // Si no hay opciones, usar defaults basados en presupuesto
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

    // Preparar listas de restricciones
    $dislikedFoodsList = !empty($profileData['disliked_foods'])
        ? array_map('trim', explode(',', $profileData['disliked_foods']))
        : [];

    $allergiesList = !empty($profileData['allergies'])
        ? array_map('trim', explode(',', $profileData['allergies']))
        : [];

    // ✅ NUEVO: Detectar si es un SNACK
    $isSnack = str_contains(strtolower($mealName), 'snack');

    // Determinar características especiales según el contexto
    $needsPortable = str_contains(strtolower($profileData['eats_out']), 'casi todos') ||
        str_contains(strtolower($profileData['eats_out']), 'veces');

    $needsQuick = in_array('Preparar la comida', $profileData['diet_difficulties']) ||
        in_array('No tengo tiempo para cocinar', $profileData['diet_difficulties']);

    $needsAlternatives = in_array('Saber qué comer cuando no tengo lo del plan', $profileData['diet_difficulties']);

    // Determinar estilo de comunicación
    $communicationTone = '';
    if (str_contains(strtolower($profileData['communication_style']), 'motivadora')) {
        $communicationTone = "Usa un tono MOTIVADOR y ENERGÉTICO: '¡Vamos {$profileData['name']}!', '¡Esta receta te llevará al siguiente nivel!'";
    } elseif (str_contains(strtolower($profileData['communication_style']), 'directa')) {
        $communicationTone = "Usa un tono DIRECTO y CLARO: Sin rodeos, instrucciones precisas, datos concretos.";
    } elseif (str_contains(strtolower($profileData['communication_style']), 'cercana')) {
        $communicationTone = "Usa un tono CERCANO y AMIGABLE: Como un amigo cocinando contigo.";
    }

    // ✅ NUEVO: Reglas especiales para SNACKS
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
{$languageInstruction}
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
```json
    {
      \"recipes\": [
        {
          \"name\": \"Nombre creativo en {$languageName}, auténtico de {$profileData['country']}\",
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
```

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
                    // ✅ NUEVO: Validación adicional para snacks
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
                            continue; // Saltar esta receta
                        }
                    }

                    // Enriquecer cada receta con metadatos adicionales
                    $recipeData['image'] = null;
                    $recipeData['analyzedInstructions'] = $this->parseInstructionsToSteps($recipeData['instructions'] ?? '');
                    $recipeData['personalizedFor'] = $profileData['name'];
                    $recipeData['mealType'] = $mealName;
                    $recipeData['generatedAt'] = now()->toIso8601String();
                    $recipeData['profileGoal'] = $profileData['goal'];
                    $recipeData['budgetLevel'] = $profileData['budget'];

                    // Validar que la receta cumple con las restricciones
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
    // Preparar listas de restricciones
    $dislikedFoods = !empty($profileData['disliked_foods'])
        ? array_map(fn($f) => trim(strtolower($f)), explode(',', $profileData['disliked_foods']))
        : [];

    $allergies = !empty($profileData['allergies'])
        ? array_map(fn($a) => trim(strtolower($a)), explode(',', $profileData['allergies']))
        : [];

    // Validar cada ingrediente
    foreach ($recipe['extendedIngredients'] ?? [] as $ingredient) {
        $ingredientName = strtolower($ingredient['name'] ?? '');
        $localName = strtolower($ingredient['localName'] ?? '');

        // ✅ NUEVO: Verificar contra alimentos que NO le gustan
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
                return false; // ← RECHAZAR receta
            }
        }

        // Verificar contra alergias (MÁS CRÍTICO)
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

    // Validación adicional según estilo dietético
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


/**
 * Calcular requerimientos de micronutrientes según perfil del usuario
 */
private function calculateMicronutrientTargets($basicData): array
{
    $sex = strtolower($basicData['sex']);
    $age = $basicData['age'];
    $goal = strtolower($basicData['goal']);

    // Requerimientos base según sexo y edad (basado en RDA/DRI)
    $fiberTarget = ($sex === 'masculino') ? 38 : 25; // gramos/día
    $vitaminCTarget = 90; // mg/día
    $vitaminDTarget = 600; // IU/día
    $calciumTarget = ($age > 50) ? 1200 : 1000; // mg/día
    $ironTarget = ($sex === 'masculino') ? 8 : 18; // mg/día (mujeres necesitan más)
    $magnesiumTarget = ($sex === 'masculino') ? 420 : 320; // mg/día
    $potassiumTarget = 3400; // mg/día
    $sodiumMax = 2300; // mg/día (límite máximo)

    // Ajustes según objetivo específico
    if (str_contains($goal, 'bajar grasa')) {
        $fiberTarget += 5; // Más fibra para mayor saciedad
        $potassiumTarget += 500; // Más potasio para metabolismo
    } elseif (str_contains($goal, 'aumentar músculo')) {
        $magnesiumTarget += 100; // Más magnesio para síntesis proteica
        $vitaminDTarget = 800; // Más vitamina D para fuerza muscular
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
                // Remover "Paso X:" del inicio si existe
                $line = preg_replace('/^Paso \d+:\s*/i', '', $line);

                $steps[] = [
                    'number' => $index + 1,
                    'step' => $line
                ];
            }
        }

        return $steps;
    }


    // Helper method para obtener horarios de comida
    private function getMealTiming($mealName, $nutritionalData): string
    {
        $mealTimes = $nutritionalData['basic_data']['meal_times'] ?? [];

        switch ($mealName) {
            case 'Desayuno':
                return $mealTimes['breakfast_time'] ?? '07:00';
            case 'Almuerzo':
                return $mealTimes['lunch_time'] ?? '13:00';
            case 'Cena':
                return $mealTimes['dinner_time'] ?? '20:00';
            case 'Snack Proteico':
                return '16:00'; // Entre almuerzo y cena
            default:
                return '12:00';
        }
    }




    private function getMealSpecificTips($mealName, array $profileData): array
    {
        $tips = [];
        $mealLower = strtolower($mealName);

        // Tips basados en el momento del día
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

        // Tips basados en dificultades específicas
        if (in_array('Controlar los antojos', $profileData['diet_difficulties'])) {
            $tips[] = "Rica en fibra y proteína para mantener saciedad y evitar antojos";
        }

        if (in_array('Preparar la comida', $profileData['diet_difficulties'])) {
            $tips[] = "Puedes preparar el doble y guardar para mañana";
        }

        return $tips;
    }

/**
 * Construir sección del prompt con preferencias de alimentos
 */
private function buildFavoritesPromptSection(array $foodPreferences, string $userName): string
{
    if (empty($foodPreferences['proteins']) &&
        empty($foodPreferences['carbs']) &&
        empty($foodPreferences['fats']) &&
        empty($foodPreferences['fruits'])) {
        return ""; // Sin preferencias, no agregar nada
    }

    $section = "\n\n🌟🌟🌟 **PREFERENCIAS ALIMENTARIAS DE {$userName}** 🌟🌟🌟\n";
    $section .= "{$userName} seleccionó estos alimentos como sus FAVORITOS. DEBES priorizarlos:\n\n";

    if (!empty($foodPreferences['proteins'])) {
        $section .= "✅ **PROTEÍNAS FAVORITAS (PRIORIZAR EN OPCIONES 1-2):**\n";
        $section .= "   " . implode(', ', $foodPreferences['proteins']) . "\n\n";
    }

    if (!empty($foodPreferences['carbs'])) {
        $section .= "✅ **CARBOHIDRATOS FAVORITOS (PRIORIZAR EN OPCIONES 1-2):**\n";
        $section .= "   " . implode(', ', $foodPreferences['carbs']) . "\n\n";
    }

    if (!empty($foodPreferences['fats'])) {
        $section .= "✅ **GRASAS FAVORITAS (PRIORIZAR EN OPCIONES 1-2):**\n";
        $section .= "   " . implode(', ', $foodPreferences['fats']) . "\n\n";
    }

    if (!empty($foodPreferences['fruits'])) {
        $section .= "✅ **FRUTAS FAVORITAS (USAR EN SNACKS):**\n";
        $section .= "   " . implode(', ', $foodPreferences['fruits']) . "\n\n";
    }

    $section .= "⚠️ **REGLA CRÍTICA DE PRIORIZACIÓN:**\n";
    $section .= "- Los alimentos favoritos DEBEN aparecer como PRIMERAS opciones\n";
    $section .= "- Si {$userName} eligió 'Atún' y 'Pollo', entonces:\n";
    $section .= "  ✅ Opción 1: Atún en lata (200g)\n";
    $section .= "  ✅ Opción 2: Pollo pechuga o muslo (180g)\n";
    $section .= "  ✅ Opción 3: Otros alimentos válidos del presupuesto\n";
    $section .= "- Los alimentos NO favoritos pueden aparecer DESPUÉS\n\n";

    return $section;
}


    /**
     * ⭐ NUEVO: Obtener favoritos que DEBEN aparecer en esta comida específica
     */
    private function getForcedFavoritesForMeal(
        string $mealName,
        string $category,
        array $userFavorites
    ): array {
        $forcedFavorites = [];

        // ⭐ REGLAS: Qué favoritos van en qué comidas
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

        // Determinar tipo de comida (normalizar)
        $mealType = 'Desayuno';
        if (str_contains($mealName, 'Almuerzo')) $mealType = 'Almuerzo';
        elseif (str_contains($mealName, 'Cena')) $mealType = 'Cena';
        elseif (str_contains($mealName, 'Snack')) $mealType = 'Snack';

        // Obtener reglas para esta categoría y comida
        $categoryKey = $category === 'Proteínas' ? 'proteins' :
            ($category === 'Carbohidratos' ? 'carbs' : 'fats');

        $allowedKeywords = $mealRules[$categoryKey][$mealType] ?? [];

        // Filtrar favoritos del usuario que aplican a esta comida
        foreach ($userFavorites as $favorite) {
            $favoriteNormalized = $this->normalizeText($favorite);

            // Verificar si este favorito debe ir en esta comida
            foreach ($allowedKeywords as $keyword) {
                $keywordNormalized = $this->normalizeText($keyword);

                if (str_contains($favoriteNormalized, $keywordNormalized) ||
                    str_contains($keywordNormalized, $favoriteNormalized)) {
                    $forcedFavorites[] = $favorite;
                    break;
                }
            }
        }

        return $forcedFavorites;
    } /**
 * ⭐ NUEVO: Asegurar que los favoritos FORZADOS estén en la lista de opciones
 */
private function ensureForcedFavoritesInList(
    array $currentOptions,
    array $forcedFavorites,
    array $allergiesNorm,
    array $dislikedNorm
): array {

    if (empty($forcedFavorites)) {
        return $currentOptions;
    }

    // Mapeo de nombres del usuario → nombres del sistema
    $nameMapping = [
        // Proteínas
        'Yogurt Griego'   => ['Yogurt griego alto en proteínas', 'Yogurt griego'],
        'Caseína'        => ['Caseína', 'Proteína en polvo'],
        'Proteína Whey'  => ['Proteína whey', 'Proteína en polvo'],
        'Pollo'          => ['Pechuga de pollo', 'Pollo muslo'],
        'Atún'           => ['Atún en lata', 'Atún fresco'],
        'Carne'          => ['Carne de res magra', 'Carne molida'],
        'Res'            => ['Carne de res magra', 'Carne molida'],
        'Pavo'           => ['Pechuga de pavo'],
        'Pescado'        => ['Pescado blanco', 'Salmón fresco'],

        // Carbohidratos
        'Papa'           => ['Papa'],
        'Arroz'          => ['Arroz blanco', 'arroz blanco'],
        'Camote'         => ['Camote'],
        'Quinua'         => ['Quinua'],
        'Avena'          => ['Avena orgánica', 'Avena tradicional', 'Avena'],
        'Tortilla'       => ['Tortilla de maíz'],
        'Pan'            => ['Pan integral artesanal', 'Pan integral'],
        'Galletas'       => ['Galletas de arroz'],
        'Fideo'          => ['Fideo'],
        'Pasta'          => ['Pasta integral'],

        // Grasas
        'Aceite De Oliva' => ['Aceite de oliva extra virgen', 'Aceite de oliva'],
        'Aceite De Palta' => ['Aguacate hass', 'Aguacate'],
        'Aguacate'        => ['Aguacate hass', 'Aguacate'],
        'Palta'           => ['Aguacate hass', 'Aguacate'],
        'Nueces'          => ['Nueces'],
        'Almendras'       => ['Almendras'],
        'Chía'            => ['Semillas de chía orgánicas'],
        'Maní'            => ['Maní', 'Mantequilla de maní'],
    ];

    $missingFavoriteNames = [];

    foreach ($forcedFavorites as $favorite) {
        $favNorm = $this->normalizeForComparison($favorite);

        // Buscar si ya está en la lista actual
        $foundInList = false;

        foreach ($currentOptions as $option) {
            // ⭐ FIX: Manejar tanto strings como arrays
            $optionName = is_array($option) ? ($option['name'] ?? '') : $option;
            $optionNormalized = $this->normalizeText($optionName);

            if (
                str_contains($optionNormalized, $favNorm) ||
                str_contains($favNorm, $optionNormalized)
            ) {
                $foundInList = true;
                break;
            }
        }

        // Si NO está, buscar el nombre correcto y agregarlo
        if (!$foundInList) {
            foreach ($nameMapping as $userKey => $systemNames) {
                $userKeyNormalized = $this->normalizeText($userKey);

                if (
                    str_contains($favNorm, $userKeyNormalized) ||
                    str_contains($userKeyNormalized, $favNorm)
                ) {
                    foreach ($systemNames as $systemName) {
                        $systemNormalized = $this->normalizeText($systemName);

                        $isAllergic = !empty($allergiesNorm)
                            && $this->containsAllKeywords(
                                $systemNormalized,
                                implode(' ', $allergiesNorm)
                            );

                        $isDisliked = !empty($dislikedNorm)
                            && $this->containsAllKeywords(
                                $systemNormalized,
                                implode(' ', $dislikedNorm)
                            );

                        if (!$isAllergic && !$isDisliked) {
                            // ⭐ FIX: Solo guardar el nombre, NO agregarlo directamente
                            $missingFavoriteNames[] = $systemName;

                            Log::info('Favorito FORZADO agregado', [
                                'user_favorite' => $favorite,
                                'system_name'  => $systemName,
                            ]);

                            break;
                        }
                    }
                    break;
                }
            }
        }
    }

    // ⭐ FIX: Si currentOptions tiene strings, devolver strings
    // Si tiene arrays, devolver solo los nombres (la llamada se encargará de calcular porciones)
    if (!empty($currentOptions) && is_string($currentOptions[0])) {
        // Caso: lista de nombres (strings)
        return array_merge($missingFavoriteNames, $currentOptions);
    } else {
        // Caso: lista de arrays completos
        // NO podemos crear los arrays completos aquí porque necesitamos
        // calculateProteinPortionByFood, calculateCarbPortionByFood, etc.
        // Solo devolvemos los nombres y dejamos que el código llamador los procese
        return array_merge($missingFavoriteNames, $currentOptions);
    }
}



    private function userHasActiveSubscription(User $user): bool
    {
        return $user->subscription_status === 'active';
    }

    private function addTrialMessage(array $planData, string $userName): array
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
}
