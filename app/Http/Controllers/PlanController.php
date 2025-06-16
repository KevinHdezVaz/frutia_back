<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MealPlan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlanController extends Controller
{
    public function generateAndStorePlan(Request $request)
    {
        $user = $request->user()->load('profile');
        $profile = $user->profile;

        // Debug: Registrar inicio del proceso y datos del usuario
        Log::debug('Iniciando generación de plan alimenticio', [
            'user_id' => $user->id,
            'user_name' => $user->name ?? 'No especificado',
            'profile_exists' => !is_null($profile)
        ]);

        if (!$profile) {
            Log::warning('Perfil incompleto para usuario', ['user_id' => $user->id]);
            return response()->json(['message' => 'El perfil del usuario no está completo.'], 400);
        }

        // Validar campos requeridos del perfil
        $requiredFields = ['goal', 'dietary_style', 'activity_level'];
        $missingFields = array_filter($requiredFields, fn($field) => empty($profile->$field));
        if (!empty($missingFields)) {
            Log::warning('Campos requeridos faltantes', [
                'user_id' => $user->id,
                'missing_fields' => $missingFields
            ]);
            return response()->json([
                'message' => 'Faltan datos requeridos: ' . implode(', ', $missingFields)
            ], 400);
        }

        // Debug: Registrar datos del perfil
        Log::debug('Datos del perfil del usuario', [
            'user_id' => $user->id,
            'profile' => $profile->toArray()
        ]);

        // Preparamos los datos opcionales
        $dislikedFoods = isset($profile->disliked_foods) ? $profile->disliked_foods : 'Ninguno';
        $allergies = isset($profile->allergies) ? $profile->allergies : 'Ninguna';
        $sport = is_array($profile->sport) ? implode(', ', $profile->sport) : (isset($profile->sport) ? $profile->sport : 'Ninguno');
        $dietDifficulties = is_array($profile->diet_difficulties) ? implode(', ', $profile->diet_difficulties) : (isset($profile->diet_difficulties) ? $profile->diet_difficulties : 'Ninguna');
        $dietMotivations = is_array($profile->diet_motivations) ? implode(', ', $profile->diet_motivations) : (isset($profile->diet_motivations) ? $profile->diet_motivations : 'Ninguna');
        $preferredName = isset($profile->preferred_name) ? $profile->preferred_name : (isset($user->name) ? $user->name : 'Usuario');
        $communicationStyle = isset($profile->communication_style) ? $profile->communication_style : 'Motivadora';
        $breakfastTime = isset($profile->breakfast_time) ? $profile->breakfast_time : '08:00';
        $lunchTime = isset($profile->lunch_time) ? $profile->lunch_time : '13:00';
        $dinnerTime = isset($profile->dinner_time) ? $profile->dinner_time : '20:00';
        $medicalCondition = isset($profile->medical_condition) ? $profile->medical_condition : 'Ninguna';
        $mealCount = isset($profile->meal_count) ? $profile->meal_count : '3 comidas principales';

        // Construir el prompt
        $prompt = "Actúa como un nutricionista experto llamado Frutia. Crea un plan de comidas personalizado para el siguiente perfil:
        - Nombre preferido: {$preferredName}
        - Objetivo: {$profile->goal}
        - Edad: {$profile->age} años
        - Sexo: {$profile->sex}
        - Peso: {$profile->weight} kg
        - Altura: {$profile->height} cm
        - Estilo de dieta: {$profile->dietary_style}. IMPORTANTE: Todas las recetas deben ser estrictamente compatibles con esta dieta, evitando ingredientes no permitidos (por ejemplo, granos, legumbres, o frutas altas en azúcar para Keto).
        - Nivel de actividad: {$profile->activity_level}
        - Deportes practicados: {$sport}
        - Frecuencia de entrenamiento: {$profile->training_frequency}
        - Número de comidas al día: {$mealCount}
        - Horarios de comidas: Desayuno ({$breakfastTime}), Almuerzo ({$lunchTime}), Cena ({$dinnerTime})
        - Presupuesto: {$profile->budget}
        - Frecuencia de comer fuera: {$profile->eats_out}
        - Alimentos que NO le gustan: {$dislikedFoods}
        - Alergias: {$allergies}
        - Condición médica: {$medicalCondition}
        - Estilo de comunicación preferido: {$communicationStyle}
        - Dificultades con la dieta: {$dietDifficulties}
        - Motivaciones para seguir la dieta: {$dietMotivations}

        Escribe un 'summary_title' corto y motivador usando el nombre preferido ({$preferredName}) con un tono acorde al estilo de comunicación ({$communicationStyle}).

        En lugar de un único 'summary_text', genera 3 o 4 textos separados (summary_text_1, summary_text_2, summary_text_3, y opcionalmente summary_text_4) que expliquen por qué este plan es ideal para el usuario. Cada texto debe ser breve (2-3 oraciones), legible con espacios adecuados, y cubrir una parte específica del resumen:
        - summary_text_1: Describe el objetivo ({$profile->goal}), nivel de actividad ({$profile->activity_level}), deportes ({$sport}), y cómo el plan apoya estos aspectos.
        - summary_text_2: Explica cómo se consideraron las características personales (edad, sexo, peso, altura), preferencias (estilo de dieta, presupuesto, horarios), y restricciones (alimentos no deseados, alergias, condiciones médicas).
        - summary_text_3: Detalla cómo el plan aborda las dificultades ({$dietDifficulties}) y se alinea con las motivaciones ({$dietMotivations}).
        - summary_text_4 (opcional): Resalta beneficios adicionales (energía, digestión, bienestar) y un mensaje motivador para cerrar.
        Asegúrate de usar un formato legible con espacios adecuados y saltos de línea entre oraciones para mejorar la presentación.

        Genera un plan de comidas para {$mealCount}. Para cada comida (desayuno, almuerzo, cena, y snacks si {$mealCount} incluye snacks), genera 2 opciones variadas. Para cada opción, incluye:
        - Descripción breve
        - Calorías aproximadas
        - Tiempo de preparación (en minutos)
        - Lista de ingredientes (respetando {$profile->dietary_style}, {$profile->budget}, {$dislikedFoods}, {$allergies})
        - Instrucciones paso a paso

        Añade 5 recomendaciones específicas que aborden las dificultades ({$dietDifficulties}), las motivaciones ({$dietMotivations}), y la frecuencia de comer fuera ({$profile->eats_out}). Por ejemplo, sugiere opciones compatibles con {$profile->dietary_style} para comer fuera o estrategias para mantener la constancia.

        IMPORTANTE: Devuelve la respuesta únicamente en formato JSON válido, sin texto introductorio ni explicaciones adicionales, con esta estructura exacta:
        {
          \"summary_title\": \"¡Hola {$preferredName}! Tu plan está listo para ayudarte a brillar.\",
          \"summary_text_1\": \"Este plan está diseñado para tu objetivo de {$profile->goal}, adaptado a tu {$profile->activity_level} nivel de actividad y deportes ({$sport}). Las recetas te ayudarán a alcanzar tus metas con energía y consistencia.\",
          \"summary_text_2\": \"Con {$profile->weight} kg, {$profile->height} cm, y {$profile->age} años, las recetas respetan tu {$profile->dietary_style}, presupuesto ({$profile->budget}), horarios ({$breakfastTime}, {$lunchTime}, {$dinnerTime}), {$dislikedFoods}, y {$allergies}. Todo está personalizado para ti.\",
          \"summary_text_3\": \"Abordamos tus dificultades ({$dietDifficulties}) con estrategias prácticas y te mantenemos motivado con {$dietMotivations} para que sigas adelante.\",
          \"summary_text_4\": \"Este plan mejorará tu energía, digestión y bienestar general. ¡Estás a un paso de sentirte increíble!\",
          \"meal_plan\": {
            \"desayuno\": [
              {
                \"opcion\": \"[Nombre de la receta]\",
                \"details\": {
                  \"description\": \"[Descripción breve de la receta]\",
                  \"calories\": 0,
                  \"prep_time_minutes\": 0,
                  \"ingredients\": [\"[Ingrediente 1]\", \"[Ingrediente 2]\"],
                  \"instructions\": [\"[Paso 1]\", \"[Paso 2]\"]
                }
              },
              {
                \"opcion\": \"[Nombre de la receta]\",
                \"details\": {
                  \"description\": \"[Descripción breve de la receta]\",
                  \"calories\": 0,
                  \"prep_time_minutes\": 0,
                  \"ingredients\": [\"[Ingrediente 1]\", \"[Ingrediente 2]\"],
                  \"instructions\": [\"[Paso 1]\", \"[Paso 2]\"]
                }
              }
            ],
            \"almuerzo\": [
              {
                \"opcion\": \"[Nombre de la receta]\",
                \"details\": {
                  \"description\": \"[Descripción breve de la receta]\",
                  \"calories\": 0,
                  \"prep_time_minutes\": 0,
                  \"ingredients\": [\"[Ingrediente 1]\", \"[Ingrediente 2]\"],
                  \"instructions\": [\"[Paso 1]\", \"[Paso 2]\"]
                }
              },
              {
                \"opcion\": \"[Nombre de la receta]\",
                \"details\": {
                  \"description\": \"[Descripción breve de la receta]\",
                  \"calories\": 0,
                  \"prep_time_minutes\": 0,
                  \"ingredients\": [\"[Ingrediente 1]\", \"[Ingrediente 2]\"],
                  \"instructions\": [\"[Paso 1]\", \"[Paso 2]\"]
                }
              }
            ],
            \"cena\": [
              {
                \"opcion\": \"[Nombre de la receta]\",
                \"details\": {
                  \"description\": \"[Descripción breve de la receta]\",
                  \"calories\": 0,
                  \"prep_time_minutes\": 0,
                  \"ingredients\": [\"[Ingrediente 1]\", \"[Ingrediente 2]\"],
                  \"instructions\": [\"[Paso 1]\", \"[Paso 2]\"]
                }
              },
              {
                \"opcion\": \"[Nombre de la receta]\",
                \"details\": {
                  \"description\": \"[Descripción breve de la receta]\",
                  \"calories\": 0,
                  \"prep_time_minutes\": 0,
                  \"ingredients\": [\"[Ingrediente 1]\", \"[Ingrediente 2]\"],
                  \"instructions\": [\"[Paso 1]\", \"[Paso 2]\"]
                }
              }
            ],
            \"snacks\": [
              {
                \"opcion\": \"[Nombre del snack]\",
                \"details\": {
                  \"description\": \"[Descripción breve del snack]\",
                  \"calories\": 0,
                  \"prep_time_minutes\": 0,
                  \"ingredients\": [\"[Ingrediente 1]\", \"[Ingrediente 2]\"],
                  \"instructions\": [\"[Paso 1]\", \"[Paso 2]\"]
                }
              },
              {
                \"opcion\": \"[Nombre del snack]\",
                \"details\": {
                  \"description\": \"[Descripción breve del snack]\",
                  \"calories\": 0,
                  \"prep_time_minutes\": 0,
                  \"ingredients\": [\"[Ingrediente 1]\", \"[Ingrediente 2]\"],
                  \"instructions\": [\"[Paso 1]\", \"[Paso 2]\"]
                }
              }
            ]
          },
          \"recomendaciones\": [
            \"[Recomendación 1 personalizada]\",
            \"[Recomendación 2 personalizada]\",
            \"[Recomendación 3 personalizada]\",
            \"[Recomendación 4 personalizada]\",
            \"[Recomendación 5 personalizada]\"
          ]
        }";

        // Debug: Registrar el prompt enviado a OpenAI
        Log::debug('Prompt enviado a OpenAI', [
            'user_id' => $user->id,
            'prompt' => $prompt
        ]);

        try {
            // Debug: Registrar antes de enviar la solicitud a OpenAI
            Log::info('Enviando solicitud a OpenAI para generar plan', [
                'user_id' => $user->id,
                'model' => 'gpt-4o',
                'temperature' => 0.7
            ]);

            $response = Http::withToken(env('OPENAI_API_KEY'))
                ->timeout(120)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o',
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.7,
                ]);

            if ($response->failed()) {
                Log::error('Error en la respuesta de OpenAI', [
                    'user_id' => $user->id,
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return response()->json(['message' => 'Error al contactar al servicio de IA.'], 502);
            }

            $responseData = $response->json();
            $planContent = $responseData['choices'][0]['message']['content'];

            // Debug: Registrar la respuesta cruda de OpenAI
            Log::debug('Respuesta cruda de OpenAI', [
                'user_id' => $user->id,
                'response' => $planContent
            ]);

            $planData = json_decode($planContent, true);

            if (json_last_error() !== JSON_ERROR_NONE || !isset($planData['meal_plan']) || !isset($planData['summary_text_1']) || !isset($planData['summary_text_2']) || !isset($planData['summary_text_3'])) {
                Log::error('El JSON de OpenAI no es válido o no tiene la estructura esperada', [
                    'user_id' => $user->id,
                    'content' => $planContent,
                    'json_error' => json_last_error_msg()
                ]);
                return response()->json(['message' => 'Respuesta inesperada del servicio de IA.'], 502);
            }

            // Debug: Registrar el plan decodificado
            Log::debug('Plan decodificado correctamente', [
                'user_id' => $user->id,
                'plan_data' => $planData
            ]);

            // Desactivar cualquier plan activo previo
            $deactivated = MealPlan::where('user_id', $user->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            // Debug: Registrar planes desactivados
            Log::info('Planes previos desactivados', [
                'user_id' => $user->id,
                'deactivated_count' => $deactivated
            ]);

            // Crear nuevo plan activo
            $mealPlan = MealPlan::create([
                'user_id' => $user->id,
                'plan_data' => $planData,
                'is_active' => true,
            ]);

            // Debug: Registrar plan creado
            Log::info('Nuevo plan alimenticio creado', [
                'user_id' => $user->id,
                'meal_plan_id' => $mealPlan->id,
                'created_at' => $mealPlan->created_at
            ]);

            return response()->json([
                'message' => 'Plan alimenticio generado con éxito',
                'data' => $mealPlan
            ], 201);

        } catch (\Exception $e) {
            Log::error('Excepción en generateAndStorePlan', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Ocurrió un error interno al generar el plan.'], 500);
        }
    }

    /**
     * Obtiene el plan de alimentación activo y más reciente para el usuario autenticado.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrentPlan(Request $request)
    {
        $user = $request->user();

        Log::debug('Intentando obtener plan actual para el usuario', ['user_id' => $user->id]);

        $mealPlan = MealPlan::where('user_id', $user->id)
            ->where('is_active', true)
            ->latest('created_at') // Cambiado de 'generated_at' a 'created_at'
            ->first();

        if (!$mealPlan) {
            Log::info('No se encontró un plan activo para el usuario', ['user_id' => $user->id]);
            return response()->json(['message' => 'No se encontró un plan de alimentación activo para este usuario.'], 404);
        }

        Log::info('Plan activo encontrado para el usuario', [
            'user_id' => $user->id,
            'meal_plan_id' => $mealPlan->id
        ]);

        return response()->json([
            'message' => 'Plan alimenticio obtenido con éxito',
            'data' => $mealPlan->plan_data // Devolvemos el JSON almacenado en plan_data
        ], 200);
    }
}