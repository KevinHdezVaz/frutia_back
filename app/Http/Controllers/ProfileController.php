<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserProfile; // Importamos el modelo que creamos

class ProfileController extends Controller
{
    /**
     * Crea o actualiza el perfil de un usuario basado en los datos del onboarding.
     */
    public function storeOrUpdate(Request $request)
    {
        // Obtenemos el usuario autenticado gracias al token de Sanctum
        $user = $request->user();

        // Validamos los datos que vienen desde Flutter.
        // 'sometimes' significa que solo valida si el campo está presente.
      // EN ProfileController.php
$validatedData = $request->validate([
    'height' => 'nullable|string', // o numeric
    'weight' => 'nullable|string', // o numeric
    'age'    => 'nullable|string',
    'sex'    => 'nullable|string',
    'goal' => 'nullable|string|max:255',
    'activity_level' => 'nullable|string|max:255',
    'dietary_style' => 'nullable|string|max:255',
    'budget' => 'nullable|string|max:255', // <-- AHORA PERMITE NULL
    'cooking_habit' => 'nullable|string|max:255',
    'eats_out' => 'nullable|string|max:255', // <-- AHORA PERMITE NULL
    'disliked_foods' => 'nullable|string',
    'allergies' => 'nullable|string',
    'medical_condition' => 'nullable|string',
    'communication_style' => 'nullable|string|max:255',
    'motivation_style' => 'nullable|string|max:255',
    'preferred_name' => 'nullable|string|max:255',
    'things_to_avoid' => 'nullable|string',
]);

        // La magia de Laravel: Busca un perfil para este usuario. Si existe, lo actualiza.
        // Si no existe, lo crea con los datos proporcionados.
        $profile = UserProfile::updateOrCreate(
            ['user_id' => $user->id], // Condición de búsqueda
            $validatedData  // Datos para crear o actualizar
        );

        return response()->json([
            'message' => 'Perfil guardado exitosamente.',
            'profile' => $profile
        ], 200);
    }
}