<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use App\Models\ProductTienda;
use Illuminate\Support\Facades\Storage;

class ProductTiendaController extends Controller
{
  

    public function index()
    {
        $products = ProductTienda::all();
        $setting = Setting::where('key', 'show_store')->first();
        $show_store = $setting ? ($setting->value === 'true') : true; // Valor por defecto: true

        return view('laravel-examples.field-listTienda', compact('products', 'show_store'));
    }
    public function apiIndex()
    {
        $products = ProductTienda::all();
        return response()->json($products);
    }

    public function create()
    {
        return view('laravel-examples.field-addTienda');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric',
            'images.*' => 'required|image|mimes:jpeg,png,jpg|max:10240',
            'colors' => 'nullable|string', // Lista de colores separados por comas
            'sizes' => 'nullable|string',  // Lista de tallas separadas por comas
            'units' => 'nullable|integer|min:1', // Cantidad máxima de piezas
        ]);

        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $imagePaths[] = $image->store('products', 'public');
            }
        }

        $product = ProductTienda::create([
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'images' => $imagePaths,
            'colors' => $request->colors ? array_map('trim', explode(',', $request->colors)) : [],
            'sizes' => $request->sizes ? array_map('trim', explode(',', $request->sizes)) : [],
            'units' => $request->units ?? 1, // Por defecto 1 pieza si no se especifica
            'numbers' => null, // No se define en el panel, lo elige el usuario en el frontend
        ]);

        return redirect()->route('product.index')->with('success', 'Producto creado correctamente.');
    }

    public function edit($id)
    {
        $product = ProductTienda::findOrFail($id);
        return view('laravel-examples.field-editTienda', compact('product'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'price' => 'sometimes|numeric',
            'images.*' => 'sometimes|image|mimes:jpeg,png,jpg|max:10240',
            'colors' => 'nullable|string',
            'sizes' => 'nullable|string',
            'units' => 'nullable|integer|min:1',
        ]);

        $product = ProductTienda::findOrFail($id);

        if ($request->has('name')) {
            $product->name = $request->name;
        }
        if ($request->has('description')) {
            $product->description = $request->description;
        }
        if ($request->has('price')) {
            $product->price = $request->price;
        }

        if ($request->hasFile('images')) {
            if ($product->images) {
                foreach ($product->images as $image) {
                    Storage::disk('public')->delete($image);
                }
            }
            $imagePaths = [];
            foreach ($request->file('images') as $image) {
                $imagePaths[] = $image->store('products', 'public');
            }
            $product->images = $imagePaths;
        }

        if ($request->has('colors')) {
            $product->colors = $request->colors ? array_map('trim', explode(',', $request->colors)) : [];
        }
        if ($request->has('sizes')) {
            $product->sizes = $request->sizes ? array_map('trim', explode(',', $request->sizes)) : [];
        }
        if ($request->has('units')) {
            $product->units = $request->units ?? 1;
        }
        // No tocamos 'numbers' aquí, lo define el usuario en el frontend

        $product->save();

        return redirect()->route('product.index')->with('success', 'Producto actualizado correctamente.');
    }

    public function destroy($id)
    {
        $product = ProductTienda::findOrFail($id);

        if ($product->images) {
            foreach ($product->images as $image) {
                Storage::disk('public')->delete($image);
            }
        }

        $product->delete();

        return redirect()->route('product.index')->with('success', 'Producto eliminado correctamente.');
    }
}