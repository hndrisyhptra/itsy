@php($person = $account ?? null)
<div class="account-grid">
    <div class="access-field"><label for="nik">NIK pegawai (opsional)</label><input id="nik" name="nik" value="{{ old('nik', $person?->nik) }}" maxlength="50" autocomplete="off"><small class="field-help">Isi sesuai identitas pegawai; nol di depan tetap disimpan.</small></div>
    <div class="access-field"><label for="id_regions">Region</label><select id="id_regions" name="id_regions" data-region-select><option value="">Belum ditetapkan</option>@foreach($regions as $region)<option value="{{ $region->id_region }}" @selected((string) old('id_regions', $person?->id_regions) === (string) $region->id_region)>{{ $region->name }}</option>@endforeach</select></div>
</div>
<div class="account-grid">
    <div class="access-field"><label for="id_branch">Branch utama</label><select id="id_branch" name="id_branch" data-branch-select><option value="">Belum ditetapkan</option>@foreach($branches as $branch)<option value="{{ $branch->id_branch }}" data-region="{{ $branch->region_id }}" @selected((string) old('id_branch', $person?->id_branch) === (string) $branch->id_branch)>{{ $branch->name }} · {{ $branch->kode_branch }}</option>@endforeach</select></div>
    <div class="access-field"><label for="id_service_areas">Service Area</label><select id="id_service_areas" name="id_service_areas" data-service-select><option value="">Belum ditetapkan</option>@foreach($serviceAreas as $service)<option value="{{ $service->id_service_area }}" data-branch="{{ $service->branch_id }}" @selected((string) old('id_service_areas', $person?->id_service_areas) === (string) $service->id_service_area)>{{ $service->workzone }} · {{ $service->name }}</option>@endforeach</select></div>
</div>
