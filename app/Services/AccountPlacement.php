<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountPlacement
{
    public function rules(?User $user = null): array
    {
        return [
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9][a-z0-9._-]*$/D', Rule::unique('users', 'username')->ignore($user?->getKey(), 'id_user')],
            'nik' => ['nullable', 'string', 'max:50', Rule::unique('users', 'nik')->ignore($user?->getKey(), 'id_user')],
            'id_regions' => ['nullable', 'integer', Rule::exists('regions', 'id_region')->where('is_active', true)->whereNull('deleted_at')],
            'id_branch' => ['nullable', 'integer', Rule::exists('branches', 'id_branch')->where('is_active', true)->whereNull('deleted_at')],
            'id_service_areas' => ['nullable', 'integer', Rule::exists('service_areas', 'id_service_area')->where('is_active', true)->whereNull('deleted_at')],
        ];
    }

    public function validate(array $input): void
    {
        $region = $input['id_regions'] ?? null;
        $branch = $input['id_branch'] ?? null;
        $service = $input['id_service_areas'] ?? null;
        if ($branch && (! $region || ! DB::table('branches')->where('id_branch', $branch)->where('region_id', $region)->exists())) {
            throw ValidationException::withMessages(['id_branch' => 'Branch harus berada di Region yang dipilih.']);
        }
        if ($service && (! $branch || ! DB::table('service_areas')->where('id_service_area', $service)->where('branch_id', $branch)->exists())) {
            throw ValidationException::withMessages(['id_service_areas' => 'Service Area harus berada di Branch yang dipilih.']);
        }
        if ($branch && isset($input['branches']) && ! in_array((int) $branch, array_map('intval', $input['branches']), true)) {
            throw ValidationException::withMessages(['id_branch' => 'Branch utama harus termasuk Branch penugasan akun.']);
        }
    }

    public function fields(array $input): array
    {
        return array_intersect_key($input, array_flip(['username', 'nik', 'id_regions', 'id_branch', 'id_service_areas']));
    }

    public function options(): array
    {
        return [
            'regions' => DB::table('regions')->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get(),
            'branches' => DB::table('branches')->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get(),
            'serviceAreas' => DB::table('service_areas')->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get(),
        ];
    }
}
