<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\LumorahAIService;


class BodyAnalysisController extends Controller
{
 
public function analyze(Request $request)
{
    $request->validate([
        'image' => 'required|string', // Imagen en base64
        // ▼▼▼ CAMBIO AQUÍ ▼▼▼
        'text' => 'nullable|string|max:255' // Aceptamos un texto opcional
    ]);

    $service = new LumorahAiService(
        $request->user()->name,
        $request->user()->language ?? 'es',
        null,
        $request->user()->profile
    );

    // ▼▼▼ Y PASAMOS EL TEXTO AL SERVICIO ▼▼▼
    $result = $service->analyzeBodyImage($request->image, $request->text);

    return response()->json([
        'success' => !is_null($result['percentage']),
        'data' => $result,
        'session_id' => $request->session_id
    ]);
}
}