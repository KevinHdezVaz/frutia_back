<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\MercadoPagoService;
use App\Models\User;
use App\Models\Affiliate; // <-- 1. Importa los modelos nuevos
use App\Models\Referral;
use Carbon\Carbon; // <-- 2. Importa Carbon para manejar fechas
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    protected $mercadoPagoService;

    public function __construct(MercadoPagoService $mercadoPagoService)
    {
        $this->mercadoPagoService = $mercadoPagoService;
    }

    public function handleMercadoPago(Request $request)
    {
        Log::info('================ INICIO WEBHOOK MERCADOPAGO ================');
        Log::info('MercadoPago Webhook Received:', ['payload' => $request->all()]);

        $query = $request->query();

        if (isset($query['type']) && $query['type'] === 'payment') {
            $paymentId = $query['data_id'] ?? null;

            if (!$paymentId) {
                Log::warning('MercadoPago Webhook: No se encontró data.id (paymentId).');
                return response()->json(['status' => 'error', 'message' => 'No data.id'], 400);
            }
            
            Log::info("Webhook: Procesando ID de pago: {$paymentId}");

            try {
                $paymentInfo = $this->mercadoPagoService->getPaymentInfo($paymentId);
                Log::info("Webhook: Información del pago recibida.", ['status' => $paymentInfo['status'] ?? 'unknown']);

                if ($paymentInfo && isset($paymentInfo['status']) && $paymentInfo['status'] === 'approved') {
                    Log::info("Webhook: El pago ID {$paymentId} está APROBADO. Procesando...");
                    
                    $externalReference = $paymentInfo['external_reference'] ?? null;
                    
                    if (!$externalReference) {
                        Log::error("Webhook: El pago ID {$paymentId} fue aprobado pero NO TIENE external_reference.");
                        return response()->json(['status' => 'error'], 400);
                    }
                    
                    Log::info("Webhook: Se encontró external_reference: {$externalReference}");

                    // ▼▼▼ INICIO DEL CAMBIO ▼▼▼

                    // 3. Extraemos todos los datos de la referencia
                    preg_match('/user_(\d+)_plan_(monthly|annual)_affiliate_(\d*)_/', $externalReference, $matches);

                    $userId = $matches[1] ?? null;
                    $planId = $matches[2] ?? null;
                    $affiliateId = !empty($matches[3]) ? $matches[3] : null; // El ID del afiliado

                    if (!$userId) {
                        Log::error("Webhook: No se pudo extraer el userId de la external_reference: {$externalReference}");
                        return response()->json(['status' => 'error'], 400);
                    }

                    Log::info("Webhook: userId extraído: {$userId}. Buscando usuario...");
                    $user = User::find($userId);
                    
                    if ($user) {
                        Log::info("Webhook: Usuario {$userId} encontrado. Actualizando suscripción...");
                        
                        // 4. Actualizamos el estado del usuario (como ya lo hacías)
                        $user->subscription_status = 'active';
                        $user->trial_ends_at = null;
                        // Añadimos la fecha de fin de la suscripción
                        $user->subscription_ends_at = ($planId === 'annual') ? Carbon::now()->addYear() : Carbon::now()->addMonth();
                        $user->save();

                        Log::info("--- ¡ÉXITO! Suscripción del usuario {$userId} activada.");

                        // 5. Si hubo un afiliado, registramos la comisión
                        if ($affiliateId) {
                            $affiliate = Affiliate::find($affiliateId);
                            if ($affiliate) {
                                Log::info("Webhook: Afiliado ID {$affiliateId} encontrado. Registrando referido...");
                                $transactionAmount = $paymentInfo['transaction_amount'] ?? 0;
                                $commissionAmount = ($transactionAmount * $affiliate->commission_rate) / 100;

                                Referral::create([
                                    'affiliate_id' => $affiliate->id,
                                    'new_user_id' => $user->id,
                                    'sale_amount' => $transactionAmount,
                                    'commission_earned' => $commissionAmount,
                                    'payout_status' => 'pending',
                                ]);
                                Log::info("--- ¡ÉXITO! Referido registrado para el afiliado {$affiliateId}. Comisión: {$commissionAmount} ---");
                            }
                        }
                        // ▲▲▲ FIN DEL CAMBIO ▲▲▲
                    } else {
                        Log::error("Webhook: Usuario con ID {$userId} NO ENCONTRADO.");
                    }
                } else {
                    Log::info("Webhook: El estado del pago ID {$paymentId} no es 'approved'. Estado actual: " . ($paymentInfo['status'] ?? 'desconocido'));
                }

            } catch (\Exception $e) {
                Log::error('CRÍTICO: Error procesando el webhook de MercadoPago: ' . $e->getMessage(), [
                    'payment_id' => $paymentId,
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json(['status' => 'error'], 500);
            }
        } else {
            Log::info('Webhook: La notificación no es de tipo "payment". Se ignora.', ['type' => $query['type'] ?? 'not_set']);
        }
        
        Log::info('================ FIN WEBHOOK MERCADOPAGO ================');
        return response()->json(['status' => 'success'], 200);
    }
}