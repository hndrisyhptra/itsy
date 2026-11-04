<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountAccess;
use App\Services\AccountPlacement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->attributes->get('area3.account')['code'] === 'SUPERADMIN', 403);
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        return view('auth.accounts', ['users' => User::orderBy('name')->get(), 'access' => app(AccountAccess::class)]);
    }

    public function edit(Request $request, ?User $user = null)
    {
        $this->authorizeAdmin($request);

        return view('auth.account-form', ['account' => $user, 'context' => $user ? app(AccountAccess::class)->context($user, false) : null,
            'roles' => AccountAccess::ROLES] + app(AccountPlacement::class)->options());
    }

    public function save(Request $request, AccountAccess $access, ?User $user = null)
    {
        $this->authorizeAdmin($request);
        $request->merge(['email' => Str::lower((string) $request->input('email')), 'username' => Str::lower((string) $request->input('username'))]);
        $placement = app(AccountPlacement::class);
        $input = $request->validate(['name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->getKey(), 'id_user')],
            'password' => AuthController::passwordRules(! $user, 6), 'role' => ['required', Rule::in(array_keys(AccountAccess::ROLES))],
            'branches' => 'required|array|min:1|max:100', 'branches.*' => ['required', 'integer', 'distinct', Rule::exists('branches', 'id_branch')->where('is_active', true)->whereNull('deleted_at')],
            'active' => 'required|boolean'] + $placement->rules($user));
        $placement->validate($input);
        if ($user?->is($request->user())) {
            abort_unless($input['active'] && $input['role'] === 'SUPERADMIN', 422, 'Akun Anda sendiri harus tetap Superadmin aktif.');
        }
        Cache::lock('area3-account-provisioning', 15)->block(5, fn () => DB::transaction(function () use ($input, $user, $access) {
            $account = $user ? User::whereKey($user->getKey())->lockForUpdate()->firstOrFail() : new User;
            $account->name = trim($input['name']);
            $account->email = Str::lower($input['email']);
            $account->fill(app(AccountPlacement::class)->fields($input));
            $account->active = (bool) $input['active'];
            if (! empty($input['password'])) {
                $account->password = $input['password'];
                $account->remember_token = Str::random(60);
            }
            $account->save();
            $access->assign($account, $input['role'], $input['branches']);
        }));

        return redirect()->route('accounts.index')->with('status', 'Akun dan penugasan Branch telah disimpan.');
    }
}
