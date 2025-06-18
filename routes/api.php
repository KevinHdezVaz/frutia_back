<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecipeImageController;

 

 Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::post('/google-login', [AuthController::class, 'googleLogin']);

    
// Rutas de Google
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('login/google', [AuthController::class, 'loginWithGoogle']);

 
Route::middleware('auth:sanctum')->group(function () {
    
     Route::get('/profile', [AuthController::class, 'profile']);
    
    // Ruta para cerrar sesión.
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/profile', [ProfileController::class, 'storeOrUpdate']);
    Route::get('/plan/current', [PlanController::class, 'getCurrentPlan']); // <--- NUEVA RUTA

    Route::post('/plan/generate', [PlanController::class, 'generateAndStorePlan']);
    Route::get('/recipes/{recipe}', [RecipeController::class, 'show']);
    Route::post('/recipes/generate-image', [RecipeImageController::class, 'generateImageForOption']);

    Route::get('/plan/ingredients', [PlanController::class, 'getIngredientsList']);

  

    Route::post('/summarize', [ChatController::class, 'summarizeConversation']);

    Route::post('/update-name', [ChatController::class, 'updateUserName']);


     
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