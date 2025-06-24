<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'height', 'weight', 'age', 'sex', 'goal', 'activity_level', 'sport',
        'training_frequency', 'meal_count', 'breakfast_time', 'lunch_time', 'dinner_time',
        'dietary_style', 'budget', 'cooking_habit', 'eats_out', 'disliked_foods',
        'has_allergies', 'allergies', 'has_medical_condition', 'medical_condition',
        'communication_style', 'motivation_style', 'preferred_name', 'things_to_avoid',
        'name', 'plan_setup_complete', 'diet_difficulties', 'diet_motivations',
        'pais',  'racha_actual',
        'ultima_fecha_racha' // <--- ¡Añade esta línea!
    ];

   
    protected $casts = [
        'sport' => 'array',
        'diet_difficulties' => 'array',
        'diet_motivations' => 'array',
        'has_allergies' => 'boolean',
        'has_medical_condition' => 'boolean',
        'plan_setup_complete' => 'boolean',
        'ultima_fecha_racha' => 'date', // Laravel convertirá este campo a un objeto Carbon automáticamente
    ];

    public function actualizarRacha()
    {
        $hoyEnUTC = Carbon::now('UTC');
        $ultimaFecha = $this->ultima_fecha_racha ? Carbon::parse($this->ultima_fecha_racha) : null;

        // Si ya se completó hoy, no hacer nada.
        if ($ultimaFecha && $ultimaFecha->isSameDay($hoyEnUTC)) {
            return;
        }

        DB::transaction(function () use ($hoyEnUTC, $ultimaFecha) {
            // Calculamos la diferencia de días desde la última vez.
            $diffInDays = $ultimaFecha ? $hoyEnUTC->diffInDays($ultimaFecha) : 999;

            // Si la diferencia es de 1 día (ayer), la racha continúa normalmente.
            if ($diffInDays == 1) {
                $this->racha_actual++;
            } else {
                // Si es cualquier otro día, la racha se rompió y empieza de nuevo en 1.
                // La lógica de "perder" la racha visualmente se manejará en el frontend.
                $this->racha_actual = 1;
            }

            $this->ultima_fecha_racha = $hoyEnUTC->toDateString();
            $this->save();

            // Registrar el día completado en el historial.
            DB::table('streak_logs')->updateOrInsert(
                [
                    'user_id' => $this->user_id,
                    'completed_at' => $hoyEnUTC->toDateString(),
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        });
    }


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}