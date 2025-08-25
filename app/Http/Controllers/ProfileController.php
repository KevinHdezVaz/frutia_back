<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log; // Asegúrate de que Log esté importado
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function storeOrUpdate(Request $request)
    {
        $user = Auth::user();
        Log::info('[ProfileController] Iniciando storeOrUpdate para el usuario ID: ' . $user->id);

        $validatedData = $request->validate([
            'name' => 'nullable|string|max:255',
            'height' => 'nullable|numeric',
            'weight' => 'nullable|numeric',
            'age' => 'nullable|integer',
            'sex' => 'nullable|string|max:255',
            'goal' => 'nullable|string|max:255',
            'activity_level' => 'nullable|string|max:255', // Podrías mantenerlo o eliminarlo si ya no se usa
            'weekly_activity' => 'nullable|string|max:255', // Agrega esta línea
            'sport' => 'nullable|array',
            'training_frequency' => 'nullable|string|max:255',
            'meal_count' => 'nullable|string|max:255',
            'breakfast_time' => 'nullable|string|max:255',
            'lunch_time' => 'nullable|string|max:255',
            'dinner_time' => 'nullable|string|max:255',
            'dietary_style' => 'nullable|string|max:255',
            'budget' => 'nullable|string|max:255',
            'cooking_habit' => 'nullable|string|max:255',
            'eats_out' => 'nullable|string|max:255',
            'disliked_foods' => 'nullable|string',
            'has_allergies' => 'nullable|boolean',
            'allergies' => 'nullable|string',
            'has_medical_condition' => 'nullable|boolean',
            'medical_condition' => 'nullable|string|max:255',
            'communication_style' => 'nullable|string|max:255',
            'motivation_style' => 'nullable|string|max:255',
            'preferred_name' => 'nullable|string|max:255',
            'things_to_avoid' => 'nullable|string',
            'plan_setup_complete' => 'nullable|boolean',
            'diet_difficulties' => 'nullable|array',
            'diet_motivations' => 'nullable|array',
            'pais' => 'nullable|string|max:255',
        ]);

        Log::info('[ProfileController] Datos validados.', $validatedData);

        $profile = UserProfile::updateOrCreate(
            ['user_id' => $user->id],
            $validatedData
        );

        Log::info('[ProfileController] Perfil creado o actualizado con ID: ' . $profile->id);

        if ($request->boolean('plan_setup_complete')) {
            $profile->plan_setup_complete = true;
            $profile->save();
            Log::info('[ProfileController] plan_setup_complete marcado como true para el perfil ID: ' . $profile->id);
        }

        return response()->json([
            'message' => 'Perfil guardado exitosamente.',
            'profile' => $profile->fresh()
        ], 200);
    }

    /**
     * Obtiene el perfil del usuario autenticado.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProfile(Request $request)
    {
        $user = Auth::user();
        Log::info('[ProfileController] Iniciando getProfile para el usuario ID: ' . $user->id);

        $user->load('profile'); 
        $profile = $user->profile;

        if (!$profile) {
            Log::warning('[ProfileController] No se encontró perfil para el usuario ID: ' . $user->id);
            return response()->json(['user' => $user, 'profile' => null], 200);
        }

        // --- ESTA ES LA FORMA MÁS LIMPIA USANDO LA RELACIÓN ---
        // Accedemos a la relación 'streakLogs' que definimos en el modelo User
        // y le aplicamos las condiciones.
        $streakHistory = $user->streakLogs()
            ->whereDate('completed_at', '>=', Carbon::now()->subDays(7)->toDateString())
            ->pluck('completed_at'); // pluck() solo devuelve un array de fechas.

        Log::info('[ProfileController] Historial de rachas encontrado: ', $streakHistory->all());
    
        $profileData = $profile->toArray();
        $profileData['streak_history'] = $streakHistory;

        Log::info('[ProfileController] Devolviendo respuesta final con historial.');
        
        // Usamos withoutRelations() para no enviar el objeto de perfil duplicado que ya cargamos.
        return response()->json(['user' => $user->withoutRelations(), 'profile' => $profileData], 200);
    }
}
