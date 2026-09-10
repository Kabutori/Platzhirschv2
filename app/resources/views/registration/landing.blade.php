@extends('registration.layout')
@section('content')
<div class="landing-grid">
    <section class="intro">
        <p class="eyebrow"><span></span> FÜR RESTAURANTS & HOTELS</p>
        <h1>Mehr Zeit für<br>Ihre <em>Gäste.</em></h1>
        <p class="lead">Reservierungen, Tische und Räume an einem Ort. Richten Sie Ihren Betrieb in Platzhirsch ein – mit Ihrer Website und Ihrer geschäftlichen E-Mail-Adresse.</p>
        <div class="benefits"><span>Reservierungen</span><span>Tischplanung</span><span>Buchungswidget</span></div>
        <ol class="steps">
            <li><span>01</span><div><h2>Betrieb prüfen</h2><p>Website und E-Mail-Domain abgleichen. Restaurant- oder Hotelangebot auf Ihrer Website erkennen.</p></div></li>
            <li><span>02</span><div><h2>E-Mail bestätigen</h2><p>Den Link in Ihrem Postfach öffnen und ein persönliches Passwort festlegen.</p></div></li>
            <li><span>03</span><div><h2>Restaurant einrichten</h2><p>Nach der Bestätigung wird Ihr Zugang eingerichtet. Anschließend Räume, Tische und Öffnungszeiten anlegen.</p></div></li>
        </ol>
    </section>
    <section class="registration-card" aria-labelledby="registration-title">
        @if(session('registration_completed'))
            <p class="eyebrow">E-MAIL BESTÄTIGT</p><h2 id="registration-title">Willkommen bei Platzhirsch.</h2>
            <p class="notice" role="status">Ihr Zugang wurde angelegt. Ihr Restaurant wird jetzt im Hintergrund eingerichtet.</p>
            <p>Sie können sich mit Ihrer E-Mail-Adresse und Ihrem neuen Passwort anmelden. Sollte die Einrichtung noch laufen, versuchen Sie es bitte nach einem kurzen Moment erneut.</p>
            <a class="button primary" href="/restaurant/login">Zum Restaurantlogin <span>→</span></a>
        @elseif(session('registration_sent'))
            <p class="eyebrow">NÄCHSTER SCHRITT</p><h2 id="registration-title">Ein Blick ins Postfach.</h2>
            <p class="notice" role="status">Wenn für diese Adresse eine neue Registrierung möglich ist, wurde eine Bestätigungsmail versendet. Der Link gilt 24 Stunden.</p>
            <p>Bitte auch den Spamordner prüfen. Bei einem bestehenden Zugang verwenden Sie die Passwort-zurücksetzen-Funktion im Restaurantlogin.</p>
            <a class="button" href="/restaurant/login">Zum Restaurantlogin →</a>
        @else
            <p class="eyebrow">WEBSITE & KONTAKT</p><h2 id="registration-title">Ihr Betrieb. Ihr Platzhirsch.</h2>
            <p class="card-intro">Alle Felder sind erforderlich.</p>
            @if(!$available)<p class="notice" role="status">Die Online-Registrierung wird vorbereitet. Bestehende Zugänge können sich bereits anmelden.</p>@endif
            @if($errors->any())<div class="error" role="alert" tabindex="-1"><strong>Bitte prüfen Sie Ihre Angaben.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <form method="post" action="/registrierung" data-registration-form>
                @csrf
                <fieldset @disabled(!$available)>
                    <legend class="sr-only">Betrieb registrieren</legend>
                    <label for="business_name">Name des Restaurants oder Hotels</label><input id="business_name" name="business_name" autocomplete="organization" maxlength="120" value="{{ old('business_name') }}" placeholder="Zum Beispiel: Restaurant Zur Linde" required>
                    <label for="owner_name">Ihr Vor- und Nachname</label><input id="owner_name" name="owner_name" autocomplete="name" maxlength="120" value="{{ old('owner_name') }}" required>
                    <label for="website">Website Ihres Betriebs</label><input id="website" name="website" inputmode="url" autocomplete="url" maxlength="500" value="{{ old('website') }}" placeholder="www.ihr-restaurant.de" aria-describedby="website-help" required>
                    <small id="website-help">Eine öffentlich erreichbare Seite mit Restaurant- oder Hotelangebot. Wir prüfen den Text, z. B. auf Speisekarte oder Zimmerangebote.</small>
                    <label for="email">Geschäftliche E-Mail-Adresse</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" value="{{ old('email') }}" placeholder="kontakt@ihr-restaurant.de" aria-describedby="email-help" required>
                    <small id="email-help">Die Domain hinter @ muss zur Website passen. Gmail, GMX oder andere private Mailanbieter passen nicht zu einer eigenen Betriebswebsite.</small>
                    <div class="honeypot" aria-hidden="true"><label for="fax_number">Faxnummer bitte leer lassen</label><input id="fax_number" name="fax_number" tabindex="-1" autocomplete="off"></div>
                    <label class="consent"><input type="checkbox" name="privacy" value="1" required @checked(old('privacy'))><span>Ich darf diesen Betrieb registrieren und habe die @if(config('registration.privacy_url'))<a href="{{ config('registration.privacy_url') }}">Datenschutzhinweise</a>@else Datenschutzhinweise @endif gelesen.</span></label>
                    <button class="primary" type="submit"><span data-submit-label>Website prüfen & registrieren</span><span aria-hidden="true">→</span></button>
                    <p class="submit-status" role="status" data-submit-status></p>
                </fieldset>
            </form>
            <p class="fineprint">Ihr Passwort legen Sie nach der E-Mail-Bestätigung fest. Mit diesem Schritt wird keine Zahlung ausgelöst.</p>
        @endif
    </section>
</div>
@endsection
