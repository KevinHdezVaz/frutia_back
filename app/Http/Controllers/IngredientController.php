<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class IngredientController extends Controller
{
    public function showImage($name)
    {
        try {
            $requestedName = trim($name);
            $allIngredientNames = Ingredient::pluck('name')->toArray();
            usort($allIngredientNames, fn($a, $b) => strlen($b) <=> strlen($a));

            $bestMatch = null;
            foreach ($allIngredientNames as $dbName) {
                if (stripos($requestedName, $dbName) !== false) {
                    $bestMatch = $dbName;
                    break;
                }
            }

            if (!$bestMatch) {
                Log::warning("No se encontró coincidencia en BD para: '{$requestedName}'");
                return response()->json(['message' => 'Ingrediente base no encontrado'], 404);
            }

            $ingredient = Ingredient::where('name', $bestMatch)->firstOrFail();

            if ($ingredient->local_image_path && Storage::disk('public')->exists($ingredient->local_image_path)) {
                return Storage::disk('public')->response($ingredient->local_image_path);
            }

            Log::info("No hay imagen local para {$ingredient->name}. Buscando en Unsplash.");
            $unsplashUrl = $this->fetchImageFromUnsplash($ingredient->name);

            if (!$unsplashUrl) {
                return response()->json(['message' => 'Imagen no disponible en proveedor externo'], 404);
            }
            
            // --- ¡AQUÍ ESTÁ LA CORRECCIÓN FINAL! ---
            // Reemplazamos file_get_contents por el cliente HTTP de Laravel, que es más robusto.
            $response = Http::get($unsplashUrl);

            if ($response->successful()) {
                $imageContents = $response->body();
                $filename = 'ingredient_images/' . Str::slug($ingredient->name) . '-' . uniqid() . '.png';
                Storage::disk('public')->put($filename, $imageContents);

                $ingredient->local_image_path = $filename;
                $ingredient->save();

                Log::info("Imagen para {$ingredient->name} descargada y guardada en: {$filename}");
                return Storage::disk('public')->response($filename);
            } else {
                // Si la descarga falla, lanzamos un error.
                throw new \Exception('Fallo al descargar la imagen desde Unsplash. Status: ' . $response->status());
            }

        } catch (\Throwable $e) {
            Log::error("EXCEPCIÓN CRÍTICA en IngredientController para '{$name}': " . $e->getMessage());
            return response()->json(['message' => 'Error interno del servidor al procesar la imagen.'], 500);
        }
    }
  // --- VAMOS A MODIFICAR ESTA FUNCIÓN CON LOGS DETALLADOS ---
  private function fetchImageFromUnsplash(string $keyword): ?string
  {
      $accessKey = env('UNSPLASH_ACCESS_KEY');
      Log::info("[UNSPLASH_DEBUG] Intentando buscar: '{$keyword}'. Clave de API encontrada: " . ($accessKey ? 'Sí' : 'No'));

      if (!$accessKey) {
          return null;
      }

      $searchTerm = strtolower(trim(preg_replace('/\([^)]*\)/', '', $keyword)));

      try {
          Log::info("[UNSPLASH_DEBUG] Realizando petición a Unsplash para: '{$searchTerm}'");
          $response = Http::withHeaders(['Authorization' => 'Client-ID ' . $accessKey])
              ->timeout(15) // Añadimos un timeout para no esperar indefinidamente
              ->get('https://api.unsplash.com/search/photos', [
                  'query' => $searchTerm,
                  'per_page' => 1,
                  'orientation' => 'squarish'
              ]);

          Log::info("[UNSPLASH_DEBUG] Respuesta de Unsplash recibida. Status: " . $response->status());

          if ($response->successful() && !empty($response->json('results'))) {
              $url = $response->json('results.0.urls.small');
              Log::info("[UNSPLASH_DEBUG] ¡Éxito! URL encontrada: " . $url);
              return $url;
          }
          
          Log::warning("[UNSPLASH_DEBUG] La petición a Unsplash no fue exitosa o no hubo resultados. Body: " . $response->body());
          return null;

      } catch (\Exception $e) {
          // Este log es el más importante si el problema es de conexión
          Log::error("[UNSPLASH_DEBUG] EXCEPCIÓN al contactar Unsplash: " . $e->getMessage());
          return null;
      }
  }
}
