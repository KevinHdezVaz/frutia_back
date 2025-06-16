<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Kreait\Firebase\Auth as FirebaseAuth;
use Illuminate\Support\Facades\Validator;
use Kreait\Firebase\Factory;

class AuthController extends Controller
{
    /**
     * Registra un nuevo usuario.
     */
    public function __construct()
    {
        // Configuración de Firebase
        $serviceAccountPath = storage_path('app/firebase/frutia-fd201-firebase-adminsdk-fbsvc-46154c6523.json');
        
        if (!file_exists($serviceAccountPath)) {
            throw new \RuntimeException("Archivo de configuración de Firebase no encontrado");
        }

        $this->firebaseAuth = (new Factory)
            ->withServiceAccount($serviceAccountPath)
            ->createAuth();
    }

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

 


    public function googleLogin(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_token' => 'required|string',
            ]);
    
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }
    
            $idToken = $request->input('id_token');
            
            \Log::info("Token recibido: " . substr($idToken, 0, 30) . "...");
    
            try {
                $verifiedIdToken = $this->firebaseAuth->verifyIdToken($idToken, true);
                $claims = $verifiedIdToken->claims();
                
                $firebaseUid = $claims->get('sub');
                $email = $claims->get('email');
                $name = $claims->get('name') ?? 'Usuario Google';
                $audience = $claims->get('aud');
    
                \Log::info("Token decodificado:", [
                    'issuer' => $claims->get('iss'),
                    'audience' => $audience,
                    'email' => $email,
                    'firebase_uid' => $firebaseUid
                ]);
    
                $serviceAccount = json_decode(file_get_contents(
                    storage_path('app/firebase/frutia-fd201-firebase-adminsdk-fbsvc-46154c6523.json')
                ), true);
                
                $expectedAudience = $serviceAccount['project_id'] ?? null;
                
                if (!$expectedAudience) {
                    throw new \Exception("Project ID no configurado en service account");
                }
    
                $audienceMatch = false;
                if (is_array($audience)) {
                    $audienceMatch = in_array($expectedAudience, $audience);
                } else {
                    $audienceMatch = ($audience === $expectedAudience);
                }
    
                if (!$audienceMatch) {
                    throw new \Exception(sprintf(
                        "Audience no coincide. Esperado: %s, Recibido: %s",
                        $expectedAudience,
                        is_array($audience) ? json_encode($audience) : $audience
                    ));
                }
    
                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $name,
                        'password' => Hash::make(uniqid()),
                        'firebase_uid' => $firebaseUid,
                        'auth_provider' => 'google'
                    ]
                );
    
                
                $token = $user->createToken('auth_token')->plainTextToken;
    
                return response()->json([
                    'success' => true,
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->nombre,
                        'email' => $user->email,
                    ],
                    'token' => $token,
                ]);
    
            } catch (\Throwable $e) {
                \Log::error("Error al verificar token:", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'token_sample' => substr($idToken, 0, 50)
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Error en verificación de token',
                    'error' => $e->getMessage(),
                ], 401);
            }
    
        } catch (\Exception $e) {
            \Log::error("Error general en googleLogin: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error en autenticación con Google',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    


    
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();
        
        return response()->json(['message' => 'Cierre de sesión exitoso']);
    }
 
    public function profile(Request $request)
{
    // Cargamos el usuario con su perfil Y su plan activo
    return response()->json($request->user()->load(['profile', 'activePlan']));
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