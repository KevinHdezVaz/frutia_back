<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProfileVerification;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class VerificationController extends Controller
{
    // Listar todas las solicitudes de verificación
    public function index()
    {
        $verifications = ProfileVerification::with('user')->get();
        return view('laravel-examples.field-listverification', compact('verifications'));
    }

    // Mostrar detalles de una solicitud de verificación
    public function show($id)
    {
        $verification = ProfileVerification::with('user')->findOrFail($id);
        return view('laravel-examples.field-showverification', compact('verification'));
    }

    public function uploadDni(Request $request)
    {
        // Validar la solicitud
        $request->validate([
            'dni_image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', // Máximo 2MB
        ]);

        // Obtener el usuario autenticado
        $user = Auth::user();

        // Guardar la imagen en el almacenamiento
        $path = $request->file('dni_image')->store('dni_images', 'public');

        // Crear o actualizar la verificación de perfil
        $verification = ProfileVerification::updateOrCreate(
            ['user_id' => $user->id],
            ['dni_image_path' => $path, 'status' => 'pending']
        );

        // Retornar una respuesta JSON
        return response()->json([
            'success' => true,
            'message' => 'DNI subido correctamente.',
            'data' => $verification,
        ]);
    }
    

    public function update(Request $request, $id)
    {
        // Obtener la verificación
        $verification = ProfileVerification::findOrFail($id);
    
        // Validar el estado enviado en la solicitud
        $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);
    
        // Actualizar el estado de la verificación
        $verification->status = $request->status;
        $verification->save();
    
        // Obtener el usuario asociado con la verificación
        $user = $verification->user;
    
        // Actualizar el campo is_verified en la tabla users
        if ($request->status === 'approved') {
            $user->is_verified = 1; // Marcar como verificado
        } else {
            $user->is_verified = 0; // Marcar como no verificado
        }
    
        // Guardar los cambios en el usuario
        $user->save();
    
        // Redirigir con un mensaje de éxito
        return redirect()->route('admin.verifications.index')
                         ->with('success', 'Solicitud actualizada correctamente.');
    }
}