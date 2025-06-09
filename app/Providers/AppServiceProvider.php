<?php

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MercadoPagoService::class, function ($app) {
            return new MercadoPagoService();
        });
        
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Crear directorios necesarios para el chat
        try {
            if (!Storage::disk('public')->exists('chat_images')) {
                Storage::disk('public')->makeDirectory('chat_images');
            }
            if (!Storage::disk('public')->exists('chat_files')) {
                Storage::disk('public')->makeDirectory('chat_files');
            }
        } catch (\Exception $e) {
            \Log::error('Error al crear directorios de chat: ' . $e->getMessage());
        }
    }
}