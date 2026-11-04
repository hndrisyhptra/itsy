<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountAccess;
use App\Services\AccountPlacement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public static function passwordRules(bool $required = true, int $minimum = 12): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'confirmed', Password::min($minimum)->letters()->numbers(),
            function ($attribute, $value, $fail) {
                if (strlen($value) > 72) {
                    $fail('Kata sandi maksimal 72 byte.');
                }
            }];
    }

    private function setupAllowed(Request $request): bool
    {
        return ! User::exists() && in_array($request->ip(), ['127.0.0.1', '::1'], true);
    }

    public function show(Request $request)
    {
        return view('auth.login', ['setup' => $this->setupAllowed($request)] + app(AccountPlacement::class)->options());
    }

    public function login(Request $request, AccountAccess $access)
    {
        $request->merge(['username' => Str::lower((string) $request->input('username'))]);
        $input = $request->validate(['username' => 'required|string|max:50', 'password' => 'required|string|max:1024', 'remember' => 'sometimes|boolean']);
        $key = 'area3-login:'.hash('sha256', $input['username'].'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.']);
        }
        if (! Auth::attempt(['username' => $input['username'], 'password' => $input['password'], 'active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'Username atau kata sandi tidak sesuai, atau akun belum memiliki akses aktif.']);
        }
        if (! $access->context($request->user())) {
            Auth::logout();
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'Username atau kata sandi tidak sesuai, atau akun belum memiliki akses aktif.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('prototype.dashboard'));
    }

    public function setup(Request $request, AccountAccess $access)
    {
        abort_unless($this->setupAllowed($request), 403);
        $request->merge(['email' => Str::lower((string) $request->input('email')), 'username' => Str::lower((string) $request->input('username'))]);
        $placement = app(AccountPlacement::class);
        $input = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email', 'password' => self::passwordRules()] + $placement->rules());
        $placement->validate($input);
        $user = Cache::lock('area3-account-provisioning', 15)->block(5, fn () => DB::transaction(function () use ($input, $access) {
            abort_if(User::exists(), 403, 'Akun pertama sudah dibuat. Silakan masuk.');
            $branches = DB::table('branches')->where('is_active', true)->whereNull('deleted_at')->pluck('id_branch')->all();
            abort_if(! $branches, 422, 'Master Branch aktif belum tersedia.');
            $user = User::create(['name' => trim($input['name']), 'email' => Str::lower($input['email']), 'password' => $input['password']] + app(AccountPlacement::class)->fields($input));
            $access->assign($user, 'SUPERADMIN', $branches);

            return $user->refresh();
        }));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('accounts.index')->with('status', 'Akun administrator siap. Tambahkan akun sesuai lima level approval dan Finance untuk menguji alur.');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
