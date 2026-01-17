<?php

namespace App\Services\PlanGeneration;

use Illuminate\Support\Facades\Log;

class PromptBuilderService
{
    public function buildUltraPersonalizedPrompt($profile, $nutritionalData, $userName, $attemptNumber = 1): string
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
              \"options\": []
            },
            \"Carbohidratos\": {
              \"options\": []
            },
            \"Grasas\": {
              \"options\": []
            },
            \"Vegetales\": {
              \"options\": [
                {\"name\": \"Ensalada LIBRE\", \"portion\": \"Sin restricción\", \"calories\": 25, \"protein\": 2, \"fats\": 0, \"carbohydrates\": 5}
              ]
            }
          },
          \"Almuerzo\": {},
          \"Cena\": {}
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

    🔴 RECUERDA:
    - Presupuesto {$budgetType} = usar SOLO alimentos de ese presupuesto
    - Los macros DEBEN sumar EXACTAMENTE (usa la fórmula del PASO 2)
    - Calcula bien las porciones antes de responder

    Genera el plan COMPLETO en español para {$preferredName}.
    ";
    }

    private function buildFavoritesPromptSection(array $foodPreferences, string $userName): string
    {
        if (empty($foodPreferences['proteins']) &&
            empty($foodPreferences['carbs']) &&
            empty($foodPreferences['fats']) &&
            empty($foodPreferences['fruits'])) {
            return "";
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
}