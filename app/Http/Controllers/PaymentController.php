<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\UserBono;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\MercadoPagoService;
use App\Http\Controllers\WebhookController;

class PaymentController extends Controller
{
    protected $mercadoPagoService;

    public function __construct(MercadoPagoService $mercadoPagoService)
    {
        $this->mercadoPagoService = $mercadoPagoService;
    }

    public function verifyPaymentStatus($paymentId)
    {
        try {
            $paymentInfo = $this->mercadoPagoService->validatePaymentStatus($paymentId);
            return response()->json([
                'status' => $paymentInfo['status'],
                'is_approved' => $paymentInfo['is_approved'],
            ]);
        } catch (\Exception $e) {
            Log::error('Error al verificar el estado del pago: ' . $e->getMessage());
            return response()->json(['error' => 'Error al verificar el estado del pago'], 500);
        }
    }
     
    public function createPreference(Request $request)
    {
        try {
            $request->validate([
                'items' => 'required|array',
                'items.*.title' => 'required|string',
                'items.*.quantity' => 'required|integer',
                'items.*.unit_price' => 'required|numeric',
                'type' => 'required|in:booking,bono,match',
                'reference_id' => 'required|integer',
            ]);

            $user = auth()->user();

            // Si es un partido (match), verificar bonos activos
            if ($request->type === 'match') {
                $userBono = UserBono::where('user_id', $user->id)
                    ->where('estado', 'activo')
                    ->where('fecha_vencimiento', '>', now())
                    ->where(function ($query) {
                        $query->whereNull('usos_disponibles')
                              ->orWhere('usos_disponibles', '>', 0);
                    })
                    ->first();

                if ($userBono) {
                    // Crear la orden sin pago, usando el bono
                    $order = Order::create([
                        'user_id' => $user->id,
                        'total' => 0, // Sin costo porque se usa bono
                        'status' => 'completed',
                        'type' => $request->type,
                        'reference_id' => $request->reference_id,
                        'payment_details' => array_merge(
                            $request->additionalData ?? [],
                            ['bono_used' => $userBono->id]
                        ),
                    ]);

                    // Decrementar usos si aplica
                    if ($userBono->usos_disponibles !== null) {
                        $userBono->usos_disponibles -= 1;
                        $userBono->save();
                    }

                    // Procesar la unión al equipo directamente usando el método existente
                    $webhookController = app(\App\Http\Controllers\WebhookController::class);
                    $paymentInfo = [
                        'id' => 'bono_' . $userBono->id,
                        'status' => 'approved',
                        'external_reference' => (string) $order->id,
                    ];
                    $webhookController->handlePayment($paymentInfo);

                    return response()->json([
                        'message' => 'Bono utilizado exitosamente',
                        'order_id' => $order->id,
                        'bono_id' => $userBono->id,
                    ]);
                }
            }

            // Si no hay bono o no aplica, proceder con el pago normal
            $order = Order::create([
                'user_id' => $user->id,
                'total' => collect($request->items)->sum(fn($item) => $item['quantity'] * $item['unit_price']),
                'status' => 'pending',
                'type' => $request->type,
                'reference_id' => $request->reference_id,
                'payment_details' => $request->additionalData ?? [],
            ]);

            $preferenceData = [
                'items' => $request->items,
                'back_urls' => [
                    'success' => 'footconnect://checkout/success',
                    'failure' => 'footconnect://checkout/failure',
                    'pending' => 'footconnect://checkout/pending',
                ],
                'auto_return' => 'approved',
                'external_reference' => (string) $order->id,
                'notification_url' => 'https://proyect.aftconta.mx/api/webhook/mercadopago',
                'payer' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ];

            $preference = $this->mercadoPagoService->createPreference($preferenceData);

            $order->update(['preference_id' => $preference['id']]);

            return response()->json([
                'init_point' => $preference['init_point'],
                'order_id' => $order->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al crear preferencia', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Error al procesar el pago'], 500);
        }
    }   


    public function handleSuccess(Request $request)
    {
        Log::info('Pago exitoso', $request->all());
        try {
            if ($request->payment_id) {
                $paymentInfo = $this->mercadoPagoService->getPaymentInfo($request->payment_id);
                $order = Order::findOrFail($paymentInfo['external_reference']);
                $order->update([
                    'status' => 'completed',
                    'payment_id' => $request->payment_id,
                    'payment_details' => array_merge(
                        $order->payment_details,
                        ['payment_info' => $paymentInfo]
                    ),
                ]);
            }
            return redirect('footconnect://checkout/success');
        } catch (\Exception $e) {
            Log::error('Error en success callback', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);
            return redirect('footconnect://checkout/failure');
        }
    }

    public function handleFailure(Request $request)
    {
        Log::info('Pago fallido', $request->all());
        try {
            if ($request->payment_id) {
                $paymentInfo = $this->mercadoPagoService->getPaymentInfo($request->payment_id);
                $order = Order::findOrFail($paymentInfo['external_reference']);
                $order->update([
                    'status' => 'failed',
                    'payment_id' => $request->payment_id,
                    'payment_details' => array_merge(
                        $order->payment_details,
                        ['payment_info' => $paymentInfo]
                    ),
                ]);
            }
            return redirect('footconnect://checkout/failure');
        } catch (\Exception $e) {
            Log::error('Error en failure callback', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);
            return redirect('footconnect://checkout/failure');
        }
    }

    public function handlePending(Request $request)
    {
        Log::info('Pago pendiente', $request->all());
        try {
            if ($request->payment_id) {
                $paymentInfo = $this->mercadoPagoService->getPaymentInfo($request->payment_id);
                $order = Order::findOrFail($paymentInfo['external_reference']);
                $order->update([
                    'status' => 'pending',
                    'payment_id' => $request->payment_id,
                    'payment_details' => array_merge(
                        $order->payment_details,
                        ['payment_info' => $paymentInfo]
                    ),
                ]);
            }
            return redirect('footconnect://checkout/pending');
        } catch (\Exception $e) {
            Log::error('Error en pending callback', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);
            return redirect('footconnect://checkout/failure');
        }
    }
}