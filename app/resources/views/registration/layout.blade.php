<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Platzhirsch: Reservierungen, Tische und Räume für Restaurants und Hotels verwalten. Betrieb mit Website und geschäftlicher E-Mail registrieren.">
    <title>@yield('title', 'Platzhirsch – Registrierung für Restaurants & Hotels')</title>
    <link rel="stylesheet" href="/landing/tokens.css">
    <link rel="stylesheet" href="/landing/style.css">
    <script src="/landing/registration.js" defer></script>
</head>
<body>
<a class="skip" href="#inhalt">Zum Inhalt</a>
<header class="site-header">
    <a class="brand" href="/" aria-label="Platzhirsch Startseite"><span class="brand-mark">P</span><span>Platzhirsch<small>RESERVIERUNGEN. ORGANISIERT.</small></span></a>
    <a class="login" href="/restaurant/login">Bereits dabei? <strong>Anmelden ↗</strong></a>
</header>
<main id="inhalt">@yield('content')</main>
<footer class="site-footer">
    <span>Platzhirsch · Für Gastgeber</span>
    <nav aria-label="Weitere Informationen">
        @if(config('registration.imprint_url'))<a href="{{ config('registration.imprint_url') }}">Impressum</a>@endif
        @if(config('registration.privacy_url'))<a href="{{ config('registration.privacy_url') }}">Datenschutz</a>@endif
        <a href="/administration/login">Administration</a>
    </nav>
</footer>
</body>
</html>
