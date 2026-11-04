<?php

return [
    'regex' => ':attribute hanya boleh berisi huruf, angka, titik, garis bawah dan tanda hubung.',
    'required' => ':attribute wajib diisi.',
    'email' => ':attribute harus berupa alamat email yang valid.',
    'unique' => ':attribute sudah digunakan.',
    'confirmed' => 'Konfirmasi :attribute tidak sesuai.',
    'string' => ':attribute harus berupa teks.',
    'boolean' => ':attribute tidak valid.',
    'integer' => ':attribute harus berupa bilangan bulat.',
    'array' => ':attribute harus berupa daftar.',
    'in' => 'Pilihan :attribute tidak valid.',
    'exists' => 'Pilihan :attribute tidak tersedia atau tidak aktif.',
    'distinct' => ':attribute tidak boleh berulang.',
    'min' => ['string' => ':attribute minimal :min karakter.', 'array' => 'Pilih minimal :min :attribute.', 'numeric' => ':attribute minimal :min.'],
    'max' => ['string' => ':attribute maksimal :max karakter.', 'array' => ':attribute maksimal :max pilihan.', 'numeric' => ':attribute maksimal :max.', 'file' => ':attribute maksimal :max KB.'],
    'password' => ['letters' => ':attribute harus mengandung huruf.', 'numbers' => ':attribute harus mengandung angka.'],
    'attributes' => ['username' => 'Username', 'nik' => 'NIK pegawai', 'id_regions' => 'Region', 'id_branch' => 'Branch utama', 'id_service_areas' => 'Service Area', 'name' => 'Nama lengkap', 'email' => 'Email', 'password' => 'Kata sandi', 'role' => 'Peran', 'active' => 'Status akun', 'branches' => 'Branch', 'branches.*' => 'Branch'],
];
