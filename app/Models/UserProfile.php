<?php
namespace App\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserProfile extends Model
{
    use HasFactory;

    protected $appends = ['streak_history'];

   protected $fillable = [
    'onesignal_player_id',
    'user_id', 
    'height', 
    'weight', 
    'age', 
    'sex', 
    'goal', 
    'activity_level',
    'weekly_activity',
    'sport', 
    'training_frequency', 
    'meal_count', 
    'breakfast_time', 
    'lunch_time', 
    'dinner_time',
    'preferred_snack_time',
    'dietary_style', 
    'budget', 
    'cooking_habit', 
    'eats_out', 
    'disliked_foods',
    'has_allergies', 
    'allergies', 
    'has_medical_condition', 
    'medical_condition',
    'communication_style', 
    'motivation_style', 
    'preferred_name', 
    'things_to_avoid',
    'name', 
    'plan_setup_complete', 
    'diet_difficulties', 
    'diet_motivations',
    'pais', 
    'racha_actual', 
    'ultima_fecha_racha',
    
    // ⭐ AGREGAR ESTOS 4 NUEVOS CAMPOS
    'favorite_proteins',
    'favorite_carbs',
    'favorite_fats',
    'favorite_fruits',
];

 protected $casts = [
    'sport' => 'array',
    'diet_difficulties' => 'array',
    'diet_motivations' => 'array',
    'has_allergies' => 'boolean',
    'preferred_snack_time' => 'string',
    'has_medical_condition' => 'boolean',
    'plan_setup_complete' => 'boolean',
    'ultima_fecha_racha' => 'date',
    
    // ⭐ AGREGAR ESTOS 4 NUEVOS CASTS
    'favorite_proteins' => 'array',
    'favorite_carbs' => 'array',
    'favorite_fats' => 'array',
    'favorite_fruits' => 'array',
];

    public function streakLogs()
    {
        return $this->hasMany(StreakLog::class, 'user_id', 'user_id');
    }

    public function getStreakHistoryAttribute()
    {
        return $this->streakLogs()->pluck('completed_at');
    }

    public function actualizarRacha()
    {
        $hoy = Carbon::now();
        $ultimaFecha = $this->ultima_fecha_racha ? Carbon::parse($this->ultima_fecha_racha) : null;

        if ($ultimaFecha && $ultimaFecha->isSameDay($hoy)) {
            return;
        }

        DB::transaction(function () use ($hoy, $ultimaFecha) {
            $diffInDays = $ultimaFecha ? $hoy->diffInDays($ultimaFecha) : 999;

            if ($diffInDays == 1) {
                $this->racha_actual++;
            } else {
                $this->racha_actual = 1;
            }

            $this->ultima_fecha_racha = $hoy->toDateString();
            $this->save();

            DB::table('streak_logs')->updateOrInsert(
                [
                    'user_id' => $this->user_id,
                    'completed_at' => $hoy->toDateString(),
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
