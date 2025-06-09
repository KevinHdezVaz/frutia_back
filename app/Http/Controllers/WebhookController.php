<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Bono;
use App\Models\User;
use App\Models\Order;
use App\Models\Booking;
use App\Models\UserBono;
use App\Models\MatchTeam;
use App\Models\DeviceToken;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\EquipoPartido;
use App\Models\MatchTeamPlayer;
use App\Models\NotificationEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\Http;

class WebhookController extends Controller
{
    protected $mercadoPagoService;

    public function __construct(MercadoPagoService $mercadoPagoService)
    {
        $this->mercadoPagoService = $mercadoPagoService;
    }

    public function test()
    {
        return response()->json(['status' => 'webhook endpoint is working']);
    }

    public function handleMercadoPago(Request $request)
    {
        try {
            Log::info('=== MercadoPago Webhook Start ===');
            Log::info('Request Data:', $request->all());
            Log::info('Request Headers:', $request->headers->all());
    
            if (!$this->validateMercadoPagoRequest($request)) {
                Log::error('Invalid webhook signature');
                return response()->json(['error' => 'Invalid signature'], 401);
            }
    
            $data = $request->all();
    
            // Verificar si ya procesamos esta notificación (por id)
            if (isset($data['id'])) {
                $existingLog = false; // Por ahora simplemente deshabilitamos la verificación de duplicados

                if ($existingLog) {
                    Log::info('Notificación duplicada ignorada:', ['id' => $data['id']]);
                    return response()->json(['status' => 'duplicated'], 200);
                }
            }
    
            if ($request->type === 'payment') {
                return $this->handlePaymentNotification($request);
            } elseif ($request->topic === 'merchant_order') {
                return $this->handleMerchantOrderNotification($request);
            } elseif ($request->topic === 'payment') {
                return $this->handlePaymentNotificationById($request->id);
            } else {
                Log::info('Notification type not handled:', [
                    'type' => $request->type,
                    'topic' => $request->topic
                ]);
                return response()->json(['status' => 'ignored']);
            }
        } catch (\Exception $e) {
            Log::error('Webhook Error:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Error processing webhook'], 500);
        }
    }
    
    public function handlePayment($paymentInfo)
    {
        try {
            Log::info('Processing payment:', $paymentInfo);
    
            if (empty($paymentInfo['external_reference'])) {
                throw new \Exception('External reference not found in payment info');
            }
    
            $orderId = $paymentInfo['external_reference'];
            $order = Order::where('id', $orderId)->first();
    
            if (!$order) {
                throw new \Exception('Order not found: ' . $orderId);
            }
    
            Log::info('Order found:', [
                'order_id' => $order->id,
                'type' => $order->type,
                'reference_id' => $order->reference_id,
                'payment_details' => $order->payment_details
            ]);
    
            // Verificar si la orden ya está completada
            if ($order->status === 'completed') {
                Log::info('Order already completed:', ['order_id' => $order->id]);
                return response()->json([
                    'status' => 'already_completed',
                    'message' => 'Order already processed'
                ], 200);
            }
    
            switch ($paymentInfo['status']) {
                case 'approved':
                    return $this->handleApprovedPayment($order, $paymentInfo);
                case 'rejected':
                case 'cancelled':
                    $order->update(['status' => 'failed']);
                    break;
                case 'pending':
                case 'in_process':
                    $order->update(['status' => 'pending']);
                    break;
                case 'authorized':
                    $order->update(['status' => 'authorized']);
                    break;
                default:
                    Log::warning('Unknown payment status:', ['status' => $paymentInfo['status']]);
                    $order->update(['status' => 'unknown']);
                    break;
            }
    
            return response()->json([
                'status' => 'updated',
                'payment_status' => $paymentInfo['status']
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing payment:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payment_info' => $paymentInfo
            ]);
            throw $e;
        }
    }

    private function validateMercadoPagoRequest(Request $request)
    {
        return true; // Implementar validación de firma si es necesario
    }

    private function handlePaymentNotification(Request $request)
    {
        try {
            $paymentId = $request->data['id'];
            Log::info('Processing payment notification:', ['payment_id' => $paymentId]);
            return $this->handlePayment($this->mercadoPagoService->getPaymentInfo($paymentId));
        } catch (\Exception $e) {
            Log::error('Error in payment notification:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function handlePaymentNotificationById($paymentId)
    {
        try {
            Log::info('Processing payment notification (topic: payment):', ['payment_id' => $paymentId]);
            return $this->handlePayment($this->mercadoPagoService->getPaymentInfo($paymentId));
        } catch (\Exception $e) {
            Log::error('Error in payment notification (topic: payment):', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function handleMerchantOrderNotification(Request $request)
    {
        try {
            $merchantOrderUrl = $request->resource;
            Log::info('Processing merchant order:', ['url' => $merchantOrderUrl]);

            $response = Http::withToken($this->mercadoPagoService->getAccessToken())
                ->get($merchantOrderUrl);

            if (!$response->successful()) {
                Log::error('Error getting merchant order:', $response->json());
                return response()->json(['error' => 'Error getting merchant order'], 400);
            }

            $orderData = $response->json();
            Log::info('Merchant order data:', $orderData);

            $results = [];
            if (isset($orderData['payments']) && !empty($orderData['payments'])) {
                foreach ($orderData['payments'] as $payment) {
                    if ($payment['status'] === 'approved') {
                        $paymentInfo = $this->mercadoPagoService->getPaymentInfo($payment['id']);
                        $results[] = $this->handlePayment($paymentInfo);
                    }
                }
            }

            return response()->json([
                'status' => 'success',
                'results' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing merchant order:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function handleApprovedPayment(Order $order, $paymentInfo)
    {
        try {
            Log::info('Procesando orden:', ['order_id' => $order->id, 'type' => $order->type]);
            
            if ($order->status !== 'completed') {
                $order->update([
                    'status' => 'completed',
                    'payment_id' => $paymentInfo['id'],
                    'payment_details' => array_merge(
                        $order->payment_details ?? [],
                        ['payment_info' => $paymentInfo]
                    )
                ]);
                
                Log::info('Orden actualizada a estado completed', ['order_id' => $order->id]);
            } else {
                if (!isset($order->payment_details['payment_info']) || $order->payment_id !== $paymentInfo['id']) {
                    $order->update([
                        'payment_id' => $paymentInfo['id'],
                        'payment_details' => array_merge(
                            $order->payment_details ?? [],
                            ['payment_info' => $paymentInfo]
                        )
                    ]);
                    
                    Log::info('Payment info actualizado para orden completada', ['order_id' => $order->id]);
                }
            }
    
            switch ($order->type) {
                case 'booking':
                    return $this->processBooking($order, $paymentInfo);
                case 'bono':
                    return $this->processBono($order, $paymentInfo);
                case 'match':
                    return $this->processMatch($order, $paymentInfo);
                default:
                    Log::warning('Unhandled order type:', ['type' => $order->type]);
                    return response()->json(['error' => 'Unknown order type'], 400);
            }
        } catch (\Exception $e) {
            Log::error('Error in handleApprovedPayment:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'order_id' => $order->id
            ]);
            throw $e;
        }
    }

    private function processMatch(Order $order, $paymentInfo)
    {
        try {
            Log::info('Procesando pago para unirse a partido', [
                'order_id' => $order->id,
                'payment_details' => $order->payment_details,
                'user_id' => $order->user_id
            ]);
            
            $details = $order->payment_details;
            
            $teamId = null;
            $position = null;
            $matchId = $order->reference_id;
            
            if (isset($details['team_id'])) {
                $teamId = $details['team_id'];
            }
            
            if (isset($details['position'])) {
                $position = $details['position'];
            }
            
            Log::info('Datos extraídos:', [
                'teamId' => $teamId,
                'position' => $position,
                'matchId' => $matchId
            ]);
            
            if (!$teamId || !$position) {
                throw new \Exception('Faltan datos para procesar la unión al partido');
            }
            
            $team = MatchTeam::find($teamId);
            if (!$team) {
                throw new \Exception('El equipo no existe: ' . $teamId);
            }
            
            Log::info('Equipo encontrado:', [
                'team_id' => $team->id,
                'name' => $team->name,
                'player_count' => $team->player_count,
                'max_players' => $team->max_players
            ]);
            
            if ($team->player_count >= $team->max_players) {
                throw new \Exception('El equipo está lleno');
            }
            
            $existingPlayer = MatchTeamPlayer::whereHas('team', function($query) use ($matchId) {
                $query->where('equipo_partido_id', $matchId);
            })->where('user_id', $order->user_id)->first();
            
            if ($existingPlayer) {
                Log::info('Usuario ya está en un equipo de este partido', [
                    'user_id' => $order->user_id,
                    'match_id' => $matchId,
                    'existing_player' => $existingPlayer->id
                ]);
                return response()->json([
                    'status' => 'success',
                    'message' => 'Usuario ya registrado en este partido'
                ]);
            }
            
            Log::info('Creando registro de jugador:', [
                'user_id' => $order->user_id,
                'team_id' => $teamId,
                'position' => $position
            ]);
            
            DB::beginTransaction();
            try {
                $player = MatchTeamPlayer::create([
                    'match_team_id' => $teamId,
                    'user_id' => $order->user_id,
                    'position' => $position,
                ]);
                
                Log::info('Jugador creado:', [
                    'player_id' => $player->id,
                    'match_team_id' => $player->match_team_id,
                    'user_id' => $player->user_id,
                    'position' => $player->position
                ]);
                
                $team->player_count = $team->player_count + 1;
                $team->save();
                
                Log::info('Contador de jugadores actualizado:', [
                    'team_id' => $team->id,
                    'new_player_count' => $team->player_count
                ]);
                
                if ($order->status !== 'completed') {
                    $order->status = 'completed';
                    $order->payment_id = $paymentInfo['id'];
                    $order->save();
                    
                    Log::info('Orden actualizada a completed:', [
                        'order_id' => $order->id
                    ]);
                }
                
                DB::commit();
            } catch (\Exception $e) {
                DB::rollback();
                Log::error('Error al crear jugador en la base de datos:', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
            
            $match = EquipoPartido::find($matchId);
            $allTeams = MatchTeam::where('equipo_partido_id', $matchId)->get();
            
            Log::info('Verificando si el partido está lleno:', [
                'match_id' => $matchId,
                'teams_count' => $allTeams->count()
            ]);
            
            $allTeamsFull = true;
            foreach ($allTeams as $t) {
                Log::info('Estado del equipo:', [
                    'team_id' => $t->id,
                    'player_count' => $t->player_count,
                    'max_players' => $t->max_players,
                    'is_full' => $t->player_count >= $t->max_players
                ]);
                
                if ($t->player_count < $t->max_players) {
                    $allTeamsFull = false;
                    break;
                }
            }
            
            if ($allTeamsFull) {
                $match->update(['status' => 'full']);
                
                Log::info('Partido marcado como lleno:', [
                    'match_id' => $matchId
                ]);
                
                try {
                    $matchStartTime = Carbon::parse("{$match->schedule_date} {$match->start_time}");
                    NotificationEvent::create([
                        'equipo_partido_id' => $match->id,
                        'event_type' => 'match_start',
                        'scheduled_at' => $matchStartTime,
                        'message' => 'Tu partido está por comenzar'
                    ]);
                    
                    $matchEndTime = Carbon::parse("{$match->schedule_date} {$match->end_time}");
                   NotificationEvent::create([
                        'equipo_partido_id' => $match->id,
                        'event_type' => 'match_rating',
                        'scheduled_at' => $matchEndTime,
                        'message' => '¡El partido ha terminado! Califica a tus compañeros'
                    ]);
                    
                    Log::info('Notificaciones programadas para el partido:', [
                        'match_id' => $matchId,
                        'start_time' => $matchStartTime,
                        'end_time' => $matchEndTime
                    ]);
                } catch (\Exception $e) {
                    Log::error('Error al programar notificaciones:', [
                        'error' => $e->getMessage()
                    ]);
                }
                
                try {
                    $playerIds = DeviceToken::whereHas('user', function($q) use ($match) {
                        $q->whereHas('matchTeamPlayers.team', function($q) use ($match) {
                            $q->where('equipo_partido_id', $match->id);
                        });
                    })->pluck('player_id')->toArray();
                    
                    if (!empty($playerIds)) {
                        $notificationController = app(\App\Http\Controllers\NotificationController::class);
                        $notificationController->sendOneSignalNotification(
                            $playerIds,
                            "¡El partido está completo! Nos vemos en la cancha",
                            "Partido Completo"
                        );
                        
                        Log::info('Notificación enviada a jugadores:', [
                            'player_ids_count' => count($playerIds)
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Error al enviar notificaciones:', [
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                try {
                    $playerIds = DeviceToken::whereNotNull('user_id')
                        ->where('user_id', '!=', $order->user_id)
                        ->pluck('player_id')
                        ->toArray();
                    
                    if (!empty($playerIds)) {
                        $user = User::find($order->user_id);
                        $userName = $user ? $user->name : 'Un nuevo jugador';
                        
                        $notificationController = app(\App\Http\Controllers\NotificationController::class);
                        $notificationController->sendOneSignalNotification(
                            $playerIds,
                            "$userName se ha unido al {$team->name} en el partido {$match->name}",
                            "Nuevo jugador en partido"
                        );
                        
                        Log::info('Notificación enviada sobre nuevo jugador:', [
                            'player_ids_count' => count($playerIds),
                            'user_name' => $userName
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Error al enviar notificación de nuevo jugador:', [
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            Log::info('Usuario unido exitosamente al partido', [
                'user_id' => $order->user_id,
                'team_id' => $teamId,
                'position' => $position,
                'player_id' => $player->id
            ]);
            
            return response()->json([
                'status' => 'success',
                'player_id' => $player->id,
                'message' => 'Usuario unido exitosamente al partido'
            ]);
        } catch (\Exception $e) {
            Log::error('Error procesando pago para unirse a partido', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    private function processBooking(Order $order, $paymentInfo)
    {
        $details = $order->payment_details;
    
        // Verificar si ya existe una reserva con este payment_id
        $existingBooking = Booking::where('payment_id', $paymentInfo['id'])->first();
        if ($existingBooking) {
            Log::info('Booking already exists:', ['booking_id' => $existingBooking->id]);
            return response()->json([
                'status' => 'success',
                'booking_id' => $existingBooking->id,
                'message' => 'Booking already exists'
            ]);
        }
    
        // Obtener la zona horaria de la cancha (fija por ahora, escalable luego)
        $timezone = 'America/Montevideo'; // Podemos añadir un campo timezone más adelante
    
        // Preparar datos de la reserva
        $startTimeRaw = $details['start_time']; // ej. "12:00"
        if (strlen($startTimeRaw) == 5) { // H:i
            $startTimeRaw .= ':00'; // H:i:s, ej. "12:00:00"
        }
        $scheduleDate = $details['date']; // ej. "2025-03-12"
    
        // Parsear el horario en la zona horaria de la cancha y convertir a UTC
        $startTimeLocal = Carbon::parse("{$scheduleDate} {$startTimeRaw}", $timezone);
        $endTimeLocal = $startTimeLocal->copy()->addHour();
    
        $startTime = $startTimeLocal->copy()->setTimezone('UTC');
        $endTime = $endTimeLocal->copy()->setTimezone('UTC');
    
        Log::info('Datos de entrada', [
            'field_id' => $order->reference_id,
            'timezone' => $timezone,
            'schedule_date' => $scheduleDate,
            'start_time_raw' => $startTimeRaw,
            'start_time_local' => $startTimeLocal->toDateTimeString(),
            'end_time_local' => $endTimeLocal->toDateTimeString(),
            'start_time_utc' => $startTime->toDateTimeString(),
            'end_time_utc' => $endTime->toDateTimeString(),
        ]);
    
        // Buscar el partido en DailyMatch (opcional, mantenido por si lo usas)
        $match = DailyMatch::where('field_id', $order->reference_id)
            ->where('schedule_date', $scheduleDate)
            ->where('start_time', $startTimeRaw)
            ->where('status', 'open')
            ->first();
    
        Log::info('Búsqueda de partido en processBooking', [
            'field_id' => $order->reference_id,
            'schedule_date' => $scheduleDate,
            'start_time' => $startTimeRaw,
            'match_found' => $match ? $match->toArray() : 'No encontrado',
        ]);
    
        // Crear la reserva directamente (sin verificar disponibilidad)
        $booking = Booking::create([
            'user_id' => $order->user_id,
            'field_id' => $order->reference_id,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'total_price' => $order->total,
            'status' => 'confirmed',
            'payment_status' => 'completed',
            'payment_id' => $paymentInfo['id'],
            'payment_method' => 'mercadopago',
            'players_needed' => $details['players_needed'] ?? null,
            'allow_joining' => $details['allow_joining'] ?? false,
            'daily_match_id' => $match ? $match->id : null,
        ]);
    
        // Si se encontró un partido, marcarlo como reservado
        if ($match) {
            $match->update(['status' => 'reserved']);
            Log::info('Partido marcado como reservado', [
                'match_id' => $match->id,
                'booking_id' => $booking->id,
            ]);
        }
    
        Log::info('Booking created successfully:', [
            'booking_id' => $booking->id,
            'field_id' => $booking->field_id,
            'start_time' => $booking->start_time->toDateTimeString(),
            'end_time' => $booking->end_time->toDateTimeString(),
            'daily_match_id' => $booking->daily_match_id,
        ]);
    
        return response()->json([
            'status' => 'success',
            'booking_id' => $booking->id,
            'message' => 'New booking created'
        ]);
    }

    private function processBono(Order $order, $paymentInfo)
    {
        $existingUserBono = UserBono::where('payment_id', $paymentInfo['id'])->first();
        if ($existingUserBono) {
            Log::info('UserBono already exists with payment_id:', ['payment_id' => $paymentInfo['id']]);
            return response()->json([
                'status' => 'success',
                'user_bono_id' => $existingUserBono->id,
                'message' => 'UserBono already exists'
            ]);
        }
    
        $existingBonoByType = UserBono::where('user_id', $order->user_id)
            ->where('bono_id', $order->reference_id)
            ->where('estado', 'activo')
            ->where('fecha_vencimiento', '>', now())
            ->first();
            
        if ($existingBonoByType) {
            Log::info('Active UserBono already exists for this user and bono_id:', [
                'user_id' => $order->user_id,
                'bono_id' => $order->reference_id
            ]);
            return response()->json([
                'status' => 'success',
                'user_bono_id' => $existingBonoByType->id,
                'message' => 'Active UserBono already exists'
            ]);
        }
    
        $bono = Bono::findOrFail($order->reference_id);
        $fechaCompra = now();
        $fechaVencimiento = $fechaCompra->copy()->addDays($bono->duracion_dias);
    
        $codigoReferencia = strtoupper(Str::random(8));
        while (UserBono::where('codigo_referencia', $codigoReferencia)->exists()) {
            $codigoReferencia = strtoupper(Str::random(8));
        }
    
        $userBono = UserBono::create([
            'user_id' => $order->user_id,
            'bono_id' => $order->reference_id,
            'fecha_compra' => $fechaCompra,
            'fecha_vencimiento' => $fechaVencimiento,
            'codigo_referencia' => $codigoReferencia,
            'payment_id' => $paymentInfo['id'],
            'estado' => 'activo',
            'usos_disponibles' => $bono->usos_totales ?? null,
            'usos_totales' => $bono->usos_totales ?? null,
        ]);
    
        Log::info('UserBono created successfully:', [
            'user_bono_id' => $userBono->id,
            'bono_id' => $userBono->bono_id,
            'codigo_referencia' => $userBono->codigo_referencia
        ]);
    
        return response()->json([
            'status' => 'success',
            'user_bono_id' => $userBono->id,
            'message' => 'New UserBono created'
        ]);
    }
}