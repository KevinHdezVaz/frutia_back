<?php
namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log; // Para depuración

class OneSignalService
{
    protected $client;
    protected $appId;
    protected $restApiKey;

    public function __construct()
    {
        $this->appId = config('services.onesignal.app_id');
        $this->restApiKey = config('services.onesignal.rest_api_key');

        $this->client = new Client([
            'base_uri' => 'https://onesignal.com/api/v1/',
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Authorization' => 'Basic ' . $this->restApiKey,
            ],
        ]);
    }

    /**
     * Envía una notificación push a uno o varios Player IDs de OneSignal.
     *
     * @param array|string $playerIds Un solo Player ID o un array de Player IDs.
     * @param string $heading El título de la notificación.
     * @param string $message El cuerpo del mensaje de la notificación.
     * @param array $data (Opcional) Datos adicionales a enviar con la notificación.
     * @return bool True si el envío fue exitoso, false en caso contrario.
     */
    public function sendNotification($playerIds, string $heading, string $message, array $data = []): bool
    {
        if (empty($playerIds)) {
            Log::warning('OneSignalService: No hay Player IDs para enviar la notificación.');
            return false;
        }

        // Aseguramos que $playerIds sea un array
        if (!is_array($playerIds)) {
            $playerIds = [$playerIds];
        }

        $payload = [
            'app_id' => $this->appId,
            'include_player_ids' => $playerIds,
            'headings' => ['en' => $heading, 'es' => $heading], // Puedes localizar esto
            'contents' => ['en' => $message, 'es' => $message], // Puedes localizar esto
            'data' => $data,
            'channel_for_external_user_ids' => 'push', // Asegura que se envíe via push si usas external IDs
        ];

        try {
            $response = $this->client->post('notifications', [
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $body = json_decode($response->getBody()->getContents(), true);

            if ($statusCode == 200 && isset($body['id'])) {
                Log::info("OneSignalService: Notificación enviada con éxito. ID: {$body['id']}");
                return true;
            } else {
                Log::error("OneSignalService: Error al enviar notificación. Código: $statusCode. Respuesta: " . json_encode($body));
                return false;
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::error("OneSignalService: Error de Guzzle al enviar notificación: " . $e->getMessage());
            if ($e->hasResponse()) {
                Log::error("OneSignalService: Respuesta de error: " . $e->getResponse()->getBody()->getContents());
            }
            return false;
        } catch (\Exception $e) {
            Log::error("OneSignalService: Error inesperado al enviar notificación: " . $e->getMessage());
            return false;
        }
    }
}