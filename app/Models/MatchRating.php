<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchRating extends Model
{
    protected $table = 'match_ratings';
    protected $fillable = [
        'match_id',
        'rated_user_id',
        'rater_user_id',
        'rating',
        'comment',
        'mvp_vote',
        'attitude_rating',
        'participation_rating'
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(DailyMatch::class, 'match_id', 'id');
    }

    public function ratedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rated_user_id', 'id');
    }

    public function raterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_user_id', 'id');
    }
}