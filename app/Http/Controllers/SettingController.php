<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function showStore(Request $request)
    {
        $setting = Setting::where('key', 'show_store')->first();
        return response()->json(['show_store' => $setting ? $setting->value : 'true']);
    }

    public function showTournaments(Request $request)
    {
        $setting = Setting::where('key', 'show_tournaments')->first();
        return response()->json(['show_tournaments' => $setting ? $setting->value : 'true']);
    }

    public function update(Request $request)
    {
        $request->validate([
            'show_store' => 'sometimes|in:0,1',
            'show_tournaments' => 'sometimes|in:0,1',
        ]);

        if ($request->has('show_store')) {
            Setting::updateOrCreate(
                ['key' => 'show_store'],
                ['value' => $request->input('show_store', 0) ? 'true' : 'false']
            );
        }

        if ($request->has('show_tournaments')) {
            Setting::updateOrCreate(
                ['key' => 'show_tournaments'],
                ['value' => $request->input('show_tournaments', 0) ? 'true' : 'false']
            );
        }

        return redirect()->back()->with('success', 'Configuración actualizada correctamente');
    }
}