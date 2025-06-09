<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id',
        'total',
        'status',
        'type',
        'reference_id',
        'preference_id',
        'payment_id',
        'payment_details',
    ];

    protected $casts = [
        'payment_details' => 'array',
        'total' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}