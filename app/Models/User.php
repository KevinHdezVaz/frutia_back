<?php

namespace App\Models;

use App\Models\MealPlan;
use App\Models\StreakLog;
use App\Models\UserProfile;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Los atributos que se pueden asignar de forma masiva.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'firebase_uid', // Añadido para Google Sign-In

    ];

    /**
     * Los atributos que deben ocultarse en las respuestas JSON.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed', // Laravel 10+ se encarga del hash automáticamente
    ];

    /**
     * Define la relación: un Usuario tiene un Perfil.
     */
    public function profile()
    {
        return $this->hasOne(UserProfile::class);
    }

    public function streakLogs()
    {
        return $this->hasMany(StreakLog::class);
    }
    
    /**
     * Define la relación: un Usuario tiene un Plan de Comidas activo.
     */
    public function activePlan()
    {
        return $this->hasOne(MealPlan::class)->where('is_active', true);
    }


    
}