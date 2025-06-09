<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserProfile;

class ProfileController extends Controller
{
    public function storeOrUpdate(Request $request)
    {
        $user = $request->user();

        // Validamos solo los campos que pertenecen a user_profiles
        $validatedData = $request->validate([
            'height' => 'nullable|string',
            'weight' => 'nullable|string',
            'age'    => 'nullable|string',
            'sex'    => 'nullable|string',
            'goal' => 'nullable|string|max:255',
            'activity_level' => 'nullable|string|max:255',
            'sport' => 'nullable|string|max:255',
            'training_frequency' => 'nullable|string|max:255',
            'meal_count' => 'nullable|string|max:255',
            'breakfast_time' => 'nullable|string',
            'lunch_time' => 'nullable|string',
            'dinner_time' => 'nullable|string',
            'cooking_habit' => 'nullable|string|max:255',
            'eats_out' => 'nullable|string|max:255',
            'dietary_style' => 'nullable|string|max:255',
            'disliked_foods' => 'nullable|string',
            'allergies' => 'nullable|string',
            'medical_condition' => 'nullable|string',
            'budget' => 'nullable|string|max:255',
            'communication_style' => 'nullable|string|max:255',
            'motivation_style' => 'nullable|string',
            'preferred_name' => 'nullable|string|max:255',
            'things_to_avoid' => 'nullable|string',
        ]);

        $profile = UserProfile::updateOrCreate(
            ['user_id' => $user->id],
            $validatedData
        );

        // Marcamos que el onboarding está completo
        $profile->plan_setup_complete = true;
        $profile->save();

        return response()->json([
            'message' => 'Perfil guardado exitosamente.',
            'profile' => $profile
        ], 200);
    }
}