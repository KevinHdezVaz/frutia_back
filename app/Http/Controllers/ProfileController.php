<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Auth;

class ProfileController extends Controller
{
    public function storeOrUpdate(Request $request)
    {
        $user = Auth::user();

        // Validación de todos los campos enviados por el frontend
        $validatedData = $request->validate([
            'name' => 'nullable|string|max:255',
            'height' => 'nullable|numeric',
            'weight' => 'nullable|numeric',
            'age' => 'nullable|integer',
            'sex' => 'nullable|string|max:255',
            'goal' => 'nullable|string|max:255',
            'activity_level' => 'nullable|string|max:255',
            'sport' => 'nullable|array', // Validar como array
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
            'diet_difficulties' => 'nullable|array', // Nuevo campo
            'diet_motivations' => 'nullable|array' // Nuevo campo
        ]);

        // Guardar o actualizar el perfil
        $profile = UserProfile::updateOrCreate(
            ['user_id' => $user->id],
            $validatedData
        );

        // Asegurarnos de que plan_setup_complete sea true si se envía como true
        if ($request->boolean('plan_setup_complete')) {
            $profile->plan_setup_complete = true;
            $profile->save();
        }

        return response()->json([
            'message' => 'Perfil guardado exitosamente.',
            'profile' => $profile->fresh() // Devolver datos actualizados
        ], 200);
    }
}