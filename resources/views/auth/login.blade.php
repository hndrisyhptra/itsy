@extends('auth.layout')
@section('content')
<main class="login-layout">
    <section class="login-story" aria-label="Area 3 Construction Workspace">
        @include('auth.brand')
        <div class="story-content"><span class="eyebrow"><i></i> TERHUBUNG DALAM SATU ALUR</span>
            <h1>Satu ruang.<br>Setiap langkah<br><span>proyek Anda.</span></h1>
            <p>Rencanakan pekerjaan, kelola persetujuan, dan pantau pembayaran dengan lebih terarah.</p>
            <div class="story-workflow" aria-label="Alur Project Plan, SPK, dan Pembayaran">
                <div class="workflow-card"><span class="workflow-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M7 3h10v4H7zM5 5H3v16h18V5h-2M7 12h10M7 16h6"/></svg></span><div><small>01 / RENCANAKAN</small><strong>Project Plan</strong><p>BOQ & persetujuan proyek</p></div><span class="workflow-arrow">↗</span></div>
                <div class="workflow-connector"></div>
                <div class="workflow-card"><span class="workflow-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M14 3H5v18h14V8zM14 3v5h5M8 12h8M8 16h5"/></svg></span><div><small>02 / LAKSANAKAN</small><strong>SPK</strong><p>Kesepakatan & kontrak kerja</p></div><span class="workflow-arrow">↗</span></div>
                <div class="workflow-connector"></div>
                <div class="workflow-card"><span class="workflow-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4M15 15h2"/></svg></span><div><small>03 / SELESAIKAN</small><strong>Pembayaran</strong><p>Approval, verifikasi & APM</p></div><span class="workflow-arrow">↗</span></div>
            </div>
        </div>
        <div class="story-footer"><span>it's Area 3</span><span>Administrasi Proyek Konstruksi</span></div>
    </section>
    <section class="login-form-panel">
        <div class="mobile-brand">@include('auth.brand')</div>
        <div class="login-form-wrap">
            <span class="form-kicker">AREA 3 WORKSPACE</span>
            <h2>{{ $setup ? 'Siapkan akses pertama' : 'Selamat datang kembali' }}</h2>
            <p class="form-intro">{{ $setup ? 'Buat akun Superadmin untuk mulai mengelola pengguna dan menjalankan alur proyek.' : 'Masuk dengan akun Anda untuk melanjutkan pekerjaan.' }}</p>
            @include('auth.errors')
            <form method="post" action="{{ $setup ? route('setup') : route('login') }}" class="access-form" data-access-form>
                @csrf
                @if($setup)<div class="access-field"><label for="name">Nama lengkap</label><input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="255" placeholder="Nama administrator" autofocus></div>@endif
                <div class="access-field"><label for="username">Username</label><input id="username" name="username" value="{{ old('username') }}" autocomplete="username" required minlength="3" maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9._-]{2,49}" placeholder="Username Anda" @if(!$setup) autofocus @endif></div>
                @if($setup)<div class="access-field"><label for="email">Email</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required maxlength="255" placeholder="nama@perusahaan.co.id"></div><details class="setup-placement"><summary>NIK & penempatan (opsional)</summary>@include('auth.placement')</details>@endif
                <div class="access-field"><label for="password">Kata sandi</label>@include('auth.password', ['autocomplete' => $setup ? 'new-password' : 'current-password'])</div>
                @if($setup)
                    <div class="access-field"><label for="password_confirmation">Ulangi kata sandi</label>@include('auth.password', ['field' => 'password_confirmation', 'autocomplete' => 'new-password'])</div>
                    <p id="password-help" class="field-help">Minimal 12 karakter, mengandung huruf dan angka. Maksimal 72 byte.</p>
                @else
                    <div class="login-options"><label class="checkbox-label"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Ingat saya</label><span id="password-help" class="field-help">Gunakan akun yang ditugaskan.</span></div>
                @endif
                <button class="access-button primary" type="submit" data-submit-label="{{ $setup ? 'Membuat akun…' : 'Memeriksa akun…' }}"><span>{{ $setup ? 'Buat akun & mulai' : 'Masuk ke Workspace' }}</span><span aria-hidden="true">→</span></button>
            </form>
            <div class="login-help"><span class="help-icon">?</span><div><strong>Butuh bantuan akses?</strong><p>Hubungi administrator untuk akun, penugasan Branch, atau pengaturan ulang kata sandi.</p></div></div>
            @if($setup)<p class="setup-note">Penyiapan ini hanya tersedia dari komputer lokal saat belum ada pengguna. Anda dapat menambahkan akun lain setelah masuk.</p>@endif
        </div>
        <footer class="login-footer"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6zM8 12l3 3 5-6"/></svg>Akses sesuai akun dan Branch penugasan</footer>
    </section>
</main>
@endsection
