<?php

namespace App\Models;

use App\Models\User;
use App\Models\Field;
use App\Models\DailyMatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Booking extends Model {
    use HasFactory;

    protected $fillable = [
        'user_id',
        'field_id',
        'start_time',
        'end_time',
        'total_price',
        'status',
        'payment_status',
        'payment_id',
        'players_needed',
        'allow_joining',
        'daily_match_id', // Vincula con equipo_partidos
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'is_recurring' => 'boolean',
        'allow_joining' => 'boolean',
        'player_list' => 'array',
    ];

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function field() {
        return $this->belongsTo(Field::class);
    }

    public function dailyMatch() {
        return $this->belongsTo(DailyMatch::class, 'daily_match_id');
    }
}