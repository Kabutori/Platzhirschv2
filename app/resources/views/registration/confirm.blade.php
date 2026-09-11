@extends('registration.layout')
@section('title', 'E-Mail bestätigen – Platzhirsch')
@section('content')
<section class="registration-card confirmation" aria-labelledby="confirmation-title">
    @if($registration)
        <p class="eyebrow">SCHRITT 02 · E-MAIL BESTÄTIGEN</p><h1 id="confirmation-title">Fast am Tisch.</h1>
        <p>Bestätigen Sie <strong>{{ $registration->email }}</strong> für <strong>{{ $registration->business_name }}</strong> und legen Sie Ihr Passwort fest.</p>
        <p class="fineprint">Website: {{ $registration->website }}</p>
        @if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <form method="post" action="/registrierung/bestaetigen/{{ $token }}" data-registration-form>
            @csrf
            <label for="password">Passwort</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required aria-describedby="password-help">
            <small id="password-help">Mindestens 12 Zeichen.</small>
            <label for="password_confirmation">Passwort wiederholen</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required>
            <button class="primary" type="submit"><span data-submit-label>E-Mail bestätigen & Zugang anlegen</span><span aria-hidden="true">→</span></button>
            <p class="submit-status" role="status" data-submit-status></p>
        </form>
        <p class="fineprint">Nicht Ihr Betrieb? Bestätigen Sie diese Registrierung nicht.</p>
    @else
        <p class="eyebrow">LINK NICHT MEHR GÜLTIG</p><h1 id="confirmation-title">Zurück zum Start.</h1>
        <p>Dieser Bestätigungslink ist abgelaufen oder wurde bereits verwendet. Ein bestehender Zugang bleibt erhalten.</p>
        <a class="button primary" href="/registrierung">Erneut registrieren →</a><a class="button" href="/restaurant/login">Zum Restaurantlogin</a>
    @endif
</section>
@endsection
