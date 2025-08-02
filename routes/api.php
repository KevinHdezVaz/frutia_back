<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\StreakController;
use App\Http\Controllers\MealLogController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\RecipeImageController;
use App\Http\Controllers\AffiliateController;

 

 Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/google-login', [AuthController::class, 'googleLogin']);
 
 Route::post('/webhooks/mercadopago', [WebhookController::class, 'handleMercadoPago']);

 Route::get('/plans', [PaymentController::class, 'getPlans']);

    
// Rutas de Google
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('login/google', [AuthController::class, 'loginWithGoogle']);

 
Route::middleware('auth:sanctum')->group(function () {
    


    Route::post('/history/log', [MealLogController::class, 'store']); // Guardar una comida
    Route::get('/history', [MealLogController::class, 'index']);      // Obtener todo el historial
    Route::post('/affiliates/validate-code', [AffiliateController::class, 'validateCode']);


    Route::get('/user/name', [AuthController::class, 'getUserName']);
      // Ruta para actualizar el OneSignal Player ID
      Route::post('/user/update-onesignal-id', function (Request $request) {
        $user = Auth::user();
        $playerId = $request->input('onesignal_player_id');

        if (!$playerId) {
            return response()->json(['message' => 'onesignal_player_id es requerido'], 400);
        }

        if ($user->profile) {
            $user->profile->update(['onesignal_player_id' => $playerId]);
            return response()->json(['message' => 'Player ID actualizado con éxito.']);
        }

        return response()->json(['message' => 'Perfil de usuario no encontrado.'], 404);
    });

    
 
    

    Route::post('/payment/create-preference', [PaymentController::class, 'createPreference']);

    
     Route::get('/profile', [AuthController::class, 'profile']);
    
    // Ruta para cerrar sesión.
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/profile', [ProfileController::class, 'storeOrUpdate']);
    Route::get('/plan/current', [PlanController::class, 'getCurrentPlan']); // <--- NUEVA RUTA
     Route::get('/plan/status', [PlanController::class, 'getPlanStatus']); // <-- AÑADE ESTA LÍNEA AQUÍ

    Route::post('/plan/generate', [PlanController::class, 'generateAndStorePlan']);
    Route::get('/recipes/{recipe}', [RecipeController::class, 'show']);
    Route::post('/recipes/generate-image', [RecipeImageController::class, 'generateImageForOption']);

    Route::get('/plan/ingredients', [PlanController::class, 'getIngredientsList']);

  
    Route::post('/streak/complete-day', [StreakController::class, 'marcarDiaCompleto']);

    Route::post('/summarize', [ChatController::class, 'summarizeConversation']);

    Route::post('/update-name', [ChatController::class, 'updateUserName']);
 
    Route::get('/ingredient-image/{name}', [IngredientController::class, 'showImage'])->middleware('auth:sanctum');

 
     
    Route::prefix('chat')->group(function () {
        Route::post('/send-voice-message', [ChatController::class, 'sendVoiceMessage']);
       Route::post('/transcribe-audio', [ChatController::class, 'transcribeAudio']);
       Route::post('/process-audio', [ChatController::class, 'processAudio']); // Solo esta ruta
       Route::get('/sessions', [ChatController::class, 'getSessions']);
       Route::post('/sessions', [ChatController::class, 'saveChatSession']);
       Route::put('/sessions/{session}', [ChatController::class, 'saveSession']);
       Route::delete('/sessions/{session}', [ChatController::class, 'deleteSession']);
       Route::get('/sessions/{session}/messages', [ChatController::class, 'getSessionMessages']);
       Route::post('/send-message', [ChatController::class, 'sendMessage']);
       Route::post('/send-temporary-message', [ChatController::class, 'sendTemporaryMessage']);
       Route::post('/start-new-session', [ChatController::class, 'startNewSession']);
   });
   
    
});