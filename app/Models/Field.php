<?php

namespace App\Models;

use DateTime;
use App\Models\Booking;
use App\Models\DailyMatch;
use Illuminate\Database\Eloquent\Model;

class Field extends Model
{
    protected $table = 'fields';

    protected $fillable = [
        'name', 'description', 'location', 'price_per_hour', 'duration_per_match', 
        'latitude', 'longitude', 'is_active', 'types', 'municipio', 
        'amenities', 'images', 'price_per_match'
    ];
  
    protected $casts = [
        'amenities' => 'array',
        'images' => 'array',
        'types' => 'array',  
        'is_active' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'price_per_match' => 'decimal:4'
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class, 'field_id');
    }
   
    public function equipoPartidos()
    {
        return $this->hasMany(DailyMatch::class, 'field_id');
    }

    /**
     * Calcula los horarios disponibles para la cancha basados en los partidos existentes.
     * @param \DateTime|string $date Fecha para la que calcular los horarios disponibles (opcional).
     * @return array
     */
    public function getAvailableHours($date = null)
    {
        // Definir los horarios por defecto (puedes ajustar según tu lógica)
        $defaultHours = [
            'monday' => ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00'],
            'tuesday' => ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00'],
            'wednesday' => ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00'],
            'thursday' => ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00'],
            'friday' => ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00'],
            'saturday' => ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00'],
            'sunday' => ['08:00', '09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00'],
        ];

        // Filtrar partidos por fecha si se proporciona
        $query = $this->equipoPartidos();
        if ($date) {
            $date = $date instanceof \DateTime ? $date->format('Y-m-d') : $date;
            $query->where('schedule_date', $date);
        }

        // Obtener los horarios ocupados
        $occupiedSlots = $query->get()->map(function ($partido) {
            return [
                'date' => $partido->schedule_date,
                'start_time' => $partido->start_time,
                'end_time' => $partido->end_time,
            ];
        })->all();

        // Calcular horarios disponibles por día
        $available = [];
        foreach ($defaultHours as $day => $hours) {
            $available[$day] = $hours; // Inicialmente, todos los horarios están disponibles

            // Filtrar horarios ocupados para este día
            foreach ($occupiedSlots as $slot) {
                $slotDate = new DateTime($slot['date']);
                $slotDay = strtolower($slotDate->format('l')); // Día de la semana en inglés (monday, tuesday, etc.)

                if ($slotDay === $day) {
                    $startTime = substr($slot['start_time'], 0, 5); // Formato HH:MM
                    $endTime = substr($slot['end_time'], 0, 5);

                    // Eliminar los horarios ocupados
                    $available[$day] = array_filter($available[$day], function ($hour) use ($startTime, $endTime) {
                        return $hour < $startTime || $hour >= $endTime;
                    });
                }
            }

            // Reindexar el array para evitar huecos
            $available[$day] = array_values($available[$day]);
        }

        return $available;
    }
}