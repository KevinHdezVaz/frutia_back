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
    private $userProfile = null; // Propiedad para almacenar el perfil del usuario

    public function __construct($userName = null, $language = 'es', $mealPlanData = null, $userProfile = null)
    {
        $this->userName = $userName ? Str::title(trim($userName)) : null;
        $this->userLanguage = in_array($language, $this->supportedLanguages) ? $language : 'es';
        $this->mealPlanData = $mealPlanData;
        $this->userProfile = $userProfile;
    }
    public function setUserName($name)
    {
        $this->userName = $name ? Str::title(trim($name)) : null;
    }

    public function getWelcomeMessage()
    {
        $welcomeEmoji = $this->getWelcomeEmoji();
        return $this->userName
            ? $this->getPersonalizedGreeting($this->userName, $welcomeEmoji)
            : $this->getAnonymousGreeting($welcomeEmoji);
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
            'food_inquiry' => ['puedo comer', 'cuantas calorias tiene', 'es bueno comer', 'que pasa si como', 'makis', 'pizza', 'hamburguesa', 'tacos', 'sushi', 'helado', 'cerveza', 'vino', 'quinoa'],
            'weight_loss' => ['perder peso', 'bajar de peso', 'adelgazar', 'bajar kilos', 'quemar grasa'],
            'muscle_gain' => ['aumentar músculo', 'ganar masa muscular', 'crecer', 'proteína para', 'gym'],
        ];
    }

    private function getTopicInstructions()
    {
        $instructions = [
            // --- INSTRUCCIÓN MEJORADA ---
            'food_inquiry' => "El usuario pregunta sobre una comida. Tu proceso DEBE ser:
            1.  **VERIFICAR**: Revisa la lista 'Plan de Hoy' que te proporciono. ¿El alimento que menciona el usuario (ej. quinoa, pollo, etc.) está en esa lista?
            2.  **RESPONDER (SI ESTÁ EN EL PLAN)**: Si el alimento está en el plan, tu respuesta DEBE ser afirmativa y específica. Ejemplo: '¡Claro! La quinoa ya es parte de tu cena de hoy. El plan indica una porción de **150g (que son unas 180 kcal)**, lo cual es perfecto para tu cena de **590 kcal**. ¡Asegúrate de disfrutarla con el resto de tus ingredientes!'
            3.  **RESPONDER (SI NO ESTÁ EN EL PLAN)**: Si el alimento no está en el plan, estima sus calorías y compáralas con el presupuesto de la comida más cercana (almuerzo/cena). Ejemplo: 'Los makis no están en tu plan de hoy, pero puedes integrarlos. 8 piezas tienen unas 400 kcal. Como tu cena tiene un presupuesto de **590 kcal**, podrías comerlos en lugar de tu cena planeada. ¡Solo ten cuidado con las salsas!'",
            'general' => "Ofrece consejos generales sobre bienestar. Anima al usuario a ser más específico.",
            // ... otras instrucciones
        ];

        $baseInstruction = $instructions[$this->detectedTopic] ?? $instructions['general'];
        $personalData = '';

        if ($this->userProfile) {
            $personalData = "\n**Perfil del Usuario:**\n- **Objetivo**: " . ($this->userProfile->goal ?? 'bienestar general') . ".";
        }

        if ($this->mealPlanData && isset($this->mealPlanData['nutritionPlan'])) {
            $contextualPlanData = [
                'targetMacros' => $this->mealPlanData['nutritionPlan']['targetMacros'] ?? [],
                'meals' => $this->mealPlanData['nutritionPlan']['meals'] ?? []
            ];
    
            // Convertimos el plan detallado a un string JSON formateado
            $planJson = json_encode($contextualPlanData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
            $planSummary = "\n\n**Resumen del Plan Activo del Usuario (JSON con todos los detalles):**";
            $planSummary .= "\n```json\n" . $planJson . "\n```";
            
            $personalData .= $planSummary;
        }

        return $baseInstruction . ($personalData ? "\n\n" . $personalData : "");
    }

    private function calculateMealCalories(array $mealComponents): int
    {
        $totalCalories = 0;
        foreach ($mealComponents as $component) {
            if (isset($component['options'][0]['calories'])) {
                $totalCalories += (int)$component['options'][0]['calories'];
            }
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
             'max_tokens' => 150, // Limitar longitud
            'temperature' => 0.5, // Menos creatividad, más precisión
            'frequency_penalty' => 0.5, // Reduce repeticiones
            'presence_penalty' => 0.5, // Evita divagaciones
            ]);

            if ($response->successful()) {
                return $response->json()['choices'][0]['message']['content'] ?? '';
            }

            Log::error('OpenAI API request failed', ['status' => $response->status(), 'body' => $response->body()]);
            return $this->getDefaultResponse();

        } catch (\Exception $e) {
            Log::error('OpenAI API exception', ['message' => $e->getMessage()]);
            return $this->getDefaultResponse();
        }
    }

  

   // Reemplaza toda esta función en LumorahAiService.php

