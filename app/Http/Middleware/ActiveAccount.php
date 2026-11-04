<?php

namespace App\Http\Middleware;

use App\Services\AccountAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        $account = app(AccountAccess::class)->context($request->user());
        if (! $account) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['message' => 'Akses akun berakhir. Silakan masuk kembali.'], 401)
                : redirect()->route('login')->with('status', 'Akses akun tidak aktif. Hubungi administrator.');
        }
        $request->attributes->set('area3.account', $account);

        return $next($request);
    }
}
