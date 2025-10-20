<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\MercadoPagoService;
use App\Models\User;
use App\Models\Affiliate;
use App\Models\Referral;
use Carbon\Carbon;
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
        // Lee todos los datos, ya sea del cuerpo JSON o de la query
        $data = $request->all();
        Log::info('MercadoPago Webhook Received:', ['payload' => $data]);

        $topic = $data['topic'] ?? $request->query('topic');
        $id = $data['id'] ?? $request->query('data_id'); // 'id' para body, 'data_id' para query

        $paymentId = null;

        if ($topic === 'payment') {
            // Caso 1: Notificación de tipo 'payment' (rara en Checkout Pro)
            $paymentId = $id;
            Log::info("Webhook: Procesando tópico 'payment' con ID: {$paymentId}");

        } elseif ($topic === 'merchant_order') {
            // Caso 2: Notificación de tipo 'merchant_order' (la más común en Checkout Pro)
            
            try {
                // Consulta la orden comercial para ver los pagos asociados
                $orderInfo = $this->mercadoPagoService->getMerchantOrderInfo($id);
                Log::info("Webhook: Merchant Order ID {$id} consultada. Estado: " . $orderInfo['status'] ?? 'unknown');

                // Busca un pago aprobado dentro de la orden
                $approvedPayment = collect($orderInfo['payments'] ?? [])
                                     ->where('status', 'approved')
                                     ->first();

                if (!$approvedPayment) {
                     Log::info("Webhook: Merchant Order ID {$id} no tiene pagos aprobados aún o ya fue procesada. Terminando.");
                     return response()->json(['status' => 'success'], 200);
                }
                
                $paymentId = $approvedPayment['id'];
                Log::info("Webhook: Payment ID aprobado encontrado en la Merchant Order: {$paymentId}");

            } catch (\Exception $e) {
                Log::error('Error consultando Merchant Order: ' . $e->getMessage());
                return response()->json(['status' => 'error'], 500);
            }

        } else {
            // Otros topics (refund, chargebacks, etc.)
            Log::info('Webhook: Tópico ignorado.', ['topic' => $topic ?? 'not_set']);
            Log::info('================ FIN WEBHOOK MERCADOPAGO ================');
            return response()->json(['status' => 'success'], 200);
        }
        
        // ----------------------------------------------------------------------
        // LÓGICA DE PROCESAMIENTO DE PAGO (Común para 'payment' y 'merchant_order')
        // ----------------------------------------------------------------------
        
        if (!$paymentId) {
            Log::warning('MercadoPago Webhook: No se pudo determinar el ID de pago.');
            return response()->json(['status' => 'error', 'message' => 'No paymentId found'], 400);
        }

        try {
            $paymentInfo = $this->mercadoPagoService->getPaymentInfo($paymentId);
            Log::info("Webhook: Información final del pago ID {$paymentId} recibida.", ['status' => $paymentInfo['status'] ?? 'unknown']);

            if ($paymentInfo && isset($paymentInfo['status']) && $paymentInfo['status'] === 'approved') {
                Log::info("Webhook: El pago ID {$paymentId} está APROBADO. Procesando...");
                
                $externalReference = $paymentInfo['external_reference'] ?? null;
                
                if (!$externalReference) {
                    Log::error("Webhook: El pago ID {$paymentId} fue aprobado pero NO TIENE external_reference.");
                    return response()->json(['status' => 'error'], 400);
                }
                
                // 3. Extracción de datos corregida: usa planId como string
                preg_match('/user_(\d+)_plan_(\w+)_affiliate_(\d*)_/', $externalReference, $matches);

                $userId = $matches[1] ?? null;
                $planId = $matches[2] ?? null; 
                $affiliateId = !empty($matches[3]) ? $matches[3] : null;

                if (!$userId || !$planId) {
                    Log::error("Webhook: No se pudo extraer userId o planId de la external_reference: {$externalReference}");
                    return response()->json(['status' => 'error'], 400);
                }

                $user = User::find($userId);
                
                if ($user) {
                    Log::info("Webhook: Usuario {$userId} encontrado. Actualizando suscripción para plan: {$planId}...");
                    
                    $user->subscription_status = 'active';
                    $user->trial_ends_at = null;
                    
                    // Lógica de fecha de expiración
                    if ($planId === 'annual') {
                        $user->subscription_ends_at = Carbon::now()->addYear();
                    } elseif ($planId === 'monthly') {
                        $user->subscription_ends_at = Carbon::now()->addMonth();
                    }
                    
                    $user->save();
                    Log::info("--- ¡ÉXITO! Suscripción del usuario {$userId} activada. ---");

                    // Lógica de afiliados
                    if ($affiliateId) {
                        $affiliate = Affiliate::find($affiliateId);
                        if ($affiliate) {
                            Log::info("Webhook: Afiliado ID {$affiliateId} encontrado. Registrando referido...");
                            $transactionAmount = $paymentInfo['transaction_amount'] ?? 0;
                            // Asegura que commission_rate sea un número
                            $commissionRate = (float) $affiliate->commission_rate; 
                            $commissionAmount = ($transactionAmount * $commissionRate) / 100;

                            Referral::create([
                                'affiliate_id' => $affiliate->id,
                                'new_user_id' => $user->id,
                                'sale_amount' => $transactionAmount,
                                'commission_earned' => $commissionAmount,
                                'payout_status' => 'pending',
                            ]);
                            Log::info("--- ¡ÉXITO! Referido registrado para el afiliado {$affiliateId}. ---");
                        }
                    }
                } else {
                    Log::error("Webhook: Usuario con ID {$userId} NO ENCONTRADO.");
                }
            } else {
                Log::info("Webhook: El estado del pago ID {$paymentId} no es 'approved'. Estado actual: " . ($paymentInfo['status'] ?? 'desconocido'));
            }

        } catch (\Exception $e) {
            Log::error('CRÍTICO: Error procesando el webhook de MercadoPago: ' . $e->getMessage(), [
                'payment_id' => $paymentId ?? 'N/A',
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['status' => 'error'], 500);
        }
        
        Log::info('================ FIN WEBHOOK MERCADOPAGO ================');
        return response()->json(['status' => 'success'], 200);
    }
}