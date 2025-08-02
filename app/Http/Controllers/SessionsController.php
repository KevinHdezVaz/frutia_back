<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Administrator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash; // <-- Asegúrate de importar Hash
use Illuminate\Support\Facades\Log;   // <-- Asegúrate de importar Log

class SessionsController extends Controller
{
    public function create()
    {
        return view('session.login-session');
    }

    public function store(Request $request)
    {
        $attributes = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        // --- INICIO DE LOGS DE DEPURACIÓN ---

        Log::info('================ INICIO INTENTO DE LOGIN ADMIN ================');
        Log::info('Email proporcionado:', ['email' => $attributes['email']]);

        // 1. Buscamos al administrador manualmente en la base de datos.
        $admin = Administrator::where('email', $attributes['email'])->first();

        if (!$admin) {
            Log::error('DEBUG PASO 1: FALLO. No se encontró ningún administrador con ese email en la tabla `administrators`.');
        } else {
            Log::info('DEBUG PASO 1: ÉXITO. Administrador encontrado:', ['id' => $admin->id, 'email' => $admin->email]);

            // 2. Verificamos la contraseña manualmente.
            if (Hash::check($attributes['password'], $admin->password)) {
                Log::info('DEBUG PASO 2: ÉXITO. La contraseña proporcionada es CORRECTA.');
            } else {
                Log::error('DEBUG PASO 2: FALLO. La contraseña proporcionada es INCORRECTA.');
            }
        }
        
        // 3. Intentamos el login automático de Laravel.
        Log::info('DEBUG PASO 3: Ejecutando Auth::guard("admin")->attempt()');
        
        if (Auth::guard('admin')->attempt($attributes)) {
            Log::info('DEBUG PASO 3: ÉXITO. Auth::guard()->attempt() devolvió TRUE.');
            $request->session()->regenerate();
            
            return redirect()->route('affiliates.index')
                             ->with(['success' => 'Has iniciado sesión correctamente.']);
        }
        
        Log::error('DEBUG PASO 3: FALLO. Auth::guard()->attempt() devolvió FALSE.');
        Log::info('================ FIN INTENTO DE LOGIN ADMIN ================');

        // --- FIN DE LOGS DE DEPURACIÓN ---

        return back()->withErrors([
            'email' => 'Las credenciales proporcionadas no coinciden con nuestros registros.'
        ])->withInput($request->only('email'));
    }

    public function destroy(Request $request)
    {
        Auth::guard('admin')->logout();
        
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return redirect('/login')
               ->with(['success' => 'You\'ve been logged out.']);
    }
}