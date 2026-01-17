<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\MealPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Log;
use App\Jobs\EnrichPlanWithPricesJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\PlanGeneration\NutritionalPlanService;
use App\Services\PlanGeneration\RecipeGenerationService;
use App\Services\PlanGeneration\PlanValidationService;

class GenerateUserPlanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;
    public $timeout = 400;
    public $tries = 2;

    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    public function handle()
    {
        Log::info('Iniciando GenerateUserPlanJob PERSONALIZADO', ['userId' => $this->userId]);

        $user = User::with('profile')->find($this->userId);
        if (!$user || !$user->profile) {
            Log::error('Usuario o perfil no encontrado.', ['userId' => $this->userId]);
            return;
        }

        $userName = $user->name ?? $user->profile->name ?? $user->profile->preferred_name ?? 'Usuario';
        Log::info('Nombre del usuario obtenido', ['userId' => $this->userId, 'name' => $userName]);
        
        $foodPreferences = [
            'proteins' => $user->profile->favorite_proteins ?? [],
            'carbs' => $user->profile->favorite_carbs ?? [],
            'fats' => $user->profile->favorite_fats ?? [],
            'fruits' => $user->profile->favorite_fruits ?? [],
        ];

        Log::info('Preferencias de alimentos extraídas', [
            'userId' => $this->userId,
            'preferences' => $foodPreferences
        ]);

        try {
            $nutritionalService = new NutritionalPlanService();
            $validationService = new PlanValidationService();
            $recipeService = new RecipeGenerationService();

            // PASO 1: Calcular macros siguiendo la metodología del PDF
            Log::info('Paso 1: Calculando TMB, GET y macros objetivo con perfil completo.', ['userId' => $user->id]);
            $nutritionalData = $nutritionalService->calculateCompleteNutritionalPlan($user->profile, $userName);

            // ⭐ AGREGAR PREFERENCIAS A nutritionalData
            $nutritionalData['food_preferences'] = $foodPreferences;

            $personalizationData = $nutritionalService->extractPersonalizationData($user->profile, $userName);

            // PASO 2: Generar plan con validación obligatoria
            Log::info('Paso 2: Generando plan nutricional ULTRA-PERSONALIZADO con validación.', ['userId' => $user->id]);
            $planData = $validationService->generateAndValidatePlan($user->profile, $nutritionalData, $userName);

            // PASO 3: Generar recetas si tiene suscripción activa
            if ($this->userHasActiveSubscription($user)) {
                Log::info('Paso 3: Generando recetas ultra-específicas - Usuario con suscripción activa.', ['userId' => $user->id]);
                $planWithRecipes = $recipeService->generatePersonalizedRecipes($planData, $user->profile, $nutritionalData);
            } else {
                Log::info('Paso 3: Omitiendo generación de recetas - Usuario en periodo de prueba.', ['userId' => $user->id]);
                $planWithRecipes = $recipeService->addTrialMessage($planData, $userName);
            }

            // PASO 4: Guardado del plan completo con datos de validación
            Log::info('Almacenando plan ultra-personalizado en la base de datos.', ['userId' => $user->id]);
            MealPlan::where('user_id', $user->id)->update(['is_active' => false]);

            $mealPlan = MealPlan::create([
                'user_id' => $user->id,
                'plan_data' => $planWithRecipes,
                'nutritional_data' => $nutritionalData,
                'personalization_data' => $personalizationData,
                'validation_data' => $planWithRecipes['validation_data'] ?? null,
                'generation_method' => $planWithRecipes['generation_method'] ?? 'ai',
                'is_active' => true,
            ]);

            // PASO 5: Despachar jobs de enriquecimiento
            Log::info('Despachando jobs de enriquecimiento.', ['mealPlanId' => $mealPlan->id]);
            EnrichPlanWithPricesJob::dispatch($mealPlan->id);
        //    GenerateRecipeImagesJob::dispatch($mealPlan->id)->onQueue('images');

            Log::info('Plan ULTRA-PERSONALIZADO generado exitosamente.', ['userId' => $user->id, 'mealPlanId' => $mealPlan->id]);
        } catch (\Exception $e) {
            Log::error('Excepción crítica en GenerateUserPlanJob', [
                'userId' => $this->userId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function userHasActiveSubscription(User $user): bool
    {
        return $user->subscription_status === 'active';
    }
}