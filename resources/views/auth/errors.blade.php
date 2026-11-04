@if(session('status'))<div class="access-notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())
<div class="access-error" role="alert" tabindex="-1" data-error-summary><strong>Periksa kembali data Anda.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
