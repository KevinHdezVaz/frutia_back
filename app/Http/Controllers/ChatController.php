<?php
namespace App\Http\Controllers;

use Pusher\Pusher;
use App\Models\User;
use App\Models\Message;
use App\Models\ChatSession;
use App\Models\MealPlan;
use App\Models\MealLog; // ⭐ NUEVO IMPORT

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

    // ⭐ NUEVO MÉTODO: Obtener historial del día
    // ⭐ MÉTODO CORREGIDO
    private function getTodayMealHistory($userId)
    {
        $today = now()->format('Y-m-d');

        $logs = MealLog::where('user_id', $userId)
            ->where('date', $today)
            ->get();

        if ($logs->isEmpty()) {
            return null;
        }

        return $logs->map(function ($log) {

            $selections = collect($log->selections)->map(function ($selection) {
                return [
                    'name' => $selection['name'] ?? 'Sin nombre',
                    'portion' => $selection['portion'] ?? 'Sin porción',
                    'calories' => $selection['calories'] ?? 0,
                    'protein' => $selection['protein'] ?? 0,
                    'carbs' => $selection['carbohydrates'] ?? 0, // ⭐ CAMBIO AQUÍ
                    'fats' => $selection['fats'] ?? 0,
                ];
            });

            $totals = [
                'calories' => $selections->sum('calories'),
                'protein' => $selections->sum('protein'),
                'carbs' => $selections->sum('carbs'),
                'fats' => $selections->sum('fats'),
            ];

            return [
                'meal_type' => $log->meal_type,
                'selections' => $selections->toArray(),
                'totals' => $totals,
            ];
        })->toArray();
    }

    // ⭐ MÉTODO ACTUALIZADO: Ahora incluye historial del día
    private function initializeService(Request $request)
    {
        $user = Auth::user();
        $userName = null;
        $mealPlanData = null;
        $userProfile = null;
        $todayHistory = null; // ⭐ NUEVO

        if ($user) {
            $user->load('profile');

            $userName = $user->name;
            $userProfile = $user->profile;

            // Obtener plan activo
            $mealPlan = MealPlan::where('user_id', $user->id)
                ->where('is_active', true)
                ->latest('created_at')
                ->first();

            if ($mealPlan && $mealPlan->plan_data) {
                $mealPlanData = is_array($mealPlan->plan_data)
                    ? $mealPlan->plan_data
                    : json_decode($mealPlan->plan_data, true);
            }

            // ⭐ NUEVO: Obtener historial del día
            $todayHistory = $this->getTodayMealHistory($user->id);
        }

        if (!$userName && $request->session_id) {
            $session = ChatSession::where('id', $request->session_id)
                ->where('user_id', Auth::id())
                ->first();
            $userName = $session->user_name ?? null;
        }

        // ⭐ ACTUALIZADO: Crear servicio con historial
        $this->lumorahService = new LumorahAIService(
            $userName,
            $userProfile->language ?? 'es',
            $mealPlanData,
            $userProfile
        );

        // ⭐ NUEVO: Establecer historial del día en el servicio
        if ($todayHistory) {
            $this->lumorahService->setTodayHistory($todayHistory);
            Log::info('Historial del día cargado en el servicio', [
                'user_id' => $user->id,
                'meals_logged' => count($todayHistory)
            ]);
        }
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
        $request->validate([
            'title' => 'required|string|max:100',
            'messages' => 'required|array',
            'messages.*.text' => 'nullable|string',
            'messages.*.image_url' => 'nullable|string|url',
            'messages.*.is_user' => 'required|boolean',
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

            if ($sessionId) {
                Message::where('chat_session_id', $session->id)->delete();
            }

            foreach ($request->messages as $msg) {
                if (empty($msg['text']) && empty($msg['image_url'])) {
                    continue;
                }

                Message::create([
                    'chat_session_id' => $session->id,
                    'user_id' => $msg['is_user'] ? $userId : null,
                    'text' => $msg['text'],
                    'image_url' => $msg['image_url'] ?? null,
                    'is_user' => $msg['is_user'],
                ]);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'data' => $session->fresh(),
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


    // ⭐ NUEVO MÉTODO: Marcar sesión como guardada sin tocar mensajes
    public function markSessionAsSaved($sessionId, Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:100',
            'is_saved' => 'required|boolean',
        ]);

        try {
            $session = ChatSession::where('id', $sessionId)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $session->update([
                'title' => $request->title,
                'is_saved' => $request->is_saved,
            ]);

            Log::info('✅ Sesión marcada como guardada', [
                'session_id' => $sessionId,
                'title' => $request->title,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sesión marcada como guardada',
                'data' => $session,
            ], 200);
        } catch (\Exception $e) {
            Log::error('❌ Error al marcar sesión como guardada', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Error al marcar la sesión',
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        $path = $request->file('image')->store('chat_images', 'public');

        return response()->json([
            'success' => true,
            'url' => asset('storage/' . $path)
        ]);
    }

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
                        'image_url' => $msg->image_url,
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

    // ⭐ MÉTODO ACTUALIZADO: Ahora usa historial del día
    // En ChatController.php, método sendMessage()

    public function sendMessage(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'session_id' => 'nullable|exists:chat_sessions,id',
            'is_temporary' => 'boolean',
            'user_name' => 'nullable|string|max:100',
        ]);

        $this->initializeService($request);

        try {
            $promptData = $this->lumorahService->generatePrompt($request->message);

            $contextMessages = [];
            if ($request->session_id && !$request->is_temporary) {
                $contextMessages = $this->getConversationContext($request->session_id);
            }

            $aiResponse = $this->lumorahService->callOpenAI(
                $request->message,
                $promptData['system_prompt'],
                $contextMessages
            );

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

            // ⭐ CAMBIO AQUÍ: Usar DB::transaction para garantizar que se guarden los mensajes
            DB::beginTransaction(); // ⬅️ MOVER AQUÍ

            try {
                // Si no hay sesión, crear una
                if (!$sessionId) {
                    $sessionId = $this->createNewSession($request->message);
                }

                // ⭐ GUARDAR MENSAJE DEL USUARIO
                $userMessage = Message::create([
                    'chat_session_id' => $sessionId,
                    'user_id' => Auth::id(),
                    'text' => $request->message,
                    'image_url' => null, // Por si envías imagen después
                    'is_user' => true,
                ]);

                Log::info('✅ Mensaje del usuario guardado', [
                    'message_id' => $userMessage->id,
                    'session_id' => $sessionId,
                    'user_id' => Auth::id()
                ]);

                // ⭐ GUARDAR RESPUESTA DE LA IA
                $aiMessage = Message::create([
                    'chat_session_id' => $sessionId,
                    'user_id' => null, // NULL porque es la IA
                    'text' => $aiResponse,
                    'image_url' => null,
                    'is_user' => false,
                ]);

                Log::info('✅ Mensaje de la IA guardado', [
                    'message_id' => $aiMessage->id,
                    'session_id' => $sessionId
                ]);

                // Incrementar contador de mensajes
                $user = Auth::user();
                if ($user->subscription_status !== 'active') {
                    $user->increment('message_count');
                    Log::info("Incrementado el contador de mensajes para el usuario {$user->id}. Nuevo total: {$user->message_count}");
                }

                // Notificar por Pusher (opcional)
                $this->pusher->trigger('chat-channel', 'new-message', [
                    'session_id' => $sessionId,
                    'message' => $aiResponse,
                    'is_user' => false,
                ]);

                DB::commit(); // ⬅️ CONFIRMAR TRANSACCIÓN

                Log::info('✅ Transacción completada exitosamente', [
                    'session_id' => $sessionId,
                    'total_messages' => Message::where('chat_session_id', $sessionId)->count()
                ]);

                $user = Auth::user();
                return response()->json([
                    'success' => true,
                    'ai_message' => [
                        'text' => $aiResponse,
                        'is_user' => false,
                        'id' => $aiMessage->id, // ⭐ NUEVO: Devolver ID del mensaje
                    ],
                    'user_message' => [ // ⭐ NUEVO: Devolver info del mensaje del usuario
                        'id' => $userMessage->id,
                        'text' => $request->message,
                        'is_user' => true,
                    ],
                    'session_id' => $sessionId,
                    'user_message_count' => $user->message_count,
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack(); // ⬅️ REVERTIR SI HAY ERROR
                Log::error('❌ Error en la transacción de mensajes', [
                    'error' => $e->getMessage(),
                    'session_id' => $sessionId,
                    'user_id' => Auth::id()
                ]);
                throw $e;
            }

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
            $user->load('profile');
            $userName = $user ? $user->nombre : null;
            $userProfile = $user->profile;

            $mealPlan = MealPlan::where('user_id', $user->id)
                ->where('is_active', true)
                ->latest('created_at')
                ->first();

            $mealPlanData = $mealPlan ? $mealPlan->plan_data : null;

            // ⭐ NUEVO: Obtener historial para voz también
            $todayHistory = $this->getTodayMealHistory($user->id);

            $voiceService = new LumorahAIService(
                $userName,
                $userProfile->language ?? 'es',
                $mealPlanData,
                $userProfile
            );

            // ⭐ NUEVO: Establecer historial
            if ($todayHistory) {
                $voiceService->setTodayHistory($todayHistory);
            }

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
