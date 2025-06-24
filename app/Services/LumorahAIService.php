<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// RECOMENDACIÓN: Renombrar la clase y el archivo a FrutiaAIService.php
class LumorahAiService
{
    private $userName;
    private $userLanguage;
    private $detectedTopic = 'general';
    private $supportedLanguages = ['es', 'en', 'fr', 'pt'];

    public function __construct($userName = null, $language = 'es')
    {
        $this->userName = $userName ? Str::title(trim($userName)) : null;
        $this->userLanguage = in_array($language, $this->supportedLanguages) ? $language : 'es';
        Log::info('FrutiaAIService initialized with userName:', ['userName' => $this->userName]);
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
    
    // --- NUEVAS FUNCIONES PARA ANÁLISIS DE TEMA ---

    /**
     * Analiza el mensaje del usuario para detectar un tema específico.
     */
    private function analyzeUserInput($message)
    {
        $message = strtolower($message);
        $topics = $this->getTopicKeywords();

        foreach ($topics as $topic => $keywords) {
            if ($this->containsAny($message, $keywords)) {
                $this->detectedTopic = $topic;
                Log::info('Topic detected:', ['topic' => $topic]);
                return; // Detenerse en el primer tema que coincida
            }
        }

        // Si no se encuentra ningún tema, se queda como 'general'
        $this->detectedTopic = 'general';
    }

    /**
     * Define las palabras clave para cada tema de nutrición.
     */
    private function getTopicKeywords()
    {
        return [
            'weight_loss' => ['perder peso', 'bajar de peso', 'adelgazar', 'bajar kilos', 'quemar grasa'],
            'muscle_gain' => ['aumentar músculo', 'ganar masa muscular', 'crecer', 'proteína para', 'gym'],
            'energy_boost' => ['más energía', 'estoy cansado', 'fatiga', 'sin fuerzas', 'bajón'],
            'healthy_eating' => ['comer más sano', 'comer mejor', 'alimentación saludable', 'dieta balanceada', 'recetas'],
            'cravings' => ['antojos', 'ansiedad por comer', 'ganas de dulce', 'controlar el hambre'],
            'hydration' => ['beber agua', 'hidratación', 'cuánta agua', 'estar hidratado'],
        ];
    }
    
    /**
     * Devuelve las instrucciones específicas para la IA según el tema detectado.
     */
    private function getTopicInstructions()
    {
        $instructions = [
            'general' => "Ofrece consejos generales sobre bienestar y alimentación balanceada. Anima al usuario a ser más específico si lo desea.",
            'weight_loss' => "Enfócate en el déficit calórico sostenible. Sugiere intercambios inteligentes y destaca proteína/fibra para saciedad. Promueve paciencia y constancia.",
            'muscle_gain' => "Habla sobre superávit calórico moderado y alta ingesta de proteínas (ej: 1.6-2.2g/kg). Sugiere fuentes de proteína magra y carbohidratos complejos.",
            'energy_boost' => "Explica energía rápida vs. sostenida (carbohidratos complejos + fibra). Recomienda snacks que combinen fibra, proteína y grasas saludables. Menciona la hidratación.",
            'healthy_eating' => "Ofrece ideas para incorporar más vegetales/frutas. Habla de planificación de comidas y ejemplos de platos balanceados (ej: método del plato de Harvard).",
            'cravings' => "Valida antojos sin juzgar. Explica causas (hábito, nutrientes, deshidratación). Ofrece alternativas saludables y estrategias (ej: esperar 15min, beber agua).",
            'hydration' => "Explica beneficios de la hidratación. Recomienda ingesta general (ej: 2-3L, aclarando que varía). Da trucos para beber más agua (ej: botellas con marcador, sabores naturales)."
        ];

        return $instructions[$this->detectedTopic] ?? $instructions['general'];
    }

    /**
     * Función de ayuda para buscar palabras clave en un texto.
     */
    private function containsAny($text, $keywords)
    {
        foreach ($keywords as $keyword) {
            if (stripos($text, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    // --- FIN DE LAS NUEVAS FUNCIONES ---

    public function generatePrompt($userMessage)
    {
        // Se añade la llamada para analizar el mensaje antes de crear el prompt
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
        // Se añade también aquí para el chat de voz
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
                'max_tokens' => 800,
                'temperature' => 0.6,
                'top_p' => 0.9,
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

    private function buildSystemPrompt($userName)
    {
        $name = $userName ? ", $userName" : "";
        $topicInstructions = $this->getTopicInstructions();

        return <<<PROMPT
# PERSONALIDAD
Eres Frutia, un nutri-coach IA amigable, motivador y positivo. Usas lenguaje sencillo basado en ciencia, como un amig@ expert@. Incluye emojis de 🍎, 🥗, 💧, 💪.

# INSTRUCCIONES CLAVE
1.  **Saludo**: Siempre saluda cálidamente. Usa el nombre si lo tienes. Ej: "¡Hola$name! Qué bueno verte por aquí 🍎".
2.  **Objetivo**: Ayudar a construir una relación sana con la comida y alcanzar objetivos de bienestar.
3.  **Estructura de Respuesta**:
    -   **Validación**: Reconoce la pregunta/sentimiento.
    -   **Información Clara**: Útil y basada en evidencia.
    -   **Consejos Prácticos**: 2-3 consejos claros y accionables (lista/emojis).
    -   **Motivación**: Frase de ánimo.
    -   **Pregunta Abierta**: Para fomentar la conversación.
4.  **Instrucciones por Tema (Tópico: {$this->detectedTopic})**:
    {$topicInstructions}
5.  **DISCLAIMER DE SEGURIDAD (¡MUY IMPORTANTE!)**:
    -   **NO eres un médico**. No diagnostiques ni prescribas para condiciones médicas.
    -   Si se menciona condición médica, DEBE incluir: "Para tu caso específico, lo mejor es que un médico o nutricionista te dé un plan personalizado. Mi consejo es de carácter general."
    -   Enfócate en hábitos sostenibles, no en promesas extremas.

# EJEMPLO DE RESPUESTA IDEAL (Usuario: "estoy sin energía por las tardes")
¡Hola$name! ☀️ Entiendo perfectamente, esa caída de energía por la tarde es súper común. Suele estar relacionada con lo que comemos al mediodía.

Para mantener tu motor funcionando a tope, aquí tienes un par de ideas:
🍎 **Añade proteína y fibra en tu almuerzo**: Un poco de pollo, lentejas o quinoa junto a una buena ensalada ayudan a que la energía se libere más despacio. ¡Adiós al bajón!
💧 **Hidrátate bien**: A veces, la fatiga es solo sed disfrazada. Asegúrate de beber suficiente agua durante el día.
💪 **Elige un snack inteligente**: Si necesitas un empujón, una fruta con un puñado de almendras es mucho mejor que algo azucarado.

Recuerda que cada cuerpo es un mundo, pero estos pequeños cambios suelen hacer una gran diferencia. ¡Vamos a intentarlo!

¿Cuál de estos tips te parece más fácil de aplicar en tu día a día?

**Comienza la conversación ahora:**
PROMPT;
    }
    
    // --- NUEVA FUNCIÓN PARA EL PROMPT DE VOZ ---
    private function buildSystemVoicePrompt($userName)
    {
        $name = $userName ? ", $userName" : "";
        $topicInstructions = $this->getTopicInstructions();

        return <<<PROMPT
Eres Frutia, un nutri-coach personal con IA. Tu tono es amigable, motivador y positivo. Hablas claro y sencillo, como un amig@ expert@.

Tu meta es ayudar al usuario a tener una relación más sana con la comida.

1.  **Saludo**: Siempre saluda cálidamente. Si conoces el nombre, úsalo.
2.  **Respuesta**:
    -   Valida la pregunta del usuario.
    -   Da información útil y basada en evidencia.
    -   Ofrece 2-3 consejos prácticos.
    -   Cierra con motivación y una pregunta abierta.
3.  **Instrucciones por Tema (Tópico: {$this->detectedTopic})**:
    {$topicInstructions}
4.  **IMPORTANTE (DISCLAIMER)**:
    -   NO eres un médico. No diagnostiques ni prescribas dietas.
    -   Si el usuario menciona una condición médica, siempre recomienda consultar a un médico o nutricionista profesional. Di: "Para tu caso específico, lo mejor es que un médico o nutricionista te dé un plan personalizado. Mi consejo es de carácter general."
    -   Enfócate en hábitos sostenibles.

Comienza la conversación ahora:
PROMPT;
    }

    public function getDefaultResponse() { /* ... tu código ... */ return "¡Uy! Parece que se me cayó una manzana en el sistema 🍎. Hubo un pequeño error, pero ¿podrías repetirme tu pregunta?"; }
    private function getPersonalizedGreeting($userName, $emoji) { /* ... tu código ... */ return "Hola $userName, soy Frutia $emoji"; }
    private function getAnonymousGreeting($emoji) { /* ... tu código ... */ return "Hola, soy Frutia $emoji"; }
    private function getWelcomeEmoji() { /* ... tu código ... */ $emojis = ['🍎', '🌱', '💪', '✨']; return $emojis[array_rand($emojis)];}
    public function formatVoiceResponse($content) { /* ... tu código ... */ return preg_replace('/\s+/', ' ', preg_replace('/[•▪♦▶-]/u', '', preg_replace('/[\x{1F600}-\x{1F64F}|\x{1F300}-\x{1F5FF}|\x{1F900}-\x{1F9FF}|\x{2600}-\x{26FF}|\x{2700}-\x{27BF}]/u', '', preg_replace('/[\*\_]/', '', $content))));}
    public function getUserName() { /* ... tu código ... */ return $this->userName;}
    public function getUserLanguage() { /* ... tu código ... */ return $this->userLanguage;}
}