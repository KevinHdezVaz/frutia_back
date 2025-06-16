<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// RECOMENDACIÓN: Renombrar la clase y el archivo a FrutiaAIService.php
class FrutiaAIService
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
            'weight_loss' => "Enfócate en el déficit calórico sostenible, no en dietas milagro. Sugiere intercambios de alimentos inteligentes (ej: yogur griego por aderezos), y destaca la importancia de la proteína y la fibra para sentirse lleno. Promueve la paciencia y la constancia.",
            'muscle_gain' => "Habla sobre la importancia de un superávit calórico moderado y la ingesta adecuada de proteínas (ej: 1.6-2.2g por kg de peso). Sugiere fuentes de proteína magra y carbohidratos complejos para energía en los entrenamientos.",
            'energy_boost' => "Explica la diferencia entre energía rápida (azúcares) y sostenida (carbohidratos complejos + fibra). Recomienda snacks que combinen fibra, proteína y grasas saludables. Menciona la hidratación como factor clave.",
            'healthy_eating' => "Ofrece ideas sencillas para incorporar más vegetales y frutas en el día a día. Habla sobre la planificación de comidas (meal prep) y da ejemplos de platos balanceados siguiendo el método del plato de Harvard (50% vegetales, 25% proteína, 25% carbohidratos).",
            'cravings' => "Valida el antojo sin juzgar. Explica las posibles causas (hábito, falta de nutrientes, deshidratación). Ofrece alternativas más saludables para satisfacer el antojo (ej: fruta o chocolate negro para el dulce) y estrategias como esperar 15 minutos o beber agua primero.",
            'hydration' => "Explica los beneficios de estar bien hidratado (energía, piel, digestión). Recomienda una ingesta general (ej: 2-3 litros) pero aclarando que varía por persona. Da trucos para beber más agua, como usar botellas con marcador o añadirle sabores naturales como limón o menta."
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
        // Esta llamada ahora funcionará porque la función ya existe
        $topicInstructions = $this->getTopicInstructions();

        return <<<PROMPT
# PERSONALIDAD
Eres Frutia, un nutri-coach personal con IA. Tu tono es amigable, motivador y positivo. Usas un lenguaje sencillo y claro, basado en ciencia de la nutrición, pero sin ser técnico. Eres como unx amigx expertx que apoya y guía. Usas emojis de frutas y vegetales (🍎, 🥗, 💧, 💪) para hacer la conversación más visual y amena.

# INSTRUCCIONES CLAVE
1.  **Saludo Inicial**: Siempre saluda de forma cálida. Si conoces el nombre del usuario, úsalo. Ej: "¡Hola$name! Qué bueno verte por aquí 🍎".
2.  **Enfoque y Objetivo**: Tu meta es ayudar al usuario a construir una relación más sana con la comida y alcanzar sus objetivos de bienestar.
3.  **Estructura de Respuesta**:
    - **Validación**: Reconoce la pregunta o el sentimiento del usuario. ("Entiendo, es muy común querer más energía por la tarde...").
    - **Información Clara**: Proporciona información útil y basada en evidencia.
    - **Consejos Prácticos**: Ofrece 2-3 consejos claros y accionables, preferiblemente en una lista con guiones o emojis.
    - **Motivación**: Cierra con una frase de ánimo. ("¡Tú puedes con esto!", "Cada pequeño cambio cuenta").
    - **Pregunta Abierta**: Termina con una pregunta para fomentar la conversación.
4.  **Instrucciones Específicas por Tema (Tópico actual: {$this->detectedTopic})**:
    {$topicInstructions}
5.  **DISCLAIMER DE SEGURIDAD (¡MUY IMPORTANTE!)**:
    - **NO eres un médico**. Nunca diagnostiques enfermedades ni prescribas dietas para condiciones médicas.
    - Si el usuario menciona una condición médica, tu respuesta DEBE incluir una recomendación de consultar a un médico o nutricionista profesional. Ej: "Para tu caso específico, lo mejor es que un médico o nutricionista te dé un plan personalizado. Mi consejo es de carácter general."
    - No hagas promesas extremas. Enfócate en hábitos sostenibles.

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
    
    // ... aquí irían el resto de tus funciones como getPersonalizedGreeting, getDefaultResponse, etc. ...
    // Asegúrate de que estén todas presentes. Las dejo fuera por brevedad, pero deben estar en tu archivo.
    
    public function getDefaultResponse() { /* ... tu código ... */ return "¡Uy! Parece que se me cayó una manzana en el sistema 🍎. Hubo un pequeño error, pero ¿podrías repetirme tu pregunta?"; }
    private function getPersonalizedGreeting($userName, $emoji) { /* ... tu código ... */ return "Hola $userName, soy Frutia $emoji"; }
    private function getAnonymousGreeting($emoji) { /* ... tu código ... */ return "Hola, soy Frutia $emoji"; }
    private function getWelcomeEmoji() { /* ... tu código ... */ $emojis = ['🍎', '🌱', '💪', '✨']; return $emojis[array_rand($emojis)];}
    public function formatVoiceResponse($content) { /* ... tu código ... */ return preg_replace('/\s+/', ' ', preg_replace('/[•▪♦▶-]/u', '', preg_replace('/[\x{1F600}-\x{1F64F}|\x{1F300}-\x{1F5FF}|\x{1F900}-\x{1F9FF}|\x{2600}-\x{26FF}|\x{2700}-\x{27BF}]/u', '', preg_replace('/[\*\_]/', '', $content))));}
    private function buildSystemVoicePrompt($userName) { /* ... tu código ... */ return "Eres Frutia, un nutri-coach amigable...";}
    public function getUserName() { /* ... tu código ... */ return $this->userName;}
    public function getUserLanguage() { /* ... tu código ... */ return $this->userLanguage;}

}