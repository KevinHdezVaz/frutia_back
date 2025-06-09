<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Administrator;
use Illuminate\Support\Facades\Auth;

class SessionsController extends Controller
{
    public function create()
    {
        return view('session.login-session');
    }

    public function store(Request $request)
    {
        $attributes = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (Auth::guard('admin')->attempt($attributes)) {
            $request->session()->regenerate();
            
            return redirect()->intended('dashboard')
                           ->with(['success' => 'You are logged in.']);
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.'
        ])->withInput($request->only('email'));
    }

    public function destroy(Request $request)
    {
        Auth::guard('admin')->logout();
        
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return redirect('/login')
                ->with(['success' => 'You\'ve been logged out.']);
    }
}