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
            'body_analysis_inquiry' => ['% de grasa', 'porcentaje de grasa', 'analizar foto', 'estimar grasa', 'analizar cuerpo'],

            'food_inquiry' => ['puedo comer', 'cuantas calorias tiene', 'es bueno comer', 'que pasa si como', 'makis', 'pizza', 'hamburguesa', 'tacos', 'sushi', 'helado', 'cerveza', 'vino', 'quinoa'],
            'weight_loss' => ['perder peso', 'bajar de peso', 'adelgazar', 'bajar kilos', 'quemar grasa'],
            'muscle_gain' => ['aumentar músculo', 'ganar masa muscular', 'crecer', 'proteína para', 'gym'],
        ];
    }

    private function getTopicInstructions()
    {
        $instructions = [
            // --- INSTRUCCIÓN MEJORADA ---
            'body_analysis_inquiry' => "El usuario está preguntando si puedes analizar su porcentaje de grasa a partir de una foto. Tu respuesta DEBE ser afirmativa y útil. Anímale a usar la función de análisis de imagen que tiene la app. Explícale brevemente que para obtener el mejor resultado, la foto debe tener buena iluminación, mostrar claramente el torso y ser de frente.
            Ejemplo de respuesta: '¡Claro que sí! Puedo darte una estimación de tu porcentaje de grasa corporal a partir de una foto. Para que el análisis sea lo más preciso posible, asegúrate de subir una foto con buena iluminación, de frente, y donde se vea bien tu torso. ¡Cuando estés listo, usa el botón de adjuntar imagen!'",
         
            'food_inquiry' => "El usuario pregunta sobre una comida. Tu proceso DEBE ser:
            1.  **VERIFICAR**: Revisa la lista 'Plan de Hoy' que te proporciono. ¿El alimento que menciona el usuario (ej. quinoa, pollo, etc.) está en esa lista?
            2.  **RESPONDER (SI ESTÁ EN EL PLAN)**: Si el alimento está en el plan, tu respuesta DEBE ser afirmativa y específica. Ejemplo: '¡Claro! La quinoa ya es parte de tu cena de hoy. El plan indica una porción de **150g (que son unas 180 kcal)**, lo cual es perfecto para tu cena de **590 kcal**. ¡Asegúrate de disfrutarla con el resto de tus ingredientes!'
            3.  **RESPONDER (SI NO ESTÁ EN EL PLAN)**: Si el alimento no está en el plan, estima sus calorías y compáralas con el presupuesto de la comida más cercana (almuerzo/cena). Ejemplo: 'Los makis no están en tu plan de hoy, pero puedes integrarlos. 8 piezas tienen unas 400 kcal. Como tu cena tiene un presupuesto de **590 kcal**, podrías comerlos en lugar de tu cena planeada. ¡Solo ten cuidado con las salsas!'",

'general' => "El usuario está haciendo una pregunta de conocimiento general sobre salud, bienestar o el cuerpo. Responde la pregunta de forma directa y concisa. Luego, si es posible, conecta la respuesta con la importancia de una buena nutrición o el plan del usuario. Ejemplo: Si pregunta por el órgano más grande, responde que es la piel y añade que cuidarla también implica una buena alimentación e hidratación.",            // ... otras instrucciones
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

  
   // En el archivo: app/Services/LumorahAiService.php

   public function analyzeBodyImage($base64Image, $userText = null) // Aceptamos el texto opcional
   {
    
       // Creamos un prompt base
       $prompt = "Analiza la imagen de este cuerpo y devuelve estrictamente un objeto JSON con tres claves: 'percentage' (un número para el % de grasa estimado), 'recommendation' (un string con una frase concisa), y 'observations' (un array de 2-3 strings con características clave).";
   
       // Si el usuario envió un texto, lo añadimos al prompt
       if (!empty($userText)) {
           $prompt .= " Además, responde a esta pregunta específica del usuario: '{$userText}'";
       }
   
       $requestPayload = [
           'model' => 'gpt-4o',
           'messages' => [
               [
                   'role' => 'user',
                   'content' => [
                       [
                           'type' => 'text',
                           'text' => $prompt // Usamos nuestro nuevo prompt dinámico
                       ],
                       [
                           'type' => 'image_url',
                           'image_url' => [
                               'url' => "data:image/jpeg;base64,{$base64Image}"
                           ]
                       ]
                   ]
               ]
           ],
           'max_tokens' => 200, // Aumentamos un poco por si la respuesta es más larga
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

   // Reemplaza toda esta función en LumorahAiService.php

// Reemplaza toda esta función en LumorahAiService.php

private function buildSystemPrompt($userName)
{
    $name = $userName ? ", $userName" : "";
    $topicAndProfileInstructions = $this->getTopicInstructions();
    
    // --- CAMBIO: El prompt del sistema ahora es más flexible e inteligente ---
    return <<<PROMPT
# ROL Y OBJETIVO
Eres Frutia, un coach nutricional con IA, amigable, experto y motivador. Tu objetivo principal es ayudar al usuario a alcanzar sus metas de bienestar, usando su plan de alimentación como la herramienta central.

# REGLAS DE RESPUESTA (OBLIGATORIAS)
1.  **PRIORIDAD MÁXIMA AL PLAN**: Tu base de conocimiento principal es el JSON del 'Resumen del Plan Activo'. Si la pregunta del usuario se puede responder con datos de este JSON (calorías, comidas, macros), DEBES usar esos datos de forma precisa y directa.
2.  **USA TU CONOCIMIENTO GENERAL (CUANDO SEA NECESARIO)**: Si la pregunta del usuario es sobre salud, nutrición o bienestar general y no se puede responder directamente con el JSON (ej: "¿cuál es el órgano más grande?", "¿es bueno el ayuno intermitente?"), DEBES responderla usando tu conocimiento como experto. **Después de responder, intenta conectar la respuesta con los objetivos del usuario si es posible.**
3.  **Personalización Total**: Siempre que sea posible, conecta tus respuestas con los datos del "Resumen del Plan Activo del Usuario" y su objetivo. No uses frases condicionales como "podrías considerar". Afirma los hechos: "Tu plan indica...", "Para tu objetivo de {$this->userProfile->goal}, esto es relevante porque...".
4.  **Estructura Clara**:
    -   **Saludo Corto**: "¡Hola{$name}!" (o una variación amigable).
    -   **Respuesta Directa**: Responde a la pregunta del usuario primero.
    -   **Conexión y Contexto**: Explica cómo se relaciona con su plan u objetivo. Usa negritas (`**texto**`) para resaltar datos clave.
    -   **Cierre Positivo**: Termina con una frase de ánimo.
5.  **No Prohibir, Guiar**: Nunca prohíbas una comida. Ofrece estrategias para que el usuario tome decisiones informadas basadas en los datos de su plan.
6.  **Ser Conciso**: Limita tus respuestas a 2-3 párrafos cortos.

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