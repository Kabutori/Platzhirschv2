# Backend-Basis — Platform-Application (Laravel) und Erst-Bootstrap

Stand: 26.08.2026 · Grundlage: `docs/KONZEPT.md` (2a, 3.1, 5.1, 8, 12) und die
gepruefte Bootstrap-Referenz im Agent-Manager-Worktree `admin-db-bootstrap`.
Vertrag der Admin-UI: `docs/ADMIN-UI.md` Abschnitte 3–4.

## 1. Struktur (`app/` = Composition Root, KONZEPT 3.1)

```
app/
├── composer.json                   platzhirsch/platform-application, laravel/framework ^12
├── artisan · public/index.php      Standard-Einstiegspunkte
├── bootstrap/app.php               Routing (api, commands, health /up), Command-Discovery
├── bootstrap/providers.php         AppServiceProvider (Rate-Limiter "bootstrap")
├── config/                         app, cache, database, hashing, logging (minimal)
├── routes/api.php                  GET /api/bootstrap-status, POST /api/bootstrap/first-admin
├── routes/console.php              (Planungsplatz; Kommandos via withCommands)
├── app/
│   ├── Http/Controllers/Api/BootstrapController.php
│   ├── Http/Requests/BootstrapFirstAdminRequest.php
│   ├── Platform/Database/FirstAdminBootstrap.php        (guardierter Bootstrap)
│   ├── Platform/Database/AlreadyBootstrappedException.php
│   └── Console/Commands/FirstAdminCommand.php
├── database/
│   ├── migrations/0001_01_01_000000_create_cache_table.php        (W3: Cache via MySQL)
│   ├── migrations/2026_08_26_000001_create_initial_platform_admin_schema.php
│   └── platform/2026_08_26_000001_initial_platform_admin_schema.sql  (Quelle der Migration)
├── tests/
│   ├── Feature/BootstrapApiTest.php         (PHPUnit, MySQL-Test-DB, 11 Tests)
│   └── Platform/Database/SchemaCompletenessTest.php (standalone, ohne DB)
└── tools/lint.php                           (php -l ueber alle Dateien)
```

## 2. Erst-Administrator-Bootstrap (keine Fake-Authentifizierung)

Endpunkte (`routes/api.php`), Vertrag identisch zu `docs/ADMIN-UI.md`:

| Methode | Pfad | Verhalten |
| --- | --- | --- |
| GET | `/api/bootstrap-status` | `200 {"bootstrapped": false\|true}` — Sentinel **oder** existierender SYSTEM_ADMIN |
| POST | `/api/bootstrap/first-admin` | `201 {"user": AdminUser}` · `409` (bereits gebootstrappt, mit Audit-Eintrag „bootstrap.first_admin_blocked") · `422` (Validierung) · `429` (Rate-Limit 5/min/IP) |

Es wird **kein** Session-Cookie ausgestellt; nach dem Setup meldet sich der
Administrator ueber den (vom Identity-Modul nachzuliefernden) Login an.

Schutzmechanik des Services (`FirstAdminBootstrap`, uebernommen aus der
Worktree-Referenz): Transaktion, Vorabpruefung, Sentinel per
`INSERT … SELECT … WHERE NOT EXISTS` (linearisierend), E-Mail-UNIQUE
(SQLSTATE 23000 ⇒ `AlreadyBootstrappedException`), Idempotenz (mehrfacher
Aufruf ist schadlos). Abweichungen von der Referenz (begruendet):
`Hash::make()` (bcrypt, Laravel-kompatibel fuer den spaeteren Login),
`system_audit.result` = `ok`/`fehler` (UI-Vertrag), `users.name` (UI-Vertrag),
Audit der blockierten Versuche.

CLI (`artisan`), Exit-Codes nach AGENTS.md-Konvention:

```
php artisan platform:bootstrap:first-admin [--email=] [--name=] [--password=]
# 0 = ok · 1 = Fehler/Validierung · 2 = bereits gebootstrappt (idempotent)
# ohne --password: interaktive hidden-Abfrage
```

## 3. Migrationen und Modulgrenzen

- `database/platform/*.sql` ist die **eine** versionierte Plattform-Migration
  (Kopfkommentar dokumentiert die Modulzugehoerigkeit jeder Tabelle).
  Sie wird von der Laravel-Migration per `DB::unprepared` angewendet; `down()`
  droppt in umgekehrter FK-Reihenfolge. Die Tabellen wandern in die jeweiligen
  Modul-Migrationen, sobald die Modul-Repositories existieren (AGENTS.md).
- Keine Test-/Seed-Daten in der Migration; `CREATE TABLE IF NOT EXISTS`
  durchgaengig (Idempotenz). `users.name` ergaenzt (UI-Vertrag).
- `cache`/`cache_locks` (Standard-Migration) ermoeglichen W3: Rate-Limit,
  Cache, Queue ueber MySQL statt Redis.
- Datenbank-/Benutzer-Provisionierung bleibt Aufgabe von `mysql-setup`
  (nicht Teil dieses Repos). E1: nur MySQL.

## 4. Lokale Validierung (durchgefuehrt)

```
composer install                       -> Laravel 12.68.0, 0 Security-Advisories
php artisan migrate --force            -> platform: 2 Migrationen, ok
php artisan route:list                 -> bootstrap-Routen + /up registriert
php artisan platform:bootstrap:first-admin …   -> Exit 0, danach Exit 2 (idempotent)
HTTP-Smoke (php -S, platform_test):    -> false → 201 (AdminUser-Form) → true → 409 → 422
php vendor/phpunit/phpunit/phpunit     -> OK, 11 Tests, 47 Assertions
php tests/Platform/Database/SchemaCompletenessTest.php -> alle Pruefungen ok
php tools/lint.php                     -> 23 Dateien, 0 Fehler
```

Hinweis: Laravel **11** war in dieser Umgebung durch Composer-Security-
Advisories vollstaendig blockiert; daher `laravel/framework ^12` (PHP 8.2+,
identisches Skeleton, aktuelle, gepatchte Linie).

## 5. Noch offene Backend-Arbeit (unveraendert zu docs/ADMIN-UI.md §6)

Login/MFA/Session (Identity-Modul, Sanctum), alle weiteren Admin-Endpunkte
(Mandanten, Module, Benutzer, Rollen, Audit, Settings) mit serverseitigen
Policies, Mandanten-Einladung/Umzug ueber Worker, kuratierter Setup-Katalog,
SSO. Betrieb: nginx/IIS-VHost fuer `public/` + `/api`-Proxy in das
nginx-setup-Repository (custom/platzhirschv2) — dort, nicht hier.
