<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        // Obtener idioma del header 'Accept-Language'
        $locale = $request->header('Accept-Language', 'es');

        // Validar que sea un idioma soportado
        $supportedLocales = ['es', 'en'];

        if (!in_array($locale, $supportedLocales)) {
            $locale = 'es'; // Fallback a español
        }

        // Setear el locale de Laravel
        App::setLocale($locale);

        Log::info('Locale detectado', [
            'locale' => $locale,
            'user_id' => $request->user()?->id ?? 'guest'
        ]);

        return $next($request);
    }
}
