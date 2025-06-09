<?php
namespace App\Http\Controllers;
use App\Models\Stories;  // Mantenemos Stories
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StoryController extends Controller
{
    // Para la vista web del panel administrativo
    public function index()
    {
        $stories = Stories::with('administrator')
            ->orderBy('created_at', 'desc')
            ->paginate(10);
            
        return view('laravel-examples.field-liststory', compact('stories'));
    }

  
    public function create()
    {
        return view('laravel-examples.field-addstory');
    }
 
    public function update(Request $request, Stories $story)  // Cambiado a Stories
    {
        if (auth()->guard('admin')->id() !== $story->administrator_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized'
            ], 403);
        }
        
        $request->validate([
            'title' => 'string|max:255',
            'is_active' => 'boolean'
        ]);
        
        $story->update($request->only(['title', 'is_active']));
        
        return response()->json([
            'status' => 'success',
            'data' => $story
        ]);
    } 

 
 public function store(Request $request)
{
    try {
        $request->validate([
            'title' => 'required|string|max:255',
            'image' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'video' => 'nullable|mimetypes:video/mp4|max:10240'
        ]);

        // Asegurarnos de que el directorio existe
        Storage::disk('public')->makeDirectory('stories');

        $imageName = time() . '_' . str_replace(' ', '_', $request->file('image')->getClientOriginalName());
        
        // Guardar la imagen y obtener la ruta relativa
        $imagePath = $request->file('image')->storeAs(
            'stories', 
            $imageName, 
            'public'
        );

        // Log para depuración
        \Log::info('Imagen guardada:', [
            'path' => $imagePath,
            'full_path' => Storage::disk('public')->path($imagePath),
            'exists' => Storage::disk('public')->exists($imagePath)
        ]);

        $story = Stories::create([
            'title' => $request->title,
            'image_url' => $imagePath,
            'administrator_id' => auth()->guard('admin')->id(),
            'expires_at' => now()->addHours(24),
            'is_active' => true
        ]);

        return redirect()->route('admin.stories.index')
            ->with('success', 'Historia creada exitosamente');

    } catch (\Exception $e) {
        \Log::error('Error creating story: ' . $e->getMessage());
        return back()->withErrors(['error' => 'Error al crear la historia'])->withInput();
    }
}


public function getStoriesApi()
{
    try {
        $stories = Stories::with('administrator')
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($story) {
                // Construir la URL correcta basada en la estructura de tu servidor
                $imageUrl = $story->image_url 
                    ? asset('storage/' . $story->image_url)  // Esto generará la URL correcta
                    : null;
                
                return [
                    'id' => $story->id,
                    'title' => $story->title,
                    'image_url' => $imageUrl,
                    'video_url' => $story->video_url ? asset('storage/' . $story->video_url) : null,
                    'is_active' => $story->is_active,
                    'expires_at' => $story->expires_at,
                    'administrator' => $story->administrator
                ];
            });

        // Agregar log para depuración
        \Log::info('Primera historia URL:', [
            'image_url' => $stories->first()->image_url ?? 'No hay historias',
            'full_url' => asset('storage/' . ($stories->first()->image_url ?? '')),
            'storage_path' => storage_path('app/public/stories'),
            'public_path' => public_path('storage/stories')
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $stories
        ]);
    } catch (\Exception $e) {
        \Log::error('Error fetching stories: ' . $e->getMessage());
        return response()->json([
            'status' => 'error',
            'message' => 'Error al cargar las historias'
        ], 500);
    }
}
 
}