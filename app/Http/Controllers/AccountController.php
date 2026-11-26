<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountAccess;
use App\Services\AccountPlacement;
use App\Services\WorkUnitAccess;
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

        $input = $request->validate(['q' => 'sometimes|nullable|string|max:150', 'per_page' => 'sometimes|integer|in:10,20,50,100', 'page' => 'sometimes|integer|min:1|max:1000000']);
        $search = trim($input['q'] ?? '');
        $perPage = (int) ($input['per_page'] ?? 20);
        $query = User::query();
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $roles = array_keys(array_filter(AccountAccess::ROLES, fn ($label) => str_contains(Str::lower($label), Str::lower($search))));
            $query->where(function ($q) use ($pattern, $roles) {
                foreach (['name', 'username', 'nik', 'email', 'jabatan', 'role'] as $column) {
                    $q->orWhereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
                }
                if ($roles) {
                    $q->orWhereIn('role', $roles);
                }
            });
        }
        $users = $query->orderBy('name')->orderBy('id_user')->paginate($perPage, ['id_user', 'name', 'username', 'nik', 'email', 'jabatan', 'role', 'active', 'work_unit_id', 'access_scope', 'id_regions'])->appends(['q' => $search, 'per_page' => $perPage]);

        return view('auth.accounts', ['users' => $users, 'search' => $search, 'perPage' => $perPage, 'contexts' => app(AccountAccess::class)->contexts($users->getCollection())]);
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
            'jabatan' => ['sometimes', 'nullable', 'string', 'max:250', 'regex:/\A[^\p{C}<>]*\z/u'],
            'password' => AuthController::passwordRules(! $user, 6), 'role' => ['required', Rule::in(array_keys(AccountAccess::ROLES))],
            'branches' => 'sometimes|array|min:1|max:100', 'branches.*' => ['required', 'integer', 'distinct', Rule::exists('branches', 'id_branch')->where('is_active', true)->whereNull('deleted_at')],
            'active' => 'required|boolean', 'access_scope' => ['sometimes', Rule::in(['BRANCH', 'REGION'])], 'work_unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('work_units', 'id_work_unit')]] + $placement->rules($user));
        $input['access_scope'] ??= $user->access_scope ?? 'BRANCH';
        $input['work_unit_id'] = array_key_exists('work_unit_id', $input) ? $input['work_unit_id'] : ($user->work_unit_id ?? null);
        $regionRoles = ['OFFICER_SS1', 'MGR_SS', 'GM_OPS'];
        abort_if($input['access_scope'] === 'REGION' && (! in_array($input['role'], $regionRoles, true) || empty($input['id_regions'])), 422, 'Cakupan Region hanya untuk Officer 1 SS, Manager SS atau GM dengan Region yang dipilih.');
        abort_if(in_array($input['role'], ['MGR_SS', 'GM_OPS'], true) && $input['access_scope'] !== 'REGION', 422, 'Manager SS dan General Manager Operation wajib memakai cakupan Region.');
        if ($input['access_scope'] === 'REGION') {
            $input['branches'] = DB::table('branches')->where('region_id', $input['id_regions'])->where('is_active', true)->whereNull('deleted_at')->pluck('id_branch')->all();
        }
        abort_if(empty($input['branches']), 422, 'Pilih minimal satu Branch aktif.');
        $unitCode = $input['work_unit_id'] ? DB::table('work_units')->where('id_work_unit', $input['work_unit_id'])->value('code') : null;
        abort_if(! WorkUnitAccess::units($input['role'], $unitCode), 422, 'Unit kerja akun harus sesuai peran yang dipilih.');
        $placement->validate($input);
        if ($user?->is($request->user())) {
            abort_unless($input['active'] && $input['role'] === 'SUPERADMIN', 422, 'Akun Anda sendiri harus tetap Superadmin aktif.');
        }
        Cache::lock('area3-account-provisioning', 15)->block(5, fn () => DB::transaction(function () use ($input, $user, $access) {
            $account = $user ? User::whereKey($user->getKey())->lockForUpdate()->firstOrFail() : new User;
            $account->name = trim($input['name']);
            $account->email = Str::lower($input['email']);
            if (array_key_exists('jabatan', $input)) {
                $account->jabatan = trim($input['jabatan'] ?? '') ?: null;
            }
            $account->fill(app(AccountPlacement::class)->fields($input));
            $account->work_unit_id = $input['work_unit_id'];
            $account->access_scope = $input['access_scope'];
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
