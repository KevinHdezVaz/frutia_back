<?php

namespace App\Http\Controllers;

use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WalletController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function index()
    {
        $user = Auth::user();
        $wallet = $user->wallet ?: ['balance' => 0, 'points' => 0];
        $transactions = $user->wallet?->transactions()->latest()->get() ?? [];
        return response()->json([
            'balance' => $wallet['balance'],
            'points' => $wallet['points'],
            'referral_code' => $user->referral_code, // Añadimos el referral_code del usuario
            'transactions' => $transactions,
        ]);
    }

    public function deposit(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:1']);
        $this->walletService->deposit(Auth::user(), $request->amount, 'Depósito manual');
        return response()->json(['message' => 'Depósito realizado con éxito']);
    }

    public function addReferralPoints(Request $request)
    {
        $request->validate(['referral_code' => 'required|string']);
        $this->walletService->addPoints(Auth::user(), 250, 'Puntos por referido');
        return response()->json(['message' => 'Puntos agregados por referido']);
    }
}