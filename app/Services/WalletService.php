<?php
namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function deposit(User $user, float $amount, string $description)
    {
        return DB::transaction(function () use ($user, $amount, $description) {
            $wallet = $user->wallet ?: Wallet::create(['user_id' => $user->id]);
            $wallet->increment('balance', $amount);
            return $wallet->transactions()->create([
                'type' => 'deposit',
                'amount' => $amount,
                'description' => $description,
            ]);
        });
    }

    public function withdraw(User $user, float $amount, string $description)
    {
        return DB::transaction(function () use ($user, $amount, $description) {
            $wallet = $user->wallet;
            if (!$wallet || $wallet->balance < $amount) {
                throw new \Exception('Saldo insuficiente');
            }
            $wallet->decrement('balance', $amount);
            return $wallet->transactions()->create([
                'type' => 'withdrawal',
                'amount' => $amount,
                'description' => $description,
            ]);
        });
    }

    public function addPoints(User $user, int $points, string $description)
    {
        return DB::transaction(function () use ($user, $points, $description) {
            $wallet = $user->wallet ?: Wallet::create(['user_id' => $user->id]);
            $wallet->increment('points', $points);
            return $wallet->transactions()->create([
                'type' => 'points_earned',
                'points' => $points,
                'description' => $description,
            ]);
        });
    }

    public function refundBooking(User $user, float $amount, string $bookingReference)
    {
        return $this->deposit($user, $amount, "Reembolso por cancelación de reserva #$bookingReference");
    }

    public function refundMatch(User $user, float $amount, string $matchReference)
    {
        return $this->deposit($user, $amount, "Reembolso por partido cancelado #$matchReference");
    }

    public function refundLeaveMatch(User $user, float $amount, string $matchReference)
    {
        return $this->deposit($user, $amount, "Reembolso por abandono de equipo #$matchReference");
    }

    
}