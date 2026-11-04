<div class="password-field">
    <input id="{{ $field ?? 'password' }}" name="{{ $field ?? 'password' }}" type="password" autocomplete="{{ $autocomplete ?? 'current-password' }}" {{ ($required ?? true) ? 'required' : '' }} @if(($autocomplete ?? '') === 'new-password') minlength="{{ $minimum ?? 12 }}" @endif aria-describedby="password-help">
    <button type="button" class="password-toggle" data-toggle-password="{{ $field ?? 'password' }}" aria-label="Tampilkan kata sandi" aria-pressed="false"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
</div>
