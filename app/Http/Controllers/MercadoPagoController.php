<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use MercadoPago\SDK;
use MercadoPago\Preference;
use MercadoPago\Item;

class MercadoPagoController extends Controller
{
    public function __construct()
    {
        SDK::setAccessToken(config('services.mercadopago.access_token'));
    }

    public function createPreference(Request $request)
    {
        try {
            $preference = new Preference();

            // Crear los items para Mercado Pago
            $items = [];
            foreach ($request->items as $cartItem) {
                $item = new Item();
                $item->title = $cartItem['name'];
                $item->quantity = $cartItem['quantity'];
                $item->unit_price = $cartItem['price'];
                $items[] = $item;
            }

            $preference->items = $items;

            // URLs de retorno a tu aplicación
            $preference->back_urls = [
                "success" => "https://proyect.aftconta.mx/api/payments/success",
                "failure" => "https://proyect.aftconta.mx/api/payments/failure",
                "pending" => "https://proyect.aftconta.mx/api/payments/pending"
            ];

            // Redirigir automáticamente si el pago es aprobado
            $preference->auto_return = "approved";

            // Referencia externa para identificar la orden
            $preference->external_reference = $request->additionalData['external_reference'] ?? uniqid();

            // Datos del comprador si están disponibles
            if (isset($request->additionalData['customer'])) {
                $preference->payer = [
                    "name" => $request->additionalData['customer']['name'],
                    "email" => $request->additionalData['customer']['email'],
                ];
            }

            $preference->notification_url = "https://proyect.aftconta.mx/api/payments/webhook";

            $preference->save();

            return response()->json([
                'init_point' => $preference->init_point
            ]);

        } catch (\Exception $e) {
            \Log::error('Error MercadoPago: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function handleSuccess(Request $request)
    {
        \Log::info('Pago exitoso', $request->all());

        // Actualizar el estado del pedido en tu base de datos
        $payment_id = $request->payment_id;
        $status = $request->status;
        $external_reference = $request->external_reference;

        // Aquí puedes agregar la lógica para actualizar el pedido
        // Order::where('external_reference', $external_reference)->update(['status' => 'completed']);

        // Redireccionar a tu app móvil
        return redirect()->away('tuapp://checkout/success');
    }

    public function handleFailure(Request $request)
    {
        \Log::info('Pago fallido', $request->all());

        // Actualizar el estado del pedido en tu base de datos
        $external_reference = $request->external_reference;

        // Order::where('external_reference', $external_reference)->update(['status' => 'failed']);

        return redirect()->away('tuapp://checkout/failure');
    }

    public function handlePending(Request $request)
    {
        \Log::info('Pago pendiente', $request->all());

        return redirect()->away('tuapp://checkout/pending');
    }

    public function handleWebhook(Request $request)
    {
        try {
            Log::info('Webhook recibido', $request->all());
    
            $data = $request->all();
    
            if ($data['type'] === 'payment') {
                $payment_id = $data['data']['id'];
                $payment = SDK::get("/v1/payments/$payment_id");
    
                $external_reference = $payment->external_reference;
                $status = $payment->status;
    
                // Actualizar el estado del pedido
                $this->processPayment($payment);
    
                return response()->json(['status' => 'ok'], 200); // Respuesta exitosa
            }
    
            return response()->json(['status' => 'ignored'], 200); // Ignorar notificaciones no manejadas
        } catch (\Exception $e) {
            Log::error('Error en webhook: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}