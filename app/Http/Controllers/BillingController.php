<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        setlocale(LC_TIME, 'es_ES.utf8', 'es_ES', 'es');

        
        // Obtener el parámetro de mes
        $month = $request->input('month');
    
        // Consulta base
        $query = Order::with('user')
            ->orderBy('created_at', 'desc');
    
        // Filtrar por mes si está presente
        if ($month) {
            $query->whereMonth('created_at', $month);
        }
    
        // Obtener todas las transacciones para calcular los totales
        $allOrders = $query->get();
    
        // Calcular totales para el resumen
        $totalMatchPayments = $allOrders->where('type', 'match')->sum('total');
        $totalBonusPayments = $allOrders->where('type', 'bono')->sum('total');
        $totalBookingPayments = $allOrders->where('type', 'booking')->sum('total');
        $totalCompleted = $allOrders->where('status', 'completed')->sum('total');
        $totalPending = $allOrders->where('status', 'pending')->sum('total');
    
        // Paginar las transacciones para mostrarlas en la vista
        $orders = $query->paginate(10);
    
        return view('billing', compact('orders', 'totalMatchPayments', 'totalBonusPayments', 'totalBookingPayments', 'totalCompleted', 'totalPending'));
    }
}