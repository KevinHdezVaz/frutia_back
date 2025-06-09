<?php
namespace App\Http\Controllers;

use App\Models\DeviceToken;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    // Quitar el constructor con el middleware
    // public function __construct()
    // {
    //     $this->middleware('auth:api')->only(['apiIndex', 'apiDestroy']);
    // }

    // Métodos para las vistas web
    public function index()
    {
        $notifications = Notification::orderBy('created_at', 'desc')->get();
        return view('laravel-examples.field-notifications', compact('notifications'));
    }

    public function create()
    {
        return view('laravel-examples.field-notificationsCreate');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
        ]);
    
        $playerIds = DeviceToken::pluck('player_id')->toArray();
    
        if (empty($playerIds)) {
            return redirect()->route('notifications.index')
                ->with('error', 'No hay dispositivos registrados para recibir la notificación.');
        }
    
        // Crear la notificación una sola vez aquí
        $notification = Notification::create([
            'title' => $request->title,
            'message' => $request->message,
            'player_ids' => json_encode($playerIds),
        ]);
    
        // Enviar la notificación a OneSignal usando los datos ya creados
        $response = $this->sendOneSignalNotification($playerIds, $request->message, $request->title);
    
        return redirect()->route('notifications.index')
            ->with('success', 'Notificación enviada exitosamente');
    }

    public function destroy(Notification $notification)
    {
        $notification->delete();
        return redirect()->route('notifications.index')
            ->with('success', 'Notificación eliminada exitosamente');
    }

    // Métodos API para el frontend
    public function apiIndex(Request $request)
    {
        try {
            $notifications = Notification::orderBy('created_at', 'desc')->get();
            return response()->json([
                'success' => true,
                'notifications' => $notifications->map(function ($notification) {
                    return [
                        'id' => $notification->id,
                        'title' => $notification->title,
                        'message' => $notification->message,
                        'created_at' => $notification->created_at->toIso8601String(),
                        'player_ids' => $notification->player_ids,
                        'read' => (bool) $notification->read, // Incluimos el campo read
                    ];
                })
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener notificaciones: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar notificaciones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function apiDestroy($id)
    {
        try {
            $notification = Notification::findOrFail($id);
            $notification->delete();
            return response()->json([
                'success' => true,
                'message' => 'Notificación eliminada exitosamente'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al eliminar notificación: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar notificación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function storePlayerId(Request $request)
    {
        $request->validate([
            'player_id' => 'required|string',
            'user_id' => 'required|integer'
        ]);

        DB::beginTransaction();
        try {
            Log::info('Inicio storePlayerId', ['request' => $request->all()]);

            $playerId = $request->input('player_id');
            $userId = (int)$request->input('user_id');

            $result = DB::table('device_tokens')
                ->where('player_id', $playerId)
                ->update([
                    'user_id' => $userId,
                    'updated_at' => now()
                ]);

            if ($result === 0) {
                DB::table('device_tokens')->insert([
                    'player_id' => $playerId,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            $token = DB::table('device_tokens')
                ->where('player_id', $playerId)
                ->first();

            Log::info('Resultado final', [
                'token' => $token,
                'user_id_saved' => $token->user_id ?? null
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Token guardado correctamente',
                'data' => $token
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en storePlayerId: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al guardar token',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function markAllAsRead(Request $request)
    {
        try {
            // Aunque no filtremos por usuario, mantenemos la autenticación para seguridad
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Usuario no autenticado'], 401);
            }
    
            // Marcar todas las notificaciones como leídas
            $updated = DB::table('notifications')
                ->where('read', false)
                ->update(['read' => true]);
    
            Log::info('Notificaciones marcadas como leídas', ['updated_rows' => $updated]);
    
            return response()->json([
                'success' => true,
                'message' => 'Notificaciones marcadas como leídas',
                'updated' => $updated
            ]);
        } catch (\Exception $e) {
            Log::error('Error en markAllAsRead: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al marcar notificaciones como leídas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function sendNotification(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'title' => 'required|string|max:255',
        ]);
    
        $playerIds = DeviceToken::pluck('player_id')->toArray();
    
        if (empty($playerIds)) {
            return response()->json([
                'success' => false,
                'message' => 'No hay dispositivos registrados'
            ], 400);
        }
    
        $response = $this->sendOneSignalNotification($playerIds, $request->message, $request->title);
    
        return response()->json([
            'success' => true,
            'message' => 'Notificación enviada',
            'data' => [
                'notification_id' => $response['notification_id'],
                'onesignal_response' => $response['onesignal_response'],
            ],
        ], 200);
    }
    
    public function sendOneSignalNotification($playerIds, $message, $title)
    {
        // No creamos la notificación aquí, solo enviamos a OneSignal
        $fields = [
            'app_id' => env('ONESIGNAL_APP_ID'),
            'include_player_ids' => $playerIds,
            'contents' => ['en' => $message],
            'headings' => ['en' => $title],
            'small_icon' => 'ic_launcher',
            'large_icon' => 'ic_launcher',
            'android_group' => 'group_1'
        ];
    
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://onesignal.com/api/v1/notifications');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . env('ONESIGNAL_REST_API_KEY')
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
    
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
        Log::info('Respuesta de OneSignal', [
            'http_code' => $httpcode,
            'response' => json_decode($response, true),
            'request' => $fields
        ]);
    
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            Log::error('Error CURL', ['error' => $error]);
            curl_close($ch);
            return json_encode(['error' => $error]);
        }
    
        curl_close($ch);
    
        return [
            'onesignal_response' => json_decode($response, true),
            // No devolvemos notification_id porque ya no creamos el registro aquí
        ];
    }
}