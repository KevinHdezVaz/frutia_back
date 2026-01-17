<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            'activity_level' => 'nullable|string|max:255',
            'weekly_activity' => 'nullable|string|max:255',
            'sport' => 'nullable|array',
            'training_frequency' => 'nullable|string|max:255',
            'breakfast_time' => 'nullable|string|max:255',
            'lunch_time' => 'nullable|string|max:255',
            'dinner_time' => 'nullable|string|max:255',
            'preferred_snack_time' => 'nullable|string|max:255',
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
            'favorite_proteins' => 'nullable|array',
            'favorite_carbs' => 'nullable|array',
            'favorite_fats' => 'nullable|array',
            'favorite_fruits' => 'nullable|array',
            'locale' => 'nullable|string|in:en,es',
        ]);

        Log::info('[ProfileController] Datos validados.', $validatedData);

        // ⭐ DETECTAR IDIOMA: Primero del body, luego del header
        $locale = $validatedData['locale'] ?? $request->header('Accept-Language', 'en');
        $locale = substr($locale, 0, 2); // Normalizar (en-US -> en)
        $locale = in_array($locale, ['en', 'es']) ? $locale : 'en'; // Validar

        // ⭐ ASEGURAR QUE SE GUARDE EN LA BD
        $validatedData['locale'] = $locale;

        $profile = UserProfile::updateOrCreate(
            ['user_id' => $user->id],
            $validatedData
        );

        Log::info('[ProfileController] Perfil creado o actualizado con ID: ' . $profile->id);

        if ($request->boolean('plan_setup_complete')) {
            $profile->plan_setup_complete = true;
            $profile->save();
            Log::info('[ProfileController] plan_setup_complete marcado como true para el perfil ID: ' . $profile->id);

            // ⭐ MODIFICACIÓN: Pasar locale al job
            \App\Jobs\GenerateUserPlanJob::dispatch($user->id, $locale);
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

        $streakHistory = $user->streakLogs()
            ->whereDate('completed_at', '>=', Carbon::now()->subDays(7)->toDateString())
            ->pluck('completed_at');

        Log::info('[ProfileController] Historial de rachas encontrado: ', $streakHistory->all());

        $profileData = $profile->toArray();
        $profileData['streak_history'] = $streakHistory;

        Log::info('[ProfileController] Devolviendo respuesta final con historial.');

        return response()->json(['user' => $user->withoutRelations(), 'profile' => $profileData], 200);
    }
}
