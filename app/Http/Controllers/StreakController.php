<?php
// en app/Http/Controllers/StreakController.php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StreakController extends Controller
{
    /**
     * Marca el día actual como completado y actualiza la racha del usuario.
     * Este es el método que será llamado por la API.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function marcarDiaCompleto(Request $request)
    {
        $user = Auth::user();

        // Laravel puede cargar relaciones automáticamente.
        // Asegúrate de tener la relación 'profile' en tu modelo User.
        // public function profile() { return $this->hasOne(UserProfile::class); }
        $profile = $user->profile;

        if (!$profile) {
            return response()->json(['message' => 'Perfil de usuario no encontrado.'], 404);
        }

        // Llamamos al método que tiene toda la lógica en el modelo.
        // ¡Mantenemos el controlador limpio!
        $profile->actualizarRacha();

        // Devolvemos una respuesta positiva a la app con la racha actualizada.
        return response()->json([
            'message' => '¡Día completado! Tu racha ha sido actualizada.',
            'racha_actual' => $profile->racha_actual,
            'ultima_fecha_racha' => $profile->ultima_fecha_racha->toDateString(),
        ], 200);
    }
}