private function buildSystemPrompt($userName)
{
    $name = $userName ? ", $userName" : "";
    $topicAndProfileInstructions = $this->getTopicInstructions();
    
    // --- CAMBIO: El prompt del sistema ahora es mucho más estricto ---
    return <<<PROMPT
# ROL Y OBJETIVO
Eres Frutia, un coach nutricional con IA, amigable, experto y motivador. Tu objetivo principal es ayudar al usuario a seguir su plan de alimentación con total precisión.

# REGLAS DE RESPUESTA (OBLIGATORIAS)
1.  **Experto del Plan Específico**: Tu conocimiento se limita EXCLUSIVAMENTE al JSON del 'Resumen del Plan Activo' que te proporciono. TODAS tus respuestas deben basarse en los datos numéricos de ese JSON (**calorías, proteína, carbohidratos, grasas**). **NUNCA uses tu conocimiento general o frases como "generalmente"**. Si el usuario pregunta por los macros de la 'Pechuga de Pollo', debes responder con los valores exactos de proteína y grasa que aparecen en el JSON del plan. Sé directo y afirmativo.
2.  **Personalización Total**: DEBES usar los datos del "Resumen del Plan Activo del Usuario" para dar respuestas específicas. No uses frases condicionales como "podrías considerar". **Afirma los hechos**: "Tu plan indica...", "La porción asignada es...".
3.  **Estructura Clara**:
    -   **Saludo Corto**: "¡Hola{$name}!"
    -   **Validación**: Reconoce su pregunta. "Entiendo que quieres saber sobre la quinoa."
    -   **Respuesta Directa y Personalizada**: Proporciona la información solicitada, conectándola directamente con los datos del plan del usuario. Usa negritas (`**texto**`) para resaltar datos clave.
    -   **Cierre Positivo**: Termina con una frase de ánimo.
4.  **No Prohibir, Guiar**: Nunca prohíbas una comida. Ofrece estrategias para que el usuario tome decisiones informadas basadas en los datos de su plan.
5.  **Ser Conciso**: Limita tus respuestas a 2-3 párrafos cortos.

# CONTEXTO DE LA CONVERSACIÓN ACTUAL
{$topicAndProfileInstructions}

Ahora, responde a la última pregunta del usuario basándote en TODAS estas reglas.
PROMPT;
}

    
    private function buildSystemVoicePrompt($userName)
    {
        return $this->buildSystemPrompt($userName);
    }
    

    public function getDefaultResponse() { /* ... tu código ... */ return "¡Uy! Parece que se me cayó una manzana en el sistema 🍎. Hubo un pequeño error, pero ¿podrías repetirme tu pregunta?"; }
    private function getPersonalizedGreeting($userName, $emoji) { /* ... tu código ... */ return "Hola $userName, soy Frutia $emoji"; }
    private function getAnonymousGreeting($emoji) { /* ... tu código ... */ return "Hola, soy Frutia $emoji, ¿Como puedo ayudarte hoy?" ; }
    private function getWelcomeEmoji() { /* ... tu código ... */ $emojis = ['🍎', '🌱', '💪', '✨']; return $emojis[array_rand($emojis)];}
    public function formatVoiceResponse($content) { /* ... tu código ... */ return preg_replace('/\s+/', ' ', preg_replace('/[•▪♦▶-]/u', '', preg_replace('/[\x{1F600}-\x{1F64F}|\x{1F300}-\x{1F5FF}|\x{1F900}-\x{1F9FF}|\x{2600}-\x{26FF}|\x{2700}-\x{27BF}]/u', '', preg_replace('/[\*\_]/', '', $content))));}
    public function getUserName() { /* ... tu código ... */ return $this->userName;}
    public function getUserLanguage() { /* ... tu código ... */ return $this->userLanguage;}
}