<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\MercadoPagoService;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    protected $mercadoPagoService;

    public function __construct(MercadoPagoService $mercadoPagoService)
    {
        $this->mercadoPagoService = $mercadoPagoService;
    }

    /**
     * Maneja las notificaciones de webhook de MercadoPago.
     */
    public function handleMercadoPago(Request $request)
    {
        // 1. Log inicial para confirmar que el webhook fue alcanzado.
        Log::info('================ INICIO WEBHOOK MERCADOPAGO ================');
        Log::info('MercadoPago Webhook Received:', ['payload' => $request->all()]);

        $query = $request->query();

        // Verificamos si la notificación es de tipo 'payment'
        if (isset($query['type']) && $query['type'] === 'payment') {
            $paymentId = $query['data_id'] ?? null;

            if (!$paymentId) {
                Log::warning('MercadoPago Webhook: No se encontró data.id (paymentId) en la consulta.');
                return response()->json(['status' => 'error', 'message' => 'No data.id'], 400);
            }
            
            Log::info("Webhook: Procesando ID de pago: {$paymentId}");

            try {
                // 2. Log antes de llamar a la API de MercadoPago.
                Log::info("Webhook: Obteniendo información del pago para el ID: {$paymentId}");
                $paymentInfo = $this->mercadoPagoService->getPaymentInfo($paymentId);
                Log::info("Webhook: Información del pago recibida.", ['status' => $paymentInfo['status'] ?? 'unknown']);

                // 3. Verificamos que el pago esté aprobado
                if ($paymentInfo && isset($paymentInfo['status']) && $paymentInfo['status'] === 'approved') {
                    Log::info("Webhook: El pago ID {$paymentId} está APROBADO. Procesando...");
                    
                    $externalReference = $paymentInfo['external_reference'] ?? null;
                    
                    if (!$externalReference) {
                        Log::error("Webhook: El pago ID {$paymentId} fue aprobado pero NO TIENE external_reference.");
                        return response()->json(['status' => 'error'], 400);
                    }
                    
                    Log::info("Webhook: Se encontró external_reference: {$externalReference}");

                    // 4. Extraemos el ID del usuario de la referencia
                    $parts = explode('_', $externalReference);
                    $userId = $parts[1] ?? null;

                    if (!$userId) {
                        Log::error("Webhook: No se pudo extraer el userId de la external_reference: {$externalReference}");
                        return response()->json(['status' => 'error'], 400);
                    }

                    Log::info("Webhook: userId extraído: {$userId}. Buscando usuario en la base de datos...");
                    $user = User::find($userId);
                    
                    if ($user) {
                        Log::info("Webhook: Usuario {$userId} encontrado. Actualizando estado de suscripción...");
                        
                        // 5. Actualizamos el estado del usuario en la base de datos
                        $user->subscription_status = 'active';
                        $user->trial_ends_at = null;
                        $user->save();

                        Log::info("--- ¡ÉXITO! La suscripción del usuario {$userId} fue activada para el pago ID {$paymentId}. ---");
                    } else {
                        Log::error("Webhook: Usuario con ID {$userId} NO ENCONTRADO en la base de datos.");
                    }
                } else {
                    Log::info("Webhook: El estado del pago ID {$paymentId} no es 'approved'. Estado actual: " . ($paymentInfo['status'] ?? 'desconocido') . ". No se hace nada.");
                }

            } catch (\Exception $e) {
                Log::error('CRÍTICO: Error procesando el webhook de MercadoPago: ' . $e->getMessage(), [
                    'payment_id' => $paymentId,
                    'trace' => $e->getTraceAsString()
                ]);
                // Devolvemos 500 para que MercadoPago reintente la notificación
                return response()->json(['status' => 'error'], 500);
            }
        } else {
            Log::info('Webhook: La notificación no es de tipo "payment". Se ignora.', ['type' => $query['type'] ?? 'not_set']);
        }
        
        Log::info('================ FIN WEBHOOK MERCADOPAGO ================');
        // Respondemos 200 OK a MercadoPago para que sepan que recibimos la notificación
        return response()->json(['status' => 'success'], 200);
    }
}
