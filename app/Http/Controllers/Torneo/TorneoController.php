<?php

namespace App\Http\Controllers\Torneo;

use App\Models\Equipo;
use App\Models\Torneo;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class TorneoController extends Controller
{
   

    public function index()
    {
        $torneos = Torneo::all();
        $show_tournaments = Setting::where('key', 'show_tournaments')->first()?->value === 'true';
        
        return view('laravel-examples.Torneos.field-listTorneo', compact('torneos', 'show_tournaments'));
    }

    public function create()
    {
        return view('laravel-examples.Torneos.field-addTorneo');
    }


    public function store(Request $request)
{
    try {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:255',
            'formato' => 'required|in:liga,eliminacion,grupos_eliminacion',
            'descripcion' => 'required|string|max:1000',
            'fecha_inicio' => 'required|date|after_or_equal:today',
            'fecha_fin' => 'required|date|after:fecha_inicio',
            'minimo_equipos' => 'required|integer|min:2',
            'maximo_equipos' => 'required|integer|min:2|gte:minimo_equipos',
            'cuota_inscripcion' => 'required|numeric|min:0',
            'premio' => 'nullable|string|max:255',
            'reglas' => 'nullable|array',
            'reglas.*' => 'nullable|string|max:500',
            'imagenes.*' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'estado' => 'nullable|in:abierto',
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'errors' => $validator->errors()
                ], 422);
            }
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        DB::beginTransaction();

        // Procesar las imágenes igual que antes
        $imagePaths = [];
        if ($request->hasFile('imagenes')) {
            foreach ($request->file('imagenes') as $imagen) {
                $path = $imagen->store('torneos', 'public');
                $imagePaths[] = $path;
            }
        }

        // Crear el torneo
        $torneoData = $request->except('imagenes');
        if (!empty($imagePaths)) {
            $torneoData['imagenesTorneo'] = json_encode($imagePaths);
        }
        $torneo = Torneo::create($torneoData);

        // Array completo de colores disponibles
        $todosLosColores = [
            ['nombre' => 'Verde', 'emoji' => '🟢'],
            ['nombre' => 'Rojo', 'emoji' => '🔴'],
            ['nombre' => 'Azul', 'emoji' => '🔵'],
            ['nombre' => 'Amarillo', 'emoji' => '💛'],
            ['nombre' => 'Naranja', 'emoji' => '🟠'],
            ['nombre' => 'Morado', 'emoji' => '💜'],
            ['nombre' => 'Negro', 'emoji' => '⚫'],
            ['nombre' => 'Blanco', 'emoji' => '⚪'],
            ['nombre' => 'Gris', 'emoji' => '⚪'],
            ['nombre' => 'Dorado', 'emoji' => '🟡'],
            ['nombre' => 'Plateado', 'emoji' => '⚪'],
            ['nombre' => 'Rosa', 'emoji' => '💗'],
            // Agrega más colores si es necesario
        ];

         // Asegurar que el estado está como string
   

        // Tomar solo los colores necesarios según maximo_equipos
        $coloresNecesarios = array_slice($todosLosColores, 0, $request->maximo_equipos);

        // Crear equipos con los colores seleccionados
        foreach ($coloresNecesarios as $color) {
            $equipo = Equipo::create([
                'nombre' => $color['nombre'],
                'color_uniforme' => $color['nombre'],
                'es_abierto' => true,
                'plazas_disponibles' => 7,
            ]);

            $torneo->equipos()->attach($equipo->id, [
                'estado' => 'aceptado',
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        DB::commit();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Torneo creado exitosamente',
                'torneo' => $torneo
            ], 201);
        }

        return redirect()->route('torneos.index')
            ->with('success', 'Torneo creado exitosamente.');

    } catch (\Exception $e) {
        DB::rollback();
        \Log::error('Error al crear torneo: ' . $e->getMessage());
        
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Error al crear el torneo',
                'error' => $e->getMessage()
            ], 500);
        }

        return redirect()->back()
            ->with('error', 'Error al crear el torneo: ' . $e->getMessage())
            ->withInput();
    }
}


    public function iniciarTorneo($id)
    {
        try {
            $torneo = Torneo::findOrFail($id);

            // Verificar número mínimo de equipos con jugadores
            $equiposActivos = $torneo->equipos()
                ->whereHas('miembros', function($query) {
                    $query->where('estado', 'activo');
                })
                ->count();

            if ($equiposActivos < $torneo->minimo_equipos) {
                return redirect()->back()
                    ->with('error', 'No se puede iniciar el torneo. No se cumple el mínimo de equipos con jugadores.');
            }

            // Actualizar estado del torneo
            $torneo->update(['estado' => 'en_progreso']);

            // Eliminar equipos sin jugadores
            $torneo->equipos()
                ->whereDoesntHave('miembros', function($query) {
                    $query->where('estado', 'activo');
                })
                ->delete();

            return redirect()->back()->with('success', 'Torneo iniciado exitosamente.');

        } catch (\Exception $e) {
            \Log::error('Error al iniciar torneo: ' . $e->getMessage());
            return redirect()->back()
                ->with('error', 'Error al iniciar el torneo: ' . $e->getMessage());
        }
    }



