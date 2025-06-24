<?php
// en app/Models/StreakLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StreakLog extends Model
{
    use HasFactory;

    // Es una buena práctica añadir esto, aunque no es estrictamente necesario para tu caso actual
    protected $fillable = ['user_id', 'completed_at'];
}