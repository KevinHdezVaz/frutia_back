<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Affiliate; // <-- Añade esta línea al principio del archivo
use App\Models\Plan; // <-- Asegúrate de importar el nuevo modelo

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
   
     
     public function getPlans()
     {
         $plans = Plan::where('is_active', true)
                      ->select('plan_id', 'name', 'title', 'price')
                      ->get();
 
         return response()->json($plans);
     }

     
public function createPreference(Request $request)
{
    $request->validate([
        'plan_id' => 'required|string', // Ya no validamos in:monthly,annual
        'affiliate_code' => 'nullable|string|exists:affiliates,referral_code',
    ]);

    $user = Auth::user();
    $planId = $request->input('plan_id');
    $affiliateCode = $request->input('affiliate_code');

    // ▼▼▼ INICIO DE CAMBIOS ▼▼▼

    // 1. Buscamos el plan en la base de datos
    $plan = Plan::where('plan_id', $planId)->where('is_active', true)->first();

    if (!$plan) {
        return response()->json(['error' => 'Plan inválido o no disponible'], 400);
    }

    // 2. Usamos los datos del modelo
    $finalPrice = (float)$plan->price;
    $affiliateId = null;

    if ($affiliateCode) {
        $affiliate = Affiliate::where('referral_code', $affiliateCode)->where('status', 'active')->first();
        if ($affiliate) {
            $discountPercentage = $affiliate->discount_percentage;
            $discountAmount = ($finalPrice * $discountPercentage) / 100;
            $finalPrice = $finalPrice - $discountAmount;
            $affiliateId = $affiliate->id;
        }
    }

    $preferenceData = [
        'items' => [
            [
                'title' => $plan->title,
                'quantity' => 1,
                'unit_price' => round($finalPrice, 2),
                'currency_id' => $plan->currency
            ]
        ],
        'payer' => [
            'name' => $user->name,
            'email' => $user->email,
        ],
        'back_urls' => [
            'success' => 'frutiapp://payment/success',
            'failure' => 'frutiapp://payment/failure',
            'pending' => 'frutiapp://payment/pending',
        ],
        'auto_return' => 'approved',
        'notification_url' => config('app.url') . '/api/webhooks/mercadopago',
        'external_reference' => "user_{$user->id}_plan_{$planId}_affiliate_{$affiliateId}_" . time(),
    ];

    // ▲▲▲ FIN DE CAMBIOS ▲▲▲

    try {
        $preference = $this->mercadoPagoService->createPreference($preferenceData);
        return response()->json(['init_point' => $preference['init_point']]);
    } catch (\Exception $e) {
        Log::error('Error creating MercadoPago preference from controller: ' . $e->getMessage());
        return response()->json(['error' => 'No se pudo iniciar el proceso de pago.'], 500);
    }
}

}
