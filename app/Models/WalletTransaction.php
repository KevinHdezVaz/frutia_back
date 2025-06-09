<?php
namespace App\Models;

use App\Models\Wallet;
use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = ['wallet_id', 'type', 'amount', 'points', 'description'];

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }
}