public function update(Request $request, $id)
{
    try {
        $torneo = Torneo::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'nombre' => 'sometimes|string|max:255',
            'formato' => 'sometimes|in:liga,eliminacion,grupos_eliminacion',
            'descripcion' => 'sometimes|string|max:1000',
            'fecha_inicio' => 'sometimes|date',
            'fecha_fin' => 'sometimes|date|after:fecha_inicio',
            'minimo_equipos' => 'sometimes|integer|min:2',
            'maximo_equipos' => 'sometimes|integer|min:2|gte:minimo_equipos',
            'cuota_inscripcion' => 'sometimes|numeric|min:0',
            'premio' => 'nullable|string|max:255',
            'reglas' => 'nullable|array',
            'reglas.*' => 'nullable|string|max:500',
            'imagenes.*' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'estado' => 'nullable|in:proximamente,abierto,en_progreso,completado,cancelado',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        // Mantener las imágenes existentes
        $imagePaths = $torneo->imagenesTorneo ? json_decode($torneo->imagenesTorneo, true) : [];

        // Procesar nuevas imágenes
        if ($request->hasFile('imagenes')) {
            foreach ($request->file('imagenes') as $imagen) {
                try {
                    $path = $imagen->store('torneos', 'public');
                    $imagePaths[] = $path;
                } catch (\Exception $e) {
                    \Log::error('Error al subir imagen:', ['error' => $e->getMessage()]);
                    return redirect()->back()->with('error', 'Error al subir la imagen.');
                }
            }
        }

        // Actualizar el torneo
        $torneoData = $request->except('imagenes');
        if (!empty($imagePaths)) {
            $torneoData['imagenesTorneo'] = json_encode($imagePaths);
        }

        $torneo->update($torneoData);

        return redirect()->route('torneos.index')->with('success', 'Torneo actualizado exitosamente.');
    } catch (\Exception $e) {
        \Log::error('Error al actualizar torneo: ' . $e->getMessage());
        return redirect()->back()
            ->with('error', 'Error al actualizar el torneo: ' . $e->getMessage())
            ->withInput();
    }
}




public function edit($id)
{
    $torneo = Torneo::findOrFail($id);
    return view('laravel-examples.Torneos.field-editTorneo', compact('torneo'));
}




    public function destroy($id)
    {
        try {
            $torneo = Torneo::findOrFail($id);

            // Eliminar imágenes asociadas
            if (!empty($torneo->imagenesTorneo)) {
                $imagePaths = json_decode($torneo->imagenesTorneo, true);
                foreach ($imagePaths as $imagePath) {
                    // Convertir URL a path relativo
                    $path = str_replace('/storage/', 'public/', $imagePath);
                    if (Storage::exists($path)) {
                        Storage::delete($path);
                    }
                }
            }

            $torneo->delete();

            return redirect()->route('torneos.index')->with('success', 'Torneo eliminado exitosamente.');

        } catch (\Exception $e) {
            \Log::error('Error al eliminar torneo: ' . $e->getMessage());
            return redirect()->back()
                ->with('error', 'Error al eliminar el torneo: ' . $e->getMessage());
        }
    }
}