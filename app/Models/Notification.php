<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'message', 
        'player_ids',
        'read',
 
    ];

    protected $casts = [
        'player_ids' => 'array',
    ];
}