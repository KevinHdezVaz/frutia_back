<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecipeImageController extends Controller
{
    public function generateImageForOption(Request $request)
    {
        $validated = $request->validate(['option_text' => 'required|string']);
        $optionText = $validated['option_text'];

        $imagePrompt = "Fotografía de comida realista de '{$optionText}', vista superior, plato blanco simple, fondo liso neutro (gris claro o beige), iluminación natural, estilo minimalista.";

        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->timeout(30)
            ->post('https://api.openai.com/v1/images/generations', [
                'model' => 'dall-e-3',
                'prompt' => $imagePrompt,
                'n' => 1,
                'size' => '512x512',
                'quality' => 'standard',
            ]);

        if ($response->failed()) {
            return response()->json(['message' => 'Error al generar la imagen.'], 502);
        }

        $imageUrl = $response->json()['data'][0]['url'];
        $imageContents = file_get_contents($imageUrl);
        $fileName = 'recipes/' . Str::random(40) . '.jpg';

        Storage::disk('public')->put($fileName, $imageContents);
        $publicUrl = Storage::disk('public')->url($fileName);

        return response()->json(['image_url' => $publicUrl]);
    }
}