<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MealPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan_data',
        'nutritional_data',
        'personalization_data',
        'validation_data',
        'generation_method',
        'is_active'
    ];

    protected $casts = [
        'plan_data' => 'array',
        'nutritional_data' => 'array',
        'personalization_data' => 'array',
        'validation_data' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Relación con el usuario
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope para planes activos
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope para planes validados
     */
    public function scopeValidated($query)
    {
        return $query->whereJsonContains('validation_data->is_valid', true);
    }

    /**
     * Verificar si el plan pasó validación
     */
    public function isValidated(): bool
    {
        return $this->validation_data['is_valid'] ?? false;
    }

    /**
     * Obtener método de generación
     */
    public function getGenerationMethodAttribute($value)
    {
        return $value ?? 'ai';
    }

    /**
     * Obtener total de macros del plan
     */
    public function getTotalMacros(): array
    {
        return $this->validation_data['total_macros'] ?? [
            'protein' => 0,
            'carbs' => 0,
            'fats' => 0,
            'calories' => 0
        ];
    }

    /**
     * Obtener warnings de validación
     */
    public function getValidationWarnings(): array
    {
        return $this->validation_data['warnings'] ?? [];
    }

    /**
     * Verificar si fue generado por IA
     */
    public function isAIGenerated(): bool
    {
        return in_array($this->generation_method, ['ai', 'ai_validated']);
    }

    /**
     * Verificar si es plan determinístico
     */
    public function isDeterministic(): bool
    {
        return $this->generation_method === 'deterministic_backup';
    }
}