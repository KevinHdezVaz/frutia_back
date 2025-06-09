<?php

namespace App\Http\Controllers;

use App\Models\Field;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FieldManagementController extends Controller
{
    public function index()
    {
        $fields = Field::all()->map(function ($field) {
            // Calcular horarios disponibles dinámicamente
            $field->calculated_available_hours = $field->getAvailableHours();
            return $field;
        });

        return view('laravel-examples.field-management', compact('fields'));
    }

    public function edit($id)
    {
        try {
            \Log::info('Entrando al método edit con ID: ' . $id);
            
            $field = Field::findOrFail($id);
            \Log::info('Field encontrado:', ['field' => $field->toArray()]);
            
            // Calcular horarios disponibles dinámicamente
            $field->calculated_available_hours = $field->getAvailableHours();

            if (!view()->exists('laravel-examples.field-edit')) {
                \Log::error('La vista field-edit no existe');
                throw new \Exception('La vista no fue encontrada');
            }
            
            return view('laravel-examples.field-edit', compact('field'));
        } catch (\Exception $e) {
            \Log::error('Error en edit method:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->route('field-management')
                ->with('error', 'Error al cargar la cancha: ' . $e->getMessage());
        }
    }

    public function create()
    {
        return view('laravel-examples.field-addCancha');
    }

    public function store(Request $request)
    {
        \Log::debug('Datos recibidos:', $request->all());
        try {
            $validated = $request->validate([
                'name' => 'required|string',
                'description' => 'required|string',
                'price_per_match' => 'required|numeric',
                'types' => 'required|array|min:1',
                'types.*' => 'in:fut5,fut7,fut11',
                'latitude' => 'nullable|numeric',
                'municipio' => 'required|string',
                'longitude' => 'nullable|numeric',
                'is_active' => 'sometimes',
                'amenities' => 'nullable|array',
                'images.*' => 'nullable|file|mimes:jpeg,png,jpg,gif|max:2048'
            ]);

            // Preparar los datos
            $validatedData = [
                'name' => $validated['name'],
                'description' => $validated['description'],
                'price_per_match' => $validated['price_per_match'],
                'municipio' => $validated['municipio'],
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'is_active' => $request->has('is_active') ? 1 : 0,
                'types' => json_encode($validated['types']),
                'amenities' => json_encode($request->input('amenities', []))
            ];

            // Procesar imágenes
            if ($request->hasFile('images')) {
                $imagePaths = [];
                foreach ($request->file('images') as $image) {
                    $path = $image->store('fields', 'public');
                    $imagePaths[] = Storage::url($path);
                }
                $validatedData['images'] = json_encode($imagePaths);
            }

            // Crear la cancha
            $field = Field::create($validatedData);

            return redirect()->route('field-management')->with('success', 'Cancha creada exitosamente');
        } catch (\Exception $e) {
            \Log::error('Error en store:', [
                'mensaje' => $e->getMessage(),
                'línea' => $e->getLine(),
                'archivo' => $e->getFile(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->withInput()->withErrors(['error' => 'Error al crear la cancha: ' . $e->getMessage()]);
        }
    }

    public function update(Request $request, $id)
    {
        Log::info('Datos recibidos en update para field_id: ' . $id, $request->all());
        $field = Field::findOrFail($id);

        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'types' => 'required|array',
                'types.*' => 'in:fut5,fut7,fut11',
                'description' => 'required|string',
                'municipio' => 'required|string|max:255',
                'price_per_match' => 'required|numeric|min:0',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'amenities' => 'nullable|array',
                'amenities.*' => 'in:Vestuarios,Estacionamiento,Iluminación nocturna',
                'images' => 'nullable|array',
                'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            $data = $request->only([
                'name', 'description', 'municipio', 'price_per_match', 'latitude',
                'longitude'
            ]);

            // Convertir tipos y amenities a JSON
            if ($request->has('types')) {
                $data['types'] = json_encode($request->input('types'));
            }

            if ($request->has('amenities')) {
                $data['amenities'] = json_encode($request->input('amenities'));
            }

            // Manejar imágenes
            if ($request->has('existing_images')) {
                $data['images'] = json_encode($request->input('existing_images'));
            }

            if ($request->hasFile('images')) {
                $newImages = [];
                foreach ($request->file('images') as $image) {
                    $path = $image->store('fields', 'public');
                    $newImages[] = "/storage/$path";
                }
                $currentImages = json_decode($data['images'] ?? $field->images ?? '[]', true) ?? [];
                $data['images'] = json_encode(array_merge($currentImages, $newImages));
            }

            if ($request->has('removed_images')) {
                $removedImages = json_decode($request->input('removed_images'), true) ?? [];
                $currentImages = json_decode($field->images ?? '[]', true) ?? [];
                $remainingImages = array_diff($currentImages, $removedImages);
                $data['images'] = json_encode(array_values($remainingImages));
            }

            $data['is_active'] = $request->has('is_active') ? 1 : 0;

            // Actualizar el campo
            $field->update($data);

            return redirect()->route('field-management')->with('success', 'Cancha actualizada exitosamente');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Error de validación al actualizar cancha: ' . $e->getMessage(), $e->errors());
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            Log::error('Error al actualizar cancha: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Error al actualizar la cancha: ' . $e->getMessage())->withInput();
        }
    }

    public function destroy($id)
    {
        try {
            // Buscar la cancha con sus relaciones
            $field = Field::with([
                'equipoPartidos.ratings',
                'equipoPartidos.teams',
                'equipoPartidos.players',
                'bookings'
            ])->findOrFail($id);

            // Usar una transacción para asegurar consistencia
            DB::transaction(function () use ($field) {
                // 1. Eliminar las relaciones de los partidos
                foreach ($field->equipoPartidos as $partido) {
                    $partido->ratings()->delete();
                    $partido->teams()->delete();
                    $partido->players()->delete();
                }

                // 2. Eliminar los partidos (equipo_partidos)
                $field->equipoPartidos()->delete();

                // 3. Eliminar las reservaciones (bookings)
                $field->bookings()->delete();

                // 4. Eliminar la cancha
                $field->delete();
            });

            return redirect()->route('field-management')
                ->with('success', 'Cancha y todos sus registros relacionados eliminados exitosamente');
        } catch (\Exception $e) {
            Log::error('Error al eliminar cancha:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->route('field-management')
                ->with('error', 'Error al eliminar la cancha: ' . $e->getMessage());
        }
    }
}