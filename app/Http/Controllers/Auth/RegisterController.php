<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Collector accounts. Anyone can make one; nothing about an account grants
 * admin rights — `is_admin` is not fillable, and only AdminUserSeeder sets it.
 */
class RegisterController extends Controller
{
    public function show(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->string('name')->trim()->value(),
            'email' => $request->email(),
            'password' => $request->string('password')->value(),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('collection.index')
            ->with('status', 'Welcome! Add cars to your collection from any car page.');
    }
}
