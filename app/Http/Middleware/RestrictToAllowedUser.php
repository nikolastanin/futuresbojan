<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RestrictToAllowedUser
{
    private const ALLOWED_EMAIL = 'bukvicbojan@gmail.com';

    public function handle(Request $request, Closure $next): mixed
    {
        if (Auth::check() && Auth::user()->email !== self::ALLOWED_EMAIL) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Access denied.',
            ]);
        }

        return $next($request);
    }
}
