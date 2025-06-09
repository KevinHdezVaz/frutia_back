<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserStats extends Model
{
    protected $fillable = [
        'user_id',
        'total_matches',
        'average_rating',
        'mvp_count'
    ];

    protected $casts = [
        'total_matches' => 'integer',
        'average_rating' => 'decimal:2',
        'mvp_count' => 'integer'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}