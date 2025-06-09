<?php

namespace App\Models;

use App\Models\Booking;
use App\Models\UserBono;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BonoUse extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_bono_id',
        'booking_id',
        'fecha_uso',
    ];

    protected $casts = [
        'fecha_uso' => 'datetime',
    ];

    public function userBono()
    {
        return $this->belongsTo(UserBono::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}