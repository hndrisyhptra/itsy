@extends('auth.layout')
@section('title', "Kelola Akun · it's Area 3")
@section('body-class', 'account-page')
@section('content')
<header class="accounts-header">@include('auth.brand')<a href="{{ route('prototype.dashboard') }}" class="access-button">← Kembali ke Workspace</a></header>
<main class="accounts-content"><div class="accounts-title"><div><span class="form-kicker">PENGATURAN AKSES</span><h1>Kelola akun pengguna</h1><p>Setiap akun memiliki satu peran dan satu atau beberapa Branch.</p></div><a class="access-button primary" href="{{ route('accounts.create') }}">+ Tambah akun</a></div>
@include('auth.errors')
<div class="access-table-wrap"><table class="access-table"><thead><tr><th>Pengguna / Username / NIK</th><th>Peran</th><th>Branch</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@foreach($users as $user)@php($context = $access->context($user, false))<tr><td><strong>{{ $user->name }}</strong><small>{{ $user->username }} · {{ $user->email }}</small><small>NIK: {{ $user->nik ?: 'Belum diisi' }}</small></td><td>{{ $context['role'] ?? 'Belum memiliki akses aktif' }}</td><td class="branch-cell">{{ implode(', ', $context['branches'] ?? []) ?: '—' }}</td><td><span class="access-badge">{{ $user->active && $context ? 'Aktif' : 'Nonaktif' }}</span></td><td><a href="{{ route('accounts.edit', $user) }}">Ubah akun</a></td></tr>@endforeach</tbody></table></div>
<p class="field-help">Untuk menguji approval, keluar dari akun ini lalu masuk dengan akun PM, Manager, atau Finance yang telah ditugaskan. Pengajuan dibagikan dalam workspace yang sama.</p>
</main>
@endsection
