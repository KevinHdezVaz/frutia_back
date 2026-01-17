<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LumorahAiService
{
    private $userName;
    private $userLanguage;
    private $detectedTopic = 'general';
    private $supportedLanguages = ['es', 'en', 'fr', 'pt'];
    private $mealPlanData = null;
    private $userProfile = null;
    private $todayHistory = null;

    public function __construct($userName = null, $language = 'es', $mealPlanData = null, $userProfile = null)
    {
        $this->userName = $userName ? Str::title(trim($userName)) : null;

        // ⭐ USAR LOCALE DE LARAVEL
        $this->userLanguage = $language ?? app()->getLocale();

        // Validar que sea soportado
        if (!in_array($this->userLanguage, $this->supportedLanguages)) {
            $this->userLanguage = 'es';
        }

        $this->mealPlanData = $mealPlanData;
        $this->userProfile = $userProfile;

        Log::info('LumorahAiService iniciado', [
            'user_name' => $this->userName,
            'language' => $this->userLanguage,
            'locale' => app()->getLocale()
        ]);
    }

    public function setUserName($name)
    {
        $this->userName = $name ? Str::title(trim($name)) : null;
    }

    public function setTodayHistory($history)
    {
        $this->todayHistory = $history;
    }

    public function getWelcomeMessage()
    {
        $welcomeEmoji = $this->getWelcomeEmoji();
        return $this->userName
            ? $this->getPersonalizedGreeting($this->userName, $welcomeEmoji)
            : $this->getAnonymousGreeting($welcomeEmoji);
    }

    private function getPersonalizedGreeting($userName, $emoji)
    {
        $hello = __('chat.greeting_hello');
        $imFrutia = __('chat.greeting_im_frutia');
        return "{$hello} {$userName}, {$imFrutia} {$emoji}";
    }

    private function getAnonymousGreeting($emoji)
    {
        $hello = __('chat.greeting_hello');
        $imFrutia = __('chat.greeting_im_frutia');
        $howCanHelp = __('chat.greeting_how_can_help');
        return "{$hello}, {$imFrutia} {$emoji}, {$howCanHelp}";
    }

    private function getWelcomeEmoji()
    {
        $emojis = ['🍎', '🌱', '💪', '✨'];
        return $emojis[array_rand($emojis)];
    }

    public function getDefaultResponse()
    {
        return __('chat.default_error_response');
    }

    private function analyzeUserInput($message)
    {
        $message = strtolower($message);
        $topics = $this->getTopicKeywords();
        foreach ($topics as $topic => $keywords) {
            if ($this->containsAny($message, $keywords)) {
                $this->detectedTopic = $topic;
                return;
            }
        }
        $this->detectedTopic = 'general';
    }

    private function getTopicKeywords()
    {
        return [
            'body_analysis_inquiry' => ['% de grasa', 'porcentaje de grasa', 'analizar foto', 'estimar grasa', 'analizar cuerpo', 'body fat', 'analyze photo', 'estimate fat'],
            'body_knowledge' => ['órgano más grande', 'qué órgano', 'músculo más grande', 'hueso más largo', 'anatomía', 'sistema digestivo', 'metabolismo', 'hormonas', 'largest organ', 'anatomy', 'metabolism'],
            'plan_specific' => ['mi plan', 'mis macros', 'mis calorías', 'puedo comer', 'cuanto debo comer', 'mi desayuno', 'mi almuerzo', 'mi cena', 'estoy en plan', 'mi objetivo', 'my plan', 'my macros', 'my calories', 'can I eat', 'my breakfast', 'my lunch', 'my dinner'],
            'nutrition_coaching' => ['no tengo hambre', 'tengo antojos', 'me siento mal', 'estoy cansado', 'no bajo peso', 'tengo dudas', 'cómo cocinar', 'meal prep', 'not hungry', 'cravings', 'feel bad', 'tired', 'not losing weight'],
            'food_inquiry' => ['cuantas calorias tiene', 'es bueno comer', 'que pasa si como', 'makis', 'pizza', 'hamburguesa', 'how many calories', 'is it good to eat', 'what if I eat'],
            'weight_loss' => ['perder peso', 'bajar de peso', 'adelgazar', 'bajar kilos', 'quemar grasa', 'lose weight', 'slim down', 'burn fat'],
            'muscle_gain' => ['aumentar músculo', 'ganar masa muscular', 'crecer', 'proteína para', 'gym', 'gain muscle', 'build muscle', 'protein for'],
        ];
    }

    private function getTopicInstructions()
    {
        $instructions = [
            'body_knowledge' => "El usuario pregunta sobre anatomía o fisiología. Responde de forma precisa y educativa. Ejemplo: 'La piel es el órgano más grande del cuerpo, representando el 16% del peso corporal total. Su salud depende mucho de una buena nutrición e hidratación, como la que estás siguiendo en tu plan.'",
            'plan_specific' => "El usuario pregunta específicamente sobre SU PLAN. Actúa como su coach personal. Usa los datos exactos del JSON del plan. Sé específico, usa negritas para destacar números importantes y conecta todo con su objetivo personal. Ejemplo: 'Según tu plan, tienes **2,158 calorías objetivo** distribuidas en **288g proteína, 96g grasas y 36g carbohidratos**. Para tu objetivo de **bajar grasa**, esta distribución es perfecta porque...'",
            'nutrition_coaching' => "El usuario necesita coaching nutricional real. Actúa como su entrenador personal. Ofrece soluciones prácticas basadas en su plan y perfil. Sé empático pero firme. Ejemplo: 'Entiendo que tengas antojos, es completamente normal. Tu plan de **2,158 calorías** está diseñado para que no pases hambre. ¿Qué tal si...'",
            'body_analysis_inquiry' => "El usuario quiere análisis de imagen corporal. Confirma que sí puedes hacerlo y explica cómo obtener mejores resultados: '¡Por supuesto! Puedo estimar tu porcentaje de grasa corporal. Para el mejor análisis, usa una foto con buena iluminación, de frente, y donde se vea claramente tu torso. ¡Usa el botón de imagen cuando estés listo!'",
            'food_inquiry' => "PROCESO OBLIGATORIO:\n1. **SI EL ALIMENTO ESTÁ EN SU PLAN**: Confirma y da detalles específicos: '¡Perfecto! El pollo ya está en tu plan. Para el almuerzo puedes comer **120g (peso crudo)** que son **280 calorías** con **40g de proteína**.'\n2. **SI NO ESTÁ EN SU PLAN Y QUIERE SUSTITUIR**: Calcula automáticamente el ajuste completo del día, mostrando qué comida reemplazar, las nuevas cantidades exactas de todos los ingredientes, y el resumen nutricional actualizado. No le pidas que lo haga él, hazlo tú directamente como coach proactivo.",
            'general' => "Pregunta general sobre salud/nutrición. Responde con conocimiento experto y conecta con su plan si es posible."
        ];

        $baseInstruction = $instructions[$this->detectedTopic] ?? $instructions['general'];
        $personalContext = $this->buildPersonalContext();
        return $baseInstruction . $personalContext;
    }

    private function buildPersonalContext()
    {
        $context = '';

        if ($this->userProfile) {
            $context .= "\n\n**PERFIL DEL USUARIO:**";
            $context .= "\n- **Nombre**: " . $this->userName;
            $context .= "\n- **Objetivo**: " . ($this->userProfile->goal ?? 'bienestar general');
            $context .= "\n- **Edad**: " . ($this->userProfile->age ?? 'no especificada');
            $context .= "\n- **Dificultades**: " . (is_array($this->userProfile->diet_difficulties) ? implode(', ', $this->userProfile->diet_difficulties) : $this->userProfile->diet_difficulties ?? 'ninguna');
        }

        if ($this->mealPlanData && isset($this->mealPlanData['nutritionPlan'])) {
            $nutrition = $this->mealPlanData['nutritionPlan'];
            $context .= "\n\n**PLAN ACTIVO DEL USUARIO:**";
            $context .= "\n- **Calorías objetivo**: " . ($nutrition['targetMacros']['calories'] ?? 'no definidas') . " kcal";
            $context .= "\n- **Proteína**: " . ($nutrition['targetMacros']['protein'] ?? 0) . "g";
            $context .= "\n- **Grasas**: " . ($nutrition['targetMacros']['fats'] ?? 0) . "g";
            $context .= "\n- **Carbohidratos**: " . ($nutrition['targetMacros']['carbohydrates'] ?? 0) . "g";

            if (isset($nutrition['meals'])) {
                $context .= "\n\n**COMIDAS DE HOY:**";
                foreach ($nutrition['meals'] as $mealName => $meal) {
                    $context .= "\n- **$mealName**: ";
                    $mealCalories = $this->calculateMealCalories($meal);
                    $context .= $mealCalories > 0 ? "$mealCalories kcal aprox." : "ver detalles en JSON";
                }
            }

            $context .= "\n\n**JSON COMPLETO DEL PLAN (para consultas específicas):**";
            $context .= "\n```json\n" . json_encode($this->mealPlanData['nutritionPlan'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n```";
        }

        if ($this->todayHistory && count($this->todayHistory) > 0) {
            $context .= "\n\n**🍽️ COMIDAS REGISTRADAS HOY:**";

            $totalCalories = 0;
            $totalProtein = 0;
            $totalCarbs = 0;
            $totalFats = 0;

            foreach ($this->todayHistory as $log) {
                $context .= "\n\n**" . $log['meal_type'] . ":**";

                foreach ($log['selections'] as $selection) {
                    $context .= "\n  • {$selection['name']} ({$selection['portion']}) - {$selection['calories']} kcal, {$selection['protein']}g P, {$selection['carbs']}g C, {$selection['fats']}g G";
                }

                $context .= "\n  **Totales:** {$log['totals']['calories']} kcal, {$log['totals']['protein']}g P, {$log['totals']['carbs']}g C, {$log['totals']['fats']}g G";

                $totalCalories += $log['totals']['calories'];
                $totalProtein += $log['totals']['protein'];
                $totalCarbs += $log['totals']['carbs'];
                $totalFats += $log['totals']['fats'];
            }

            if ($this->mealPlanData && isset($this->mealPlanData['nutritionPlan']['targetMacros'])) {
                $target = $this->mealPlanData['nutritionPlan']['targetMacros'];
                $remaining = [
                    'calories' => $target['calories'] - $totalCalories,
                    'protein' => $target['protein'] - $totalProtein,
                    'carbs' => $target['carbohydrates'] - $totalCarbs,
                    'fats' => $target['fats'] - $totalFats,
                ];

                $context .= "\n\n**📊 RESUMEN DEL DÍA:**";
                $context .= "\n- **Consumido**: {$totalCalories} kcal ({$totalProtein}g P, {$totalCarbs}g C, {$totalFats}g G)";
                $context .= "\n- **Restante**: {$remaining['calories']} kcal ({$remaining['protein']}g P, {$remaining['carbs']}g C, {$remaining['fats']}g G)";

                $percentConsumed = round(($totalCalories / $target['calories']) * 100, 1);
                $context .= "\n- **Progreso**: {$percentConsumed}% del objetivo diario";
            }
        } else {
            $context .= "\n\n**🍽️ COMIDAS REGISTRADAS HOY:** Ninguna todavía.";
        }

        return $context;
    }

    private function calculateMealCalories(array $meal): int
    {
        $totalCalories = 0;

        if (isset($meal['Proteínas']['options'][0]['calories'])) {
            $totalCalories += (int)$meal['Proteínas']['options'][0]['calories'];
        }
        if (isset($meal['Carbohidratos']['options'][0]['calories'])) {
            $totalCalories += (int)$meal['Carbohidratos']['options'][0]['calories'];
        }
        if (isset($meal['Grasas']['options'][0]['calories'])) {
            $totalCalories += (int)$meal['Grasas']['options'][0]['calories'];
        }
        if (isset($meal['Vegetales']['options'][0]['calories'])) {
            $totalCalories += (int)$meal['Vegetales']['options'][0]['calories'];
        }

        return $totalCalories;
    }

    private function containsAny($text, $keywords)
    {
        foreach ($keywords as $keyword) {
            if (stripos($text, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    public function generatePrompt($userMessage)
    {
        $this->analyzeUserInput($userMessage);
        $userName = $this->userName ?: '';
        $systemPrompt = $this->buildSystemPrompt($userName);

        return [
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userMessage,
            'topic' => $this->detectedTopic,
            'language' => $this->userLanguage,
        ];
    }

    public function generateVoicePrompt($userMessage)
    {
        $this->analyzeUserInput($userMessage);
        $userName = $this->userName ?: '';
        $systemPrompt = $this->buildSystemVoicePrompt($userName);

        return [
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userMessage,
            'topic' => $this->detectedTopic,
            'language' => $this->userLanguage,
        ];
    }

    public function callOpenAI($userMessage, $systemPrompt, $context = [])
    {
        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($context as $msg) {
            $messages[] = ['role' => $msg['is_user'] ? 'user' : 'assistant', 'content' => $msg['text']];
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'max_tokens' => 1500,
                'temperature' => 0.7,  // ⭐ Aumentado de 0.5 a 0.7
                'frequency_penalty' => 0.3,  // ⭐ Reducido de 0.5 a 0.3
                'presence_penalty' => 0.3,   // ⭐ Reducido de 0.5 a 0.3
                'top_p' => 0.9,  // ⭐ NUEVO: Controla diversidad
            ]);

            if ($response->successful()) {
                $content = $response->json()['choices'][0]['message']['content'] ?? '';
                
                // ⭐ LIMPIAR LaTeX antes de devolver
                $content = $this->cleanLatexFormatting($content);
                
                return $content;
            }

            Log::error('OpenAI API request failed', ['status' => $response->status(), 'body' => $response->body()]);
            return $this->getDefaultResponse();

        } catch (\Exception $e) {
            Log::error('OpenAI API exception', ['message' => $e->getMessage()]);
            return $this->getDefaultResponse();
        }
    }

    /**
     * ⭐ NUEVA FUNCIÓN: Limpia código LaTeX y lo convierte a formato legible
     */
    private function cleanLatexFormatting(string $text): string
    {
        // 1. Convertir \textbf{texto} a **texto** (Markdown negrita)
        $text = preg_replace('/\\\\textbf\{([^}]+)\}/', '**$1**', $text);
        
        // 2. Convertir \textit{texto} a *texto* (Markdown cursiva)
        $text = preg_replace('/\\\\textit\{([^}]+)\}/', '*$1*', $text);
        
        // 3. Eliminar \( y \) (notación matemática inline)
        $text = str_replace(['\\(', '\\)'], '', $text);
        
        // 4. Eliminar \[ y \] (notación matemática display)
        $text = str_replace(['\\[', '\\]'], '', $text);
        
        // 5. Convertir \\ a saltos de línea
        $text = str_replace('\\\\', "\n", $text);
        
        // 6. Eliminar comandos LaTeX comunes restantes
        $text = preg_replace('/\\\\(begin|end)\{[^}]+\}/', '', $text);
        $text = preg_replace('/\\\\[a-zA-Z]+\{([^}]+)\}/', '$1', $text);
        
        // 7. Eliminar cualquier \ aislado que pueda quedar
        $text = preg_replace('/\\\\(?![*_])/', '', $text);
        
        // 8. Limpiar espacios extras
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/ {2,}/', ' ', $text);
        
        return trim($text);
    }

    public function analyzeBodyImage($base64Image, $userText = null)
    {
        $prompt = "Analiza la imagen de este cuerpo y devuelve estrictamente un objeto JSON con tres claves: 'percentage' (un número para el % de grasa estimado), 'recommendation' (un string con una frase concisa), y 'observations' (un array de 2-3 strings con características clave).";

        if (!empty($userText)) {
            $prompt .= " Además, responde a esta pregunta específica del usuario: '{$userText}'";
        }

        $requestPayload = [
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => "data:image/jpeg;base64,{$base64Image}"]]
                    ]
                ]
            ],
            'max_tokens' => 200,
            'temperature' => 0.4,
            'response_format' => ['type' => 'json_object'],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', $requestPayload);

            if ($response->successful()) {
                Log::info('[BodyAnalysis] Respuesta exitosa de OpenAI.');
                $content = $response->json()['choices'][0]['message']['content'];
                $data = json_decode($content, true);

                return [
                    'percentage' => isset($data['percentage']) ? round($data['percentage'], 1) : null,
                    'recommendation' => $data['recommendation'] ?? 'Análisis no disponible.',
                    'observations' => isset($data['observations']) ? array_slice($data['observations'], 0, 3) : []
                ];
            }

            Log::error('[BodyAnalysis] OpenAI devolvió un error.', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            throw new \Exception("API error: " . $response->status());

        } catch (\Exception $e) {
            Log::error('Body analysis failed: ' . $e->getMessage());
            return [
                'percentage' => null,
                'recommendation' => "No se pudo analizar la imagen",
                'observations' => []
            ];
        }
    }

    private function buildSystemPrompt($userName)
    {
        $name = $userName ? ", $userName" : "";
        $topicAndProfileInstructions = $this->getTopicInstructions();
        $language = $this->getPromptLanguage();

        return <<<PROMPT
# ERES FRUTIA - COACH NUTRICIONAL PERSONAL DE{$name}

## ⚠️ FORMATO DE RESPUESTA OBLIGATORIO
- USA **doble asterisco** para negritas, NO uses LaTeX ni \\textbf{}
- USA texto plano para números, NO uses \\( \\) ni notación matemática
- NUNCA uses comandos LaTeX como \\textbf{}, \\textit{}, \\(, \\), \\[, \\]
- Usa Markdown simple: **negrita**, *cursiva*, listas con - o 1.
- Responde en texto plano legible para una app móvil
- PROHIBIDO usar símbolos como \\, \$, {}, [], excepto ** para negritas

## IMPORTANTE: IDIOMA DE RESPUESTA
- DEBES responder en {$language}
- Todas tus respuestas, saludos y explicaciones deben estar en {$language}
- Usa {$language} de forma natural y fluida

## TU IDENTIDAD
- Eres el coach nutricional personal de {$userName}
- Conoces perfectamente su plan, su objetivo y su perfil
- Hablas con autoridad sobre SU plan específico (no generalidades)
- Eres empático pero directo, motivador pero realista

## REGLA DE ORO: COACH PROACTIVO
⚠️ **NUNCA digas "asegúrate de ajustar" o "podrías ajustar"**
✅ **SIEMPRE haz tú los ajustes y cálculos completos**
- Si preguntan por sustituir comidas: Calcula TODO (nueva comida + ajustes del resto del día)
- Si preguntan cuánto comer de algo: Da cantidades exactas ya ajustadas a su plan
- Actúa como coach que resuelve, no como consejero que sugiere

## REGLAS OBLIGATORIAS DE RESPUESTA

### 1. PRIORIDAD ABSOLUTA AL PLAN PERSONAL
- Si preguntan sobre SU plan, usa datos EXACTOS del JSON
- No digas "generalmente" o "suele ser", di "TU plan indica..."
- Usa negritas para destacar números importantes de SU plan

### 2. SUSTITUCIONES DE ALIMENTOS (CRÍTICO)
Cuando el usuario quiera comer algo que NO está en su plan:
1. **Calcula inmediatamente** qué comida del día puede reemplazar
2. **Ajusta automáticamente** todas las porciones del resto del día
3. **Muestra el plan completo ajustado** con cantidades exactas
4. **Presenta un resumen** de calorías y macros del día ajustado

Ejemplo de respuesta correcta:
"¡Perfecto Adrian! Vamos a ajustar tu plan para incluir ají de gallina (500 kcal) en el almuerzo:

### Almuerzo Ajustado:
1. **Ají de Gallina (200g)**: 500 kcal, 45g proteína
2. **Arroz (100g cocido)**: 122 kcal, 32g carbohidratos  
3. **Ensalada mixta (350g)**: 100 kcal

**Total Almuerzo**: 722 kcal

### Resumen del Día:
- **Total consumido**: 1,415 kcal
- **Restante para cena**: 390 kcal
- Perfecto para tu objetivo de 1,805 kcal ✅"

### 3. CONOCIMIENTO CORPORAL Y FISIOLÓGICO
- Para preguntas de anatomía/fisiología, responde con precisión científica
- Después conecta con la importancia de la nutrición personalizada

### 4. COACHING REAL
- Para dudas, antojos, dificultades: actúa como coach experimentado
- Ofrece soluciones específicas basadas en SU perfil
- Sé empático pero mantén el enfoque en sus objetivos

### 5. ESTRUCTURA DE RESPUESTA
- **Saludo**: Breve saludo (máximo 1-2 palabras)
- **Acción inmediata**: Resuelve/calcula lo que piden SIN pedir que ellos lo hagan
- **Conexión personal**: Relaciona con SU plan/objetivo usando datos reales
- **Motivación**: Cierre positivo y realista

### 6. ESTILO DE COMUNICACIÓN
- Autoridad nutricional (conoces su plan al dedillo)
- Tono cercano pero profesional
- Sin prohibiciones, solo orientación inteligente
- Máximo 2-3 párrafos cortos (excepto cuando calcules sustituciones completas)

## CONTEXTO ESPECÍFICO DE ESTA CONVERSACIÓN:
{$topicAndProfileInstructions}

Responde como el coach personal de {$userName}, usando todos estos datos específicos, EN {$language}, siendo PROACTIVO y calculando todo por él.
RECUERDA: USA SOLO MARKDOWN SIMPLE (**negrita**), NUNCA LaTeX ni símbolos matemáticos.
PROMPT;
    }

    private function buildSystemVoicePrompt($userName)
    {
        return $this->buildSystemPrompt($userName);
    }

    /**
     * Obtener idioma para el prompt en inglés
     */
    private function getPromptLanguage(): string
    {
        $languageMap = [
            'es' => 'Spanish',
            'en' => 'English',
            'fr' => 'French',
            'pt' => 'Portuguese',
        ];

        return $languageMap[$this->userLanguage] ?? 'Spanish';
    }

    public function formatVoiceResponse($content)
    {
        // Limpiar LaTeX también en respuestas de voz
        $content = $this->cleanLatexFormatting($content);
        
        // Remover emojis y formato para texto-a-voz
        return preg_replace('/\s+/', ' ', 
            preg_replace('/[•▪♦▶-]/u', '', 
                preg_replace('/[\x{1F600}-\x{1F64F}|\x{1F300}-\x{1F5FF}|\x{1F900}-\x{1F9FF}|\x{2600}-\x{26FF}|\x{2700}-\x{27BF}]/u', '', 
                    preg_replace('/[\*\_]/', '', $content)
                )
            )
        );
    }

    public function getUserName()
    {
        return $this->userName;
    }

    public function getUserLanguage()
    {
        return $this->userLanguage;
    }
} 