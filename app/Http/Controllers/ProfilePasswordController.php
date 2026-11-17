<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProfilePasswordController extends Controller
{
    public function update(Request $request)
    {
        $input = $request->validate([
            'current_password' => ['required', 'string', 'max:1024', 'not_regex:/\x00/'],
            'password' => [...AuthController::passwordRules(true, 6), 'different:current_password', 'not_regex:/\x00/'],
        ], [
            'current_password.required' => 'Masukkan kata sandi saat ini.',
            'password.confirmed' => 'Konfirmasi kata sandi baru tidak sesuai.',
            'password.different' => 'Kata sandi baru harus berbeda dari kata sandi saat ini.',
            'current_password.not_regex' => 'Kata sandi saat ini mengandung karakter yang tidak didukung.',
            'password.not_regex' => 'Kata sandi baru mengandung karakter yang tidak didukung.',
        ]);

        // Resolve the account from authentication only and recheck its current hash under lock.
        $user = DB::transaction(function () use ($request, $input) {
            $user = User::whereKey($request->user()->getAuthIdentifier())->lockForUpdate()->firstOrFail();
            abort_unless($user->active, 401, 'Akses akun berakhir. Silakan masuk kembali.');
            if (! Hash::check($input['current_password'], $user->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini tidak sesuai.']);
            }
            $user->password = $input['password'];
            $user->remember_token = Str::random(60);
            $user->save();

            return $user;
        });

        Auth::setUser($user);
        // Retain this tab's CSRF token; rotate the session ID and current session password hash.
        // auth.session rejects other sessions with the old hash on their next request.
        $request->session()->migrate(true);
        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $user->getAuthPassword());
        Log::notice('Area3 account password changed', ['user_id' => $user->getKey()]);

        return response()->json(['message' => 'Kata sandi berhasil diubah.']);
    }
}
