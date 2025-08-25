<?php
namespace App\Http\Controllers;

use Pusher\Pusher;
use App\Models\User;
use App\Models\Message;
use App\Models\ChatSession;
use App\Models\MealPlan; // Importar el modelo MealPlan

use Illuminate\Http\Request;
use App\Services\LumorahAIService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
 
class ChatController extends Controller
{
    protected $lumorahService;
    protected $pusher;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->pusher = new Pusher(
            env('PUSHER_APP_KEY'),
            env('PUSHER_APP_SECRET'),
            env('PUSHER_APP_ID'),
            [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'useTLS' => true,
            ]
        );
    }

  
    private function initializeService(Request $request)
    {
        $user = Auth::user();
        $userName = null;
        $mealPlanData = null;
        $userProfile = null;

        if ($user) {
            $user->load('profile');
            
            // --- OPTIMIZACIÓN ---
            // Usamos directamente el objeto $user que ya tenemos.
            // La columna en tu base de datos es 'name', no 'nombre'.
            $userName = $user->name; 
            $userProfile = $user->profile;
            // --- FIN OPTIMIZACIÓN ---

            $mealPlan = MealPlan::where('user_id', $user->id)
                ->where('is_active', true)
                ->latest('created_at')
                ->first();

            // Nos aseguramos de que plan_data sea siempre un array.
            if ($mealPlan && $mealPlan->plan_data) {
                $mealPlanData = is_array($mealPlan->plan_data) 
                    ? $mealPlan->plan_data 
                    : json_decode($mealPlan->plan_data, true);
            }
        }

        if (!$userName && $request->session_id) {
            $session = ChatSession::where('id', $request->session_id)
                ->where('user_id', Auth::id())
                ->first();
            $userName = $session->user_name ?? null;
        }

        $this->lumorahService = new LumorahAIService(
            $userName,
            $userProfile->language ?? 'es',
            $mealPlanData,
            $userProfile
        );
    }
    
    

    protected function generateSessionTitle($message)
    {
        return substr($message, 0, 50) . (strlen($message) > 50 ? '...' : '');
    }

    public function getSessions(Request $request)
    {
        try {
            $query = ChatSession::where('user_id', Auth::id())
                ->orderBy('created_at', 'desc');

            if ($request->query('saved', true)) {
                $query->where('is_saved', true);
            }

            $sessions = $query->get()->map(function ($session) {
                return [
                    'id' => $session->id,
                    'user_id' => $session->user_id,
                    'title' => $session->title,
                    'is_saved' => (bool)$session->is_saved,
                    'created_at' => $session->created_at->toDateTimeString(),
                    'updated_at' => $session->updated_at->toDateTimeString(),
                    'deleted_at' => $session->deleted_at?->toDateTimeString(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $sessions,
                'count' => $sessions->count()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener sesiones: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Error al obtener sesiones',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function processAudio(Request $request)
    {
        Log::info('Solicitud recibida en processAudio', [
            'user_id' => Auth::id(),
            'audio_file' => $request->hasFile('audio') ? $request->file('audio')->getClientOriginalName() : 'No file',
        ]);

        if ($request->hasFile('audio')) {
            $audio = $request->file('audio');
            Log::info('Archivo recibido: ' . $audio->getClientOriginalName());
            Log::info('Tipo MIME detectado por el servidor: ' . $audio->getMimeType());

            $request->validate([
                'audio' => 'required|file|mimetypes:audio/m4a,audio/mp4,audio/aac,audio/wav,audio/mpeg,video/mp4|max:25600',
            ]);

            try {
                $audioPath = $audio->getPathname();
                Log::info('Procesando audio: ' . $audioPath);

                return response()->json([
                    'success' => true,
                    'message' => 'Audio recibido correctamente',
                ], 200);
            } catch (\Exception $e) {
                Log::error('Excepción al procesar audio: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Error al procesar el audio',
                    'message' => $e->getMessage(),
                ], 500);
            }
        }

        return response()->json([
            'success' => false,
            'error' => 'No se proporcionó un archivo de audio',
        ], 400);
    }

    public function deleteSession($sessionId)
    {
        DB::beginTransaction();
        try {
            $session = ChatSession::where('id', $sessionId)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            Message::where('chat_session_id', $sessionId)->delete();
            $session->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Conversación eliminada exitosamente'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al eliminar sesión: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Error al eliminar la conversación',
                'message' => $e->getMessage()
            ], 500);
        }
    }

   
public function saveChatSession(Request $request)
{
    // ▼▼▼ 1. CORREGIR LA VALIDACIÓN ▼▼▼
    $request->validate([
        'title' => 'required|string|max:100',
        'messages' => 'required|array',
        'messages.*.text' => 'nullable|string', // <-- El texto ahora es opcional
        'messages.*.image_url' => 'nullable|string|url', // <-- Nuevo campo para la imagen (opcional)
        'messages.*.is_user' => 'required|boolean',
        // 'messages.*.created_at' ya no es necesario si lo manejamos aquí
        'session_id' => 'nullable|exists:chat_sessions,id',
    ]);

    DB::beginTransaction();
    try {
        $userId = Auth::id();
        $sessionId = $request->session_id;

        if ($sessionId) {
            $session = ChatSession::where('id', $sessionId)
                ->where('user_id', $userId)
                ->firstOrFail();
            $session->update([
                'title' => $request->title,
                'is_saved' => true,
            ]);
        } else {
            $session = ChatSession::create([
                'user_id' => $userId,
                'title' => $request->title,
                'is_saved' => true,
            ]);
        }

        // Borramos los mensajes antiguos para resincronizar el chat completo
        if ($sessionId) {
            Message::where('chat_session_id', $session->id)->delete();
        }

        // ▼▼▼ 2. CORREGIR LA LÓGICA DE GUARDADO ▼▼▼
        foreach ($request->messages as $msg) {
            // Asegurarse de que al menos uno de los dos (texto o imagen) exista
            if (empty($msg['text']) && empty($msg['image_url'])) {
                continue; // Saltar mensajes vacíos
            }

            Message::create([
                'chat_session_id' => $session->id,
                'user_id' => $msg['is_user'] ? $userId : null,
                'text' => $msg['text'],
                'image_url' => $msg['image_url'] ?? null, // <-- Guardamos la URL de la imagen
                'is_user' => $msg['is_user'],
            ]);
        }

        DB::commit();
        return response()->json([
            'success' => true,
            'data' => $session->fresh(), // Devolvemos la sesión actualizada
        ], 201);
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error al guardar sesión: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'error' => 'Error al guardar el chat',
            'message' => $e->getMessage(),
        ], 500);
    }
}

// ▼▼▼ AÑADE ESTA FUNCIÓN COMPLETA A TU CHATCONTROLLER.PHP ▼▼▼
public function uploadImage(Request $request)
{
    $request->validate([
        'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', // Valida que sea una imagen
    ]);

    // Guarda la imagen en 'storage/app/public/chat_images' y devuelve la ruta
    $path = $request->file('image')->store('chat_images', 'public');

    // Devuelve la URL completa y pública del archivo
    return response()->json([
        'success' => true,
        'url' => asset('storage/' . $path)
    ]);
}
// ▲▲▲ FIN DE LA FUNCIÓN A AÑADIR ▲▲▲


    public function getSessionMessages($sessionId)
    {
        try {
            $session = ChatSession::where('id', $sessionId)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $messages = $session->messages()
                ->orderBy('created_at')
                ->get()
                ->map(function ($msg) {
                    return [
                        'id' => $msg->id,
                        'chat_session_id' => $msg->chat_session_id,
                        'user_id' => $msg->user_id,
                        'text' => $msg->text,
                        'image_url' => $msg->image_url, // <-- AÑADE ESTA LÍNEA

                        'is_user' => $msg->is_user,
                        'created_at' => $msg->created_at->toDateTimeString(),
                        'updated_at' => $msg->updated_at->toDateTimeString(),
                       
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $messages,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener mensajes: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Sesión no encontrada',
                'message' => $e->getMessage(),
            ], 404);
        }
    }

   
   

    public function sendMessage(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'session_id' => 'nullable|exists:chat_sessions,id',
            'is_temporary' => 'boolean',
            'user_name' => 'nullable|string|max:100',
        ]);

        // La inicialización del servicio ahora incluirá el plan de alimentación
        $this->initializeService($request);

        try {
            $promptData = $this->lumorahService->generatePrompt($request->message);

            $contextMessages = [];
            if ($request->session_id && !$request->is_temporary) {
                $contextMessages = $this->getConversationContext($request->session_id);
            }

            $aiResponse = $this->lumorahService->callOpenAI($request->message, $promptData['system_prompt'], $contextMessages);

            if (!is_string($aiResponse) || empty($aiResponse)) {
                Log::warning('Respuesta de OpenAI inválida, usando mensaje por defecto.');
                $aiResponse = $this->lumorahService->getDefaultResponse();
            }

            $sessionId = $request->session_id;

            if ($request->is_temporary) {
                return response()->json([
                    'success' => true,
                    'ai_message' => [
                        'text' => $aiResponse,
                        'is_user' => false,
                    ],
                    'session_id' => null,
                ], 200);
            }

            DB::transaction(function () use ($request, $aiResponse, &$sessionId, $promptData) {
                if (!$sessionId) {
                    $sessionId = $this->createNewSession($request->message);
                }

                Message::create([
                    'chat_session_id' => $sessionId,
                    'user_id' => Auth::id(),
                    'text' => $request->message,
                    'is_user' => true,

                ]);

                Message::create([
                    'chat_session_id' => $sessionId,
                    'user_id' => null,
                    'text' => $aiResponse,
                    'is_user' => false,

                ]);


                  // --- UBICACIÓN CORRECTA ---
                // Justo después de guardar los mensajes y antes de terminar la transacción.
                $user = Auth::user();
                if ($user->subscription_status !== 'active') {
                    $user->increment('message_count');
                    Log::info("Incrementado el contador de mensajes para el usuario {$user->id}. Nuevo total: " . ($user->message_count + 1));
                }
                
                $this->pusher->trigger('chat-channel', 'new-message', [
                    'session_id' => $sessionId,
                    'message' => $aiResponse,
                    'is_user' => false,
                ]);
            });

            return response()->json([
                'success' => true,
                'ai_message' => [
                    'text' => $aiResponse,
                    'is_user' => false,

                ],
                'session_id' => $sessionId,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en sendMessage: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Error al procesar el mensaje',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function sendTemporaryMessage(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'user_name' => 'nullable|string',
        ]);

        // La inicialización del servicio ahora incluirá el plan de alimentación
        $this->initializeService($request);

        try {
            $promptData = $this->lumorahService->generatePrompt($request->message);
            $aiResponse = $this->lumorahService->callOpenAI($request->message, $promptData['system_prompt']);

            if (!is_string($aiResponse) || empty($aiResponse)) {
                Log::warning('Respuesta de OpenAI inválida, usando mensaje por defecto.');
                $aiResponse = $this->lumorahService->getDefaultResponse();
            }

            return response()->json([
                'success' => true,
                'ai_message' => [
                    'text' => $aiResponse,
                    'is_user' => false,

                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en sendTemporaryMessage: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Error al procesar el mensaje temporal',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function startNewSession(Request $request)
    {
        Log::info('Starting new session for user request', [
            'user_id' => Auth::id(),
            'user_name' => $request->input('user_name'),
        ]);

        $request->validate([
            'user_name' => 'nullable|string',
        ]);

        // La inicialización del servicio ahora incluirá el plan de alimentación
        $this->initializeService($request);

        try {
            $session = ChatSession::create([
                'user_id' => Auth::id(),
                'title' => 'Nueva conversación',
                'is_saved' => false,
                'language' => 'es',
                'user_name' => $this->lumorahService->getUserName() ?? '',
            ]);

            $welcomeMessage = $this->lumorahService->getWelcomeMessage();

            Log::info('Session created successfully', [
                'session_id' => $session->id,
                'welcome_message' => $welcomeMessage,
            ]);

            return response()->json([
                'success' => true,
                'session_id' => $session->id,
                'ai_message' => [
                    'text' => $welcomeMessage,
                    'is_user' => false,

                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al iniciar sesión: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'stack_trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Error al iniciar la conversación',
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function updateUserName(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
        ]);

        // La inicialización del servicio ahora incluirá el plan de alimentación
        $this->initializeService($request);

        try {
            $usuario = User::where('id', Auth::id())->first();
            if ($usuario) {
                $usuario->update(['nombre' => $request->name]);
            }
            $this->lumorahService->setUserName($request->name);

            $responseMessage = "Gracias, {$request->name}. Ahora que nos conocemos mejor, ¿qué te gustaría compartir hoy?";

            return response()->json([
                'success' => true,
                'ai_message' => [
                    'text' => $responseMessage,
                    'is_user' => false,

                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al actualizar nombre: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Error al actualizar el nombre',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function sendVoiceMessage(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'session_id' => 'nullable|exists:chat_sessions,id',
            'user_name' => 'nullable|string|max:100',
        ]);

        try {
            $user = Auth::user();
            $user->load('profile'); // Asegura que el perfil esté cargado
            $userName = $user ? $user->nombre : null;
            $userProfile = $user->profile; // Asigna el objeto Profile

            $mealPlan = MealPlan::where('user_id', $user->id)
                ->where('is_active', true)
                ->latest('created_at')
                ->first();

            $mealPlanData = $mealPlan ? $mealPlan->plan_data : null;

            $voiceService = new LumorahAIService(
                $userName,
                $userProfile->language ?? 'es', // Usa el idioma del perfil si está disponible
                $mealPlanData,
                $userProfile // Pasa el perfil de usuario aquí también
            );

            $promptData = $voiceService->generateVoicePrompt($request->message);

            $contextMessages = [];
            if ($request->session_id) {
                $contextMessages = $this->getConversationContext($request->session_id);
            }

            $aiResponse = $voiceService->callOpenAI(
                $request->message,
                $promptData['system_prompt'],
                $contextMessages
            );

            $formattedResponse = $voiceService->formatVoiceResponse($aiResponse);

            $sessionId = $request->session_id;
            if ($sessionId) {
                DB::transaction(function () use ($request, $formattedResponse, $sessionId, $promptData) {
                    Message::create([
                        'chat_session_id' => $sessionId,
                        'user_id' => Auth::id(),
                        'text' => $request->message,
                        'is_user' => true,
                    ]);

                    Message::create([
                        'chat_session_id' => $sessionId,
                        'user_id' => null,
                        'text' => $formattedResponse,
                        'is_user' => false,
                    ]);

                    $this->pusher->trigger('chat-channel', 'new-message', [
                        'session_id' => $sessionId,
                        'message' => $formattedResponse,
                        'is_user' => false,
                    ]);
                });
            }

            return response()->json([
                'success' => true,
                'ai_message' => [
                    'text' => $formattedResponse,
                    'is_user' => false,
                ],
                'session_id' => $sessionId,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en sendVoiceMessage: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Error al procesar el mensaje de voz',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function getConversationContext($sessionId)
    {
        return Message::where('chat_session_id', $sessionId)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($message) {
                return [
                    'text' => $message->text,
                    'is_user' => $message->is_user,
                ];
            })
            ->toArray();
    }

    protected function createNewSession($firstMessage)
    {
        $session = ChatSession::create([
            'user_id' => Auth::id(),
            'title' => $this->generateSessionTitle($firstMessage),
            'is_saved' => false,
            'language' => 'es',
            'user_name' => $this->lumorahService->getUserName() ?? Auth::user() ? User::where('id', Auth::id())->first()->nombre : null,
        ]);

        return $session->id;
    }
}