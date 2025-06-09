<?php

namespace App\Http\Controllers;

use App\Models\CarouselImage;
use Illuminate\Http\Request;

class CarouselImageController extends Controller
{
    public function index()
    {
        $images = CarouselImage::all(['image_url']);
        \Log::info('Datos recuperados de carousel_images: ', $images->toArray()); // Agrega logging
        return response()->json($images);
    }
}