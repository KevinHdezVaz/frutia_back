<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoPagoService
{
    protected $accessToken;
    protected $baseUrl = 'https://api.mercadopago.com';

    public function __construct()
    {
        $this->accessToken = config('services.mercadopago.access_token');
        
        if (empty($this->accessToken)) {
            Log::error('MercadoPago access token is not configured');
            throw new \Exception('MercadoPago access token is not configured');
        }

      
    }

    public function getAccessToken()
    {
        return $this->accessToken;
    }

    public function createPreference($preferenceData)
    {
        try {
            Log::info('Creating MercadoPago preference:', [
                'data' => $preferenceData,
                'access_token_length' => strlen($this->accessToken)
            ]);
            
            // Asegurar que los datos requeridos estén presentes
            if (!isset($preferenceData['items']) || empty($preferenceData['items'])) {
                throw new \Exception('Items are required for preference creation');
            }

            // Validar y preparar los items
            foreach ($preferenceData['items'] as &$item) {
                $item['currency_id'] = $item['currency_id'] ?? 'MXN';
                if (!isset($item['unit_price']) || !is_numeric($item['unit_price'])) {
                    throw new \Exception('Invalid unit price for item: ' . ($item['title'] ?? 'unknown'));
                }
                $item['unit_price'] = (float) $item['unit_price'];
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post($this->baseUrl . '/checkout/preferences', $preferenceData);

            Log::info('MercadoPago API Response:', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('MercadoPago Error:', $response->json());
            throw new \Exception('Error creating preference: ' . json_encode($response->json()));
        } catch (\Exception $e) {
            Log::error('MercadoPago Exception:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function getPaymentInfo($paymentId)
    {
        try {
            Log::info('Getting payment info:', ['payment_id' => $paymentId]);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->get($this->baseUrl . "/v1/payments/{$paymentId}");

            Log::info('Payment info response:', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('Error getting payment info:', $response->json());
            throw new \Exception('Error getting payment info: ' . json_encode($response->json()));
        } catch (\Exception $e) {
            Log::error('Payment info error:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payment_id' => $paymentId
            ]);
            throw $e;
        }
    }

    public function validatePaymentStatus($paymentId)
    {
        try {
            $paymentInfo = $this->getPaymentInfo($paymentId);
            
            return [
                'is_approved' => $paymentInfo['status'] === 'approved',
                'status' => $paymentInfo['status'],
                'status_detail' => $paymentInfo['status_detail'],
                'external_reference' => $paymentInfo['external_reference'],
                'payment_id' => $paymentInfo['id'],
                'transaction_amount' => $paymentInfo['transaction_amount'],
                'payment_method' => $paymentInfo['payment_method_id']
            ];
        } catch (\Exception $e) {
            Log::error('Payment validation error:', [
                'message' => $e->getMessage(),
                'payment_id' => $paymentId
            ]);
            throw $e;
        }
    }
}