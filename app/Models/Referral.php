<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'affiliate_id',
        'new_user_id',
        'sale_amount',
        'commission_earned',
        'payout_status',
    ];

    /**
     * Get the affiliate who generated this referral.
     */
    public function affiliate()
    {
        return $this->belongsTo(Affiliate::class);
    }

    /**
     * Get the new user who was referred.
     */
    public function newUser()
    {
        return $this->belongsTo(User::class, 'new_user_id');
    }
}