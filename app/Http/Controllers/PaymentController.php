<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected $mercadoPagoService;

    public function __construct(MercadoPagoService $mercadoPagoService)
    {
        $this->mercadoPagoService = $mercadoPagoService;
    }

    /**
     * Crea una preferencia de pago en MercadoPago.
     */
    public function createPreference(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|string|in:monthly,annual', // Valida que el plan sea 'monthly' o 'annual'
        ]);

        $user = Auth::user();
        $planId = $request->input('plan_id');

        // Define los detalles de tus planes aquí
        $plans = [
            'monthly' => ['title' => 'Suscripción Mensual Frutia', 'price' => 10.99],
            'annual' => ['title' => 'Suscripción Anual Frutia', 'price' => 95.88], // 7.99 * 12
        ];

        if (!isset($plans[$planId])) {
            return response()->json(['error' => 'Plan inválido'], 400);
        }

        $selectedPlan = $plans[$planId];

        $preferenceData = [
            'items' => [
                [
                    'title' => $selectedPlan['title'],
                    'quantity' => 1,
                    'unit_price' => (float)$selectedPlan['price'],
                    'currency_id' => 'MXN' // O la moneda que uses
                ]
            ],
            'payer' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
          

            'back_urls' => [
                // Usamos el esquema personalizado para que la app pueda interceptarlo.
                'success' => 'frutiapp://payment/success',
                'failure' => 'frutiapp://payment/failure',
                'pending' => 'frutiapp://payment/pending',
            ],
            'auto_return' => 'approved',
            'notification_url' => config('app.url') . '/api/webhooks/mercadopago', // ¡MUY IMPORTANTE!
            'external_reference' => "user_{$user->id}_plan_{$planId}_" . time(), // Referencia única para identificar la compra
        ];

        try {
            $preference = $this->mercadoPagoService->createPreference($preferenceData);
            
            return response()->json(['init_point' => $preference['init_point']]);

             

        } catch (\Exception $e) {
            Log::error('Error creating MercadoPago preference from controller: ' . $e->getMessage());
            return response()->json(['error' => 'No se pudo iniciar el proceso de pago.'], 500);
        }
    }
}
