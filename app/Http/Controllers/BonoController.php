<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Bono;
use App\Models\Order;
use App\Models\UserBono;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Services\MercadoPagoService;

class BonoController extends Controller
{
    protected $mercadoPagoService;

    public function __construct(MercadoPagoService $mercadoPagoService)
    {
        $this->mercadoPagoService = $mercadoPagoService;
        $this->middleware('auth:admin')->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
    }

    public function getBonos()
{
    try {
        $bonos = Bono::where('is_active', true)->get();
        Log::info('Bonos obtenidos para API', ['count' => $bonos->count()]);
        return response()->json($bonos);
    } catch (\Exception $e) {
        Log::error('Error al obtener bonos para API', ['error' => $e->getMessage()]);
        return response()->json(['message' => 'Error al obtener los bonos'], 500);
    }
}

    public function index()
    {
        $bonos = Bono::all();
        return view('laravel-examples.field-listBono', compact('bonos'));
    }

    public function create()
    {
        return view('bonos.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'titulo' => 'required|string|max:255',
            'tipo' => 'required|string|max:255',
            'descripcion' => 'required|string',
            'precio' => 'required|numeric|min:0',
            'duracion_dias' => 'required|integer|min:1',
            'usos_totales' => 'nullable|integer|min:0',
            'caracteristicas' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        try {
            $validated['caracteristicas'] = array_filter(array_map('trim', explode(',', $request->caracteristicas)));
            Bono::create($validated);

            return redirect()->route('bonos.index')->with('success', 'Bono creado exitosamente');
        } catch (\Exception $e) {
            Log::error('Error al crear bono: ' . $e->getMessage());
            return back()->with('error', 'Error al crear el bono');
        }
    }

    public function edit(Bono $bono)
    {
        return view('laravel-examples.field-editBono', compact('bono'));
    }

   
    
    public function update(Request $request, Bono $bono)
{
    Log::info('Iniciando actualización de bono', ['bono_id' => $bono->id, 'request_data' => $request->all()]);

    try {
        $validated = $request->validate([
            'titulo' => 'required|string|max:255',
            'tipo' => 'required|string|max:255',
            'descripcion' => 'required|string',
            'precio' => 'required|numeric|min:0',
            'duracion_dias' => 'required|integer|min:1',
            'usos_totales' => 'nullable|integer|min:0',
            'caracteristicas' => 'nullable|string',
            'is_active' => 'nullable|in:on,off', // Ajustado para aceptar "on" o "off"
        ]);

        Log::info('Datos validados exitosamente', ['validated_data' => $validated]);

        // Convertir "on" a booleano para la base de datos
        $validated['is_active'] = $request->input('is_active') === 'on';
        $validated['caracteristicas'] = array_filter(array_map('trim', explode(',', $request->caracteristicas ?? '')));
        
        Log::info('Características procesadas e is_active convertido', [
            'caracteristicas' => $validated['caracteristicas'],
            'is_active' => $validated['is_active']
        ]);

        $bono->update($validated);
        Log::info('Bono actualizado en la base de datos', ['bono_id' => $bono->id]);

        return redirect()->route('bonos.index')->with('success', 'Bono actualizado exitosamente');
    } catch (\Illuminate\Validation\ValidationException $e) {
        Log::error('Error de validación al actualizar bono', [
            'bono_id' => $bono->id,
            'errors' => $e->errors(),
            'request_data' => $request->all()
        ]);
        return back()->withErrors($e->errors())->withInput();
    } catch (\Exception $e) {
        Log::error('Error general al actualizar bono', [
            'bono_id' => $bono->id,
            'error_message' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString()
        ]);
        return back()->with('error', 'Error al actualizar el bono');
    }
}


    public function destroy(Bono $bono)
    {
        try {
            $bono->delete();
            return redirect()->route('bonos.index')->with('success', 'Bono eliminado exitosamente');
        } catch (\Exception $e) {
            Log::error('Error al eliminar bono: ' . $e->getMessage());
            return back()->with('error', 'Error al eliminar el bono');
        }
    }

    public function show(Bono $bono)
    {
        return response()->json($bono);
    }

    public function createPreference(Request $request)
    {
        $validated = $request->validate([
            'bono_id' => 'required|exists:bonos,id',
        ]);

        try {
            $bono = Bono::findOrFail($validated['bono_id']);
            $user = auth()->user();

            $preferenceData = [
                'items' => [
                    [
                        'title' => $bono->titulo,
                        'quantity' => 1,
                        'unit_price' => (float)$bono->precio,
                        'currency_id' => 'MXN',
                    ],
                ],
                'back_urls' => [
                    'success' => 'footconnect://checkout/success',
                    'failure' => 'footconnect://checkout/failure',
                    'pending' => 'footconnect://checkout/pending',
                ],
                'auto_return' => 'approved',
                'external_reference' => "bono_{$bono->id}_user_{$user->id}",
                'notification_url' => 'https://proyect.aftconta.mx/api/webhook/mercadopago',
                'payer' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ];

            $preference = $this->mercadoPagoService->createPreference($preferenceData);

            return response()->json([
                'init_point' => $preference['init_point'],
                'preference_id' => $preference['id'],
            ]);
        } catch (\Exception $e) {
            Log::error('Error al crear preferencia de bono: ' . $e->getMessage());
            return response()->json(['message' => 'Error al crear preferencia de pago'], 500);
        }
    }

    public function comprar(Request $request)
    {
        $validated = $request->validate([
            'bono_id' => 'required|exists:bonos,id',
            'payment_id' => 'required|string',
            'order_id' => 'required|exists:orders,id',
        ]);
    
        try {
            return \DB::transaction(function() use ($validated, $request) {
                $existingUserBono = UserBono::where('payment_id', $validated['payment_id'])->first();
                if ($existingUserBono) {
                    return response()->json([
                        'message' => 'Este pago ya ha sido procesado anteriormente',
                        'user_bono' => $existingUserBono->load('bono')
                    ], 200);
                }
    
                $existingActiveBonoByType = UserBono::where('user_id', auth()->id())
                    ->where('bono_id', $validated['bono_id'])
                    ->where('estado', 'activo')
                    ->where('fecha_vencimiento', '>', now())
                    ->first();
                    
                if ($existingActiveBonoByType) {
                    return response()->json([
                        'message' => 'Ya tienes un bono activo de este tipo',
                        'user_bono' => $existingActiveBonoByType->load('bono')
                    ], 200);
                }
    
                $order = Order::findOrFail($validated['order_id']);
                if ($order->type !== 'bono' || $order->reference_id != $validated['bono_id']) {
                    return response()->json(['message' => 'Orden inválida para este bono'], 422);
                }
    
                if ($order->payment_id !== $validated['payment_id'] || $order->status !== 'completed') {
                    $paymentInfo = $this->mercadoPagoService->getPaymentInfo($validated['payment_id']);
                    if ($paymentInfo['status'] !== 'approved') {
                        return response()->json(['message' => 'El pago aún no ha sido aprobado'], 422);
                    }
                    $order->update([
                        'payment_id' => $validated['payment_id'],
                        'status' => 'completed',
                        'payment_details' => array_merge($order->payment_details ?? [], ['payment_info' => $paymentInfo]),
                    ]);
                }
    
                $bono = Bono::findOrFail($validated['bono_id']);
                $fechaCompra = now();
                $fechaVencimiento = $fechaCompra->copy()->addDays($bono->duracion_dias);
    
                $codigoReferencia = strtoupper(Str::random(8));
                while (UserBono::where('codigo_referencia', $codigoReferencia)->exists()) {
                    $codigoReferencia = strtoupper(Str::random(8));
                }
    
                $userBono = UserBono::create([
                    'user_id' => auth()->id(),
                    'bono_id' => $bono->id,
                    'fecha_compra' => $fechaCompra,
                    'fecha_vencimiento' => $fechaVencimiento,
                    'codigo_referencia' => $codigoReferencia,
                    'payment_id' => $validated['payment_id'],
                    'estado' => 'activo',
                    'usos_disponibles' => $bono->usos_totales ?? null,
                    'usos_totales' => $bono->usos_totales ?? null,
                ]);
    
                return response()->json([
                    'message' => 'Bono comprado exitosamente',
                    'user_bono' => $userBono->load('bono')
                ], 201);
            });
        } catch (\Exception $e) {
            Log::error('Error al comprar bono: ' . $e->getMessage());
            return response()->json(['message' => 'Error al procesar la compra del bono'], 500);
        }
    }

    public function misBonos()
    {
        $userBonos = UserBono::with('bono')
            ->where('user_id', auth()->id())
            ->where('estado', 'activo')
            ->where('fecha_vencimiento', '>=', now())
            ->orderBy('fecha_vencimiento')
            ->get();

        return response()->json($userBonos);
    }

    public function historialBonos()
    {
        $userBonos = UserBono::with('bono')
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($userBonos);
    }

    public function usarBono(Request $request)
    {
        $validated = $request->validate([
            'user_bono_id' => 'required|exists:user_bonos,id',
            'booking_id' => 'required|exists:bookings,id',
        ]);

        try {
            $userBono = UserBono::findOrFail($validated['user_bono_id']);
            
            if ($userBono->user_id !== auth()->id()) {
                return response()->json(['message' => 'Este bono no pertenece al usuario autenticado'], 403);
            }

            if ($userBono->estado !== 'activo' || $userBono->fecha_vencimiento < now()) {
                return response()->json(['message' => 'El bono ha expirado o no está activo'], 422);
            }

            if ($userBono->usos_disponibles !== null) {
                if ($userBono->usos_disponibles <= 0) {
                    return response()->json(['message' => 'El bono no tiene usos disponibles'], 422);
                }
                $userBono->usos_disponibles -= 1;
                $userBono->save();
            }

            BonoUse::create([
                'user_bono_id' => $userBono->id,
                'booking_id' => $validated['booking_id'],
                'fecha_uso' => now(),
            ]);

            return response()->json([
                'message' => 'Bono aplicado exitosamente a la reserva',
                'user_bono' => $userBono->refresh()
            ]);

        } catch (\Exception $e) {
            Log::error('Error al usar bono: ' . $e->getMessage());
            return response()->json(['message' => 'Error al aplicar el bono: ' . $e->getMessage()], 500);
        }
    }

    public function cancelarBono(UserBono $userBono)
    {
        if ($userBono->user_id !== auth()->id()) {
            return response()->json(['message' => 'No autorizado para cancelar este bono'], 403);
        }

        $userBono->update(['estado' => 'cancelado']);

        return response()->json([
            'message' => 'Bono cancelado exitosamente',
            'user_bono' => $userBono
        ]);
    }

    public function verificarCodigo(Request $request)
    {
        $request->validate(['codigo' => 'required|string']);

        $userBono = UserBono::where('codigo_referencia', $request->codigo)
            ->where('estado', 'activo')
            ->where('fecha_vencimiento', '>=', now())
            ->first();

        if (!$userBono) {
            return response()->json(['valid' => false, 'message' => 'Código de bono inválido o expirado']);
        }

        return response()->json(['valid' => true, 'user_bono' => $userBono->load('bono')]);
    }
}