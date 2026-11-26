@extends('auth.layout')
@section('title', "Kelola Akun · it's Area 3")
@section('body-class', 'account-page')
@section('content')
<header class="accounts-header">@include('auth.brand')<a href="{{ route('prototype.dashboard') }}" class="access-button">← Kembali ke Workspace</a></header>
<main class="accounts-content"><div class="accounts-title"><div><span class="form-kicker">PENGATURAN AKSES</span><h1>Kelola akun pengguna</h1><p>Setiap akun memiliki satu peran dan satu atau beberapa Branch.</p></div><a class="access-button primary" href="{{ route('accounts.create') }}">+ Tambah akun</a></div>
@include('auth.errors')
<form method="get" action="{{ route('accounts.index') }}" class="accounts-filters" role="search" aria-label="Pencarian akun pengguna">
    <div class="access-field accounts-search"><label for="account-search">Cari pengguna</label><input id="account-search" name="q" type="search" maxlength="150" value="{{ $search }}" placeholder="Nama, username, NIK, email, jabatan atau peran…"></div>
    <div class="access-field"><label for="account-per-page">Baris per halaman</label><select id="account-per-page" name="per_page">@foreach([10,20,50,100] as $size)<option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>@endforeach</select></div>
    <button type="submit" class="access-button primary">Cari / Terapkan</button>
    @if($search !== '')<a class="access-button" href="{{ route('accounts.index', ['per_page' => $perPage]) }}">Reset pencarian</a>@endif
</form>
<div class="access-table-wrap"><table class="access-table"><thead><tr><th>Pengguna / Username / NIK</th><th>Jabatan</th><th>Peran</th><th>Branch</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($users as $user)@php($context = $contexts[$user->getKey()] ?? null)<tr><td><strong>{{ $user->name }}</strong><small>{{ $user->username }} · {{ $user->email }}</small><small>NIK: {{ $user->nik ?: 'Belum diisi' }}</small></td><td>{{ $user->jabatan ?: '—' }}</td><td>{{ $context['role'] ?? 'Belum memiliki akses aktif' }}</td><td class="branch-cell">{{ implode(', ', $context['branches'] ?? []) ?: '—' }}</td><td><span class="access-badge">{{ $user->active && $context ? 'Aktif' : 'Nonaktif' }}</span></td><td><a href="{{ route('accounts.edit', $user) }}">Ubah akun</a></td></tr>@empty<tr><td colspan="6" class="accounts-empty">Tidak ada akun yang sesuai pencarian.</td></tr>@endforelse</tbody></table></div>
<nav class="accounts-pagination" aria-label="Halaman akun pengguna">
    <span>Total <strong>{{ number_format($users->total(), 0, ',', '.') }}</strong> pengguna · {{ $users->firstItem() ?? 0 }}–{{ $users->lastItem() ?? 0 }} ditampilkan</span>
    <div class="accounts-page-actions">
        @if($users->onFirstPage())<span class="access-button" aria-disabled="true">Sebelumnya</span>@else<a class="access-button" rel="prev" href="{{ $users->previousPageUrl() }}">Sebelumnya</a>@endif
        <span aria-live="polite">Halaman {{ $users->currentPage() }} / {{ $users->lastPage() }}</span>
        @if($users->hasMorePages())<a class="access-button" rel="next" href="{{ $users->nextPageUrl() }}">Berikutnya</a>@else<span class="access-button" aria-disabled="true">Berikutnya</span>@endif
    </div>
</nav>
<p class="field-help">Untuk menguji approval, keluar dari akun ini lalu masuk dengan akun PM, Manager, atau Finance yang telah ditugaskan. Pengajuan dibagikan dalam workspace yang sama.</p>
</main>
@endsection
