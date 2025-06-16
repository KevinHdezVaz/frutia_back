<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Recipe;
use Illuminate\Support\Facades\Log; // Importamos la facade de Log

class RecipeController extends Controller
{
    /**
     * Muestra los detalles de una receta específica.
     *
     * @param  \App\Models\Recipe  $recipe
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Recipe $recipe)
    {
        // Log de entrada al método
        Log::info('Solicitud recibida para mostrar receta', [
            'recipe_id' => $recipe->id,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        try {
            // Log de datos de la receta (sin información sensible)
            Log::debug('Datos de receta recuperados', [
                'recipe_id' => $recipe->id,
                'name' => $recipe->name,
                'calories' => $recipe->calories,
                'prep_time' => $recipe->prep_time_minutes
            ]);

            // Respuesta exitosa
            $response = response()->json($recipe);

            // Log de respuesta exitosa
            Log::info('Receta enviada exitosamente', [
                'recipe_id' => $recipe->id,
                'response_status' => 200
            ]);

            return $response;

        } catch (\Exception $e) {
            // Log de error
            Log::error('Error al recuperar receta', [
                'recipe_id' => $recipe->id,
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Error al procesar la solicitud',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Aquí podrías añadir otros métodos en el futuro, como index() para listar todas las recetas.
}