<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    /**
     * Registra un nuevo usuario.
     */
    public function register(Request $request)
    {
        // 1. Validar los datos básicos para el registro.
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
        ]);

        // 2. Hashear la contraseña por seguridad.
        $validated['password'] = Hash::make($validated['password']);

        // 3. Crear el usuario en la base de datos.
        $user = User::create($validated);

        // 4. Crear un token para que la app pueda iniciar sesión automáticamente.
        $token = $user->createToken('auth_token')->plainTextToken;

        // 5. Devolver el usuario y el token a la app Flutter.
        return response()->json([
            'user' => $user,
            'token' => $token,
        ], 201); // 201: Created
    }

    /**
     * Inicia sesión para un usuario existente.
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        $user = User::where('email', $validated['email'])->first();

        // Verificar que el usuario exista y la contraseña sea correcta.
        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401); // 401: Unauthorized
        }
    
        return response()->json([
            'user' => $user,
            'token' => $user->createToken('auth_token')->plainTextToken
        ]);
    }

 
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        
        return response()->json(['message' => 'Cierre de sesión exitoso']);
    }
 
    public function profile(Request $request)
    {
        // Carga al usuario JUNTO CON su relación de perfil.
        // Si el usuario no ha llenado el onboarding, 'profile' será null.
        return response()->json($request->user()->load('profile'));
    }
     
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Enlace de restablecimiento enviado.'], 200)
            : response()->json(['message' => 'No se pudo enviar el enlace.'], 400);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:6|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'token', 'password', 'password_confirmation'),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Contraseña restablecida exitosamente.'], 200)
            : response()->json(['message' => 'No se pudo restablecer la contraseña.'], 400);
    }
}