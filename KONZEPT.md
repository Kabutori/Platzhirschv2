# Platzhirsch v2 — Umsetzungskonzept

Stand: 05.08.2026 · Grundlage: `design-template/` (Design-Prototyp + `platzhirsch_grundstruktur.md`) und `../middleware/` (Infrastruktur-Submodule)

> **Getroffene Grundsatzentscheidungen** (Details in Abschnitt 12):
> **Laravel** statt Symfony · **Windows Server** statt Linux · nur **MySQL** · Design fest auf **sharp/flat** · **öffentliche Website mit Selbstregistrierung** ab Phase 2 · Repos unter `Orryn` · **Satis** als Paketregistry · kein Horizon.
> Alle übrigen Architekturvorgaben der Grundstruktur bleiben gültig.

---

## 1. Ausgangslage

### 1.1 Was in diesem Repository liegt

```
platzhirschv2/
└── design-template/          (Submodul)
    ├── Admin Login.dc.html          Login Plattform-Administration
    ├── Customer Login.dc.html       Login Mandant/Restaurant
    ├── Platzhirsch Admin.dc.html    Vollständiger Admin-Prototyp (~5.700 Zeilen)
    ├── support.js / image-slot.js   Laufzeit des Prototyp-Werkzeugs (kein Produktcode)
    └── uploads/platzhirsch_grundstruktur.md   Architekturvorgabe (45 Kapitel)
```

Der Prototyp ist **kein React-Code**. Er nutzt eine eigene Template-Sprache (`<sc-if>`, `<sc-for>`, `{{ … }}`) mit einer einzelnen Klasse, die `state` hält und ein flaches Props-Objekt für das Template berechnet. Alle Styles sind Inline-Styles mit `oklch()`-Farben. Er ist damit **verbindliche Spezifikation für Layout, Informationsarchitektur, Texte und Interaktion** — aber nicht wiederverwendbar als Implementierung. Er wird übersetzt, nicht übernommen.

### 1.2 Was in `../middleware/` liegt

Vier Submodule mit Installationsskripten für Windows (PowerShell) und Linux (Bash):

| Submodul | Inhalt |
| --- | --- |
| `Git-Setup` | `Install-Git-{Windows.ps1,Linux.sh}`, mitgelieferte `Git-2.51.2-64-bit.exe` |
| `mysql-setup` | Install-Skripte, mitgelieferte `mysql-9.7.1-winx64.zip` |
| `nginx-setup` | Install-Skripte, `nginx-1.31.3.zip`, `NginxService.exe` + `.xml` (WinSW), `test.php` |
| `php-laravel-setup` | Install-Skripte, `php-8.4-…zip`, `composer.phar`, `PhpCgiService.exe` + `.xml`, `vc_redist.x64.exe` |

Das ist ein **Windows-first-Stack** — und damit, anders als bei der ursprünglichen Linux-Annahme der Grundstruktur, genau die richtige Grundlage: Windows Server ist die Zielplattform (E6). Was fehlt und was sich ändert, steht in Abschnitt 9.

### 1.3 Was das Design an Fachlichkeit vorgibt

Platzhirsch ist eine **Reservierungsplattform für Restaurants** — das war aus der Grundstruktur allein nicht ableitbar, dort ist alles generisch als "Kunde"/"Widget" beschrieben. Der Prototyp zeigt zwei getrennte Oberflächen hinter einem gemeinsamen Login:

**Bereichsauswahl** (`isChooser`) → Nutzer mit beiden Rollen wählen zwischen *Restaurant* und *Plattform*.

| Mandanten-Bereich (`NAV_CUSTOMER`) | Plattform-Bereich (`NAV_SYSTEM`) |
| --- | --- |
| Auswertung (Dashboard, KPIs, Auslastung/Std.) | Mandanten (Liste, Detail, Einladung) |
| Reservierungen | Module (Katalog, Versionen, Setup-Skripte) |
| Tischplan (Echtzeit-Timeline 11:30–23:00) | Benutzer (plattformweite Admins) |
| Tische (Sitzplätze, Status, Raumzuordnung) | Rollen & Rechte (Familie → Berechtigung, Codes) |
| Räume (Standort → Raum → Tisch, Farbe/Icon, wetterabhängig) | Abrechnung (Abos, Rechnungen) |
| Öffnungszeiten & Sonderzeiten | Audit Log |
| Widget (Konfiguration + Embed-Code) | System (Cluster-Health: Nginx/PHP-FPM/MySQL/Redis/Worker) |
| Module (kaufen/aktivieren) | System-Einstellungen (DB-Verbindungen, Cluster, Umzug, Setup, Odoo) |
| Team & Rollen | Releases (Release Notes je Modul) |
| Restaurant-Profil | Support (Ticket-Bearbeitung) |
| Einstellungen | Einstellungen |
| Support (Ticket eröffnen) | |

---

## 2. Abweichungen zwischen Design und Grundstruktur

Diese Punkte sind **vor Implementierungsbeginn zu entscheiden**, weil sie das Datenmodell und die Modulliste ändern:

| # | Befund | Empfehlung |
| --- | --- | --- |
| D1 | Design-Seed `DB_CONNECTIONS_SEED` nutzt **PostgreSQL**, die Settings-Maske lässt PostgreSQL/MySQL umschalten. Grundstruktur schreibt **MySQL** fest. | MySQL als einzige unterstützte Engine. Umschalter aus der Maske entfernen — sonst verspricht die UI etwas, das nicht getestet wird. |
| D2 | Design kennt **mehrere DB-Server/Cluster** (`DB_SERVERS`, EU/NA-Cluster, Mandanten-Umzug zwischen Servern, Massen-Migration). Grundstruktur kennt nur "eine MySQL-Instanz". | Übernehmen. Erfordert `platform.db_servers` + `tenant_databases.server_id` und einen Connection-Resolver, der pro Tenant den Server auflöst. Ist später sehr teuer nachzurüsten. |
| D3 | Design hat ein **Support-Ticketsystem** (beidseitig: Mandant eröffnet, Plattform bearbeitet, interne Notizen, SLA/Fälligkeit, Zuweisung). Nicht in der Modulliste. | Neues Modul `module-support` (in der Grundstruktur nur als "optional später" erwähnt) — von Anfang an einplanen, es ist im Design voll ausgebaut. |
| D4 | Design hat **Releases/Release Notes je Modul** mit Kategorien (Bugfix/Neuerung/Sicherheit/Preview) und Git-PR-Links. | Neues Modul `module-release`. Passt strukturell zur Multi-Repo-Strategie: jedes Modul-Repo publiziert seine Notes, der Core aggregiert. |
| D5 | Design hat eine **Odoo-Schnittstelle** (JSON-RPC/XML-RPC, Sync von Reservierungen/Kunden/Rechnungen). | Neues Modul `module-integration-odoo`, klar getrennt vom Core. |
| D6 | Design hat ein Modul **"Wettervorhersage"** (7-Tage-Vorschau für wetterabhängige Räume, `weatherDependent`-Flag am Raum). | Neues Modul `module-weather`. Räume verweisen darauf nur über ein optionales Contract-Interface. |
| D7 | Design hat **Setup-Tab**, der SQL-Skripte gegen eine gewählte Verbindung ausführt (`SQL_SCRIPTS`: Basis-Setup, Demo-Daten, Migration Berechtigungsmodell v2) mit Live-Log. | Ist faktisch eine UI für das Provisioning-/Migrations-System. Nicht als "freies SQL ausführen" bauen, sondern als **kuratierter Katalog benannter, versionierter Operationen** mit Audit-Pflicht. Sicherheitsrelevant. |
| D8 | Design hat **modulbasiertes Pricing** (`BASE_PACKAGE_PRICE` 29 €, `ADDON_PRICES`: Notification 9 €, Reporting 19 €) und Mandanten kaufen Module selbst ("Nicht gekauft" / "Gekauft & aktiviert"). | `module-billing` + `platform.tenant_modules` mit Kauf-/Aktivierungsstatus. Modulaktivierung muss Migrationen in der Tenant-DB auslösen. |
| D9 | Design erlaubt **Bearbeiten von Rollen, Berechtigungsfamilien und Codes zur Laufzeit** (`slugCode()`, `DEFAULT_FAMILY_CODES`, `DEFAULT_PERM_CODES`, gesperrte Systemrollen via `DEFAULT_ROLE_LOCKED`). | Zweistufig: Module **registrieren** Berechtigungen im Code (Permission Registry, unveränderlich). Rollen sind Datensätze und frei kombinierbar. Freies Anlegen neuer *Berechtigungen* in der UI nur als "Custom Permission" ohne Code-Wirkung — sonst entsteht eine Berechtigung, die kein Modul jemals prüft. |
| D10 | Design bietet **Exporte** in xlsx/csv/sql/json/xml/pdf. | `module-reporting`. Der `.sql`-Export ist heikel (DSGVO, Rohdatenabfluss) — nur für Plattform-Admins mit Audit-Eintrag. |
| D11 | Design zeigt einen **Metro-Look-Schalter** (`cornerStyle: sharp\|rounded`, `tileFinish: flat\|card`) als Prototyp-Props. | **Entschieden (E2): fest auf `sharp` / `flat`.** Kein Umschalter, keine Radius-Token. Siehe 6.1. |

---

## 2a. Framework: Laravel

Die Grundstruktur nennt Symfony. Gesetzt ist stattdessen **Laravel** — das ist eine bewusste Entscheidung und ändert nichts an der Architektur, nur an ihrer Umsetzung. Alle Konzepte aus der Grundstruktur haben eine Entsprechung:

| Grundstruktur (Symfony) | Umsetzung in Laravel |
| --- | --- |
| Bundle je Modul | Service Provider je Modul, per Composer `extra.laravel.providers` automatisch entdeckt |
| Doctrine ORM/DBAL | Eloquent + Query Builder (siehe 2a.2) |
| Symfony Messenger | Laravel Queues (Treiber laut W3, kein Horizon — E9) |
| Symfony Security | Laravel Auth + **Sanctum** (Admin-SPA-Session), Gates & Policies für Rechteprüfung |
| Symfony Secrets | `config/*` + `.env` verschlüsselt über `php artisan env:encrypt`, alternativ Vault-Adapter |
| Doctrine Migrations je Modul | `loadMigrationsFrom()` im Service Provider — pro Modul, mehrere Pfade, ohne Zusatzpaket |
| `services.yaml` / `routes.yaml` | `register()` / `loadRoutesFrom()` im Provider |
| API Platform / Attribute-Routing | Route-Dateien je Modul + API Resources, OpenAPI via **Scramble** |
| systemd + `messenger:consume` | WinSW-Dienst je Queue mit `php artisan queue:work --queue=…` (siehe 9.2) |

### 2a.1 Mandantenfähigkeit — hier gewinnt Laravel deutlich

Das in Kapitel 23–29 der Grundstruktur beschriebene Modell (eine `platform`-DB, eine DB je Mandant, Provisioning bei Registrierung, Migrationen je Mandant) ist exakt der Funktionsumfang von **`stancl/tenancy`**:

- `tenants`-Tabelle in der zentralen DB, Tenant-Auflösung per Domain, Header oder Token
- automatisches Umschalten der DB-Verbindung pro Request (`tenancy()->initialize($tenant)`)
- `tenants:migrate` führt Modul-Migrationen über alle oder einzelne Mandanten-DBs aus
- Jobs, Cache-Keys und Dateipfade werden automatisch tenant-scoped

Das ersetzt einen erheblichen Teil dessen, was in Abschnitt 3.1 als `core/Provisioning/` und `core/Database/` geplant war. Der **Cluster-Aspekt (D2)** — mehrere DB-Server, Mandanten-Umzug — ist darin *nicht* enthalten und bleibt Eigenentwicklung: ein eigener Tenant-Database-Manager, der den Server aus `platform.db_servers` auflöst, bevor `stancl/tenancy` die Verbindung aufbaut. Das ist ein sauber definierter Erweiterungspunkt des Pakets.

### 2a.2 Der Preis: Eloquent und die Domain-Schicht

Kapitel 9 der Grundstruktur fordert eine Domain-Schicht "möglichst unabhängig von Symfony, HTTP und MySQL". Eloquent ist Active Record — das Model kennt seine Tabelle. Eine wirklich persistenz-freie Domain gibt es mit Eloquent nicht ohne zusätzliche Mapper-Schicht.

Drei Wege, empfohlen wird der dritte:

1. *Reine Domain + Eloquent nur als Mapper.* Erfüllt die Vorgabe wörtlich, kostet je Entität eine zusätzliche Klasse plus Hin- und Rückabbildung. Bei diesem Funktionsumfang unverhältnismäßig.
2. *Eloquent überall, keine Schichten.* Schnell, aber genau der Zustand, den die Modulgrenzen verhindern sollen.
3. **Eloquent-Modelle als Domain-Schicht, mit Regeln.** Die Modelle liegen in `Domain/Model/`, tragen die Geschäftsregeln als Methoden (`$widget->activate()`), und es gilt: **kein Eloquent-Modell verlässt jemals sein Modul.** Nach außen — an andere Module und an die API — gehen ausschließlich DTOs aus `PublicApi/`. Damit bleibt die entscheidende Grenze (Modul ↔ Modul) hart, und die weniger wichtige (Domain ↔ Persistenz) wird pragmatisch aufgeweicht.

Regel 6 aus Kapitel 14 ("keine Tabellen anderer Module lesen") bleibt davon unberührt und wird weiter per Architekturtest erzwungen.

### 2a.3 Was zusätzlich Disziplin erfordert

Laravel macht globalen Zugriff bequem: Facades, `app()`, `config()`, globale Helper. Genau das erodiert Modulgrenzen. Verbindlich:

- **Keine Facades innerhalb von Modulen** — Abhängigkeiten per Constructor-Injection. Facades nur in der Application-Schicht des Core.
- **Keine `Model::query()`-Aufrufe über Modulgrenzen** — ein Modul kennt fremde Modelle nicht einmal dem Namen nach.
- **Eigener Command-/Query-Bus.** Laravels `Bus::dispatch` ist ein Job-Dispatcher, kein CQRS-Bus. Ein dünner eigener Bus (~100 Zeilen) über einem Handler-Mapping, damit Commands und Queries wie in Kapitel 17/18 beschrieben funktionieren. Events dagegen direkt über Laravels Event-System.
- **Kein Filament, kein Nova.** Beide sind Admin-Panel-Generatoren; die Oberfläche ist im Design vollständig eigenständig spezifiziert und wird als React-SPA gebaut (Abschnitt 6). Ein Panel-Generator würde dagegen arbeiten.
- **Kein Inertia.** Die Admin-UI ist eine SPA gegen eine REST-API, die ohnehin für Widget und Integrationen existieren muss.

### 2a.4 Konkrete Folgen für dieses Konzept

- `php-laravel-setup` **bleibt wie es heißt** — die Umbenennung aus 9.2 entfällt.
- Die Extension-Liste aus 9.2 gilt unverändert (`pdo_mysql`, `intl`, `mbstring`, `opcache`, `redis`, `zip`, `sodium`).
- `core/Provisioning/` und `core/Database/` schrumpfen auf den Cluster-Teil (D2), der Rest kommt aus `stancl/tenancy`.
- Statische Analyse: **Larastan** (PHPStan mit Laravel-Erweiterung) auf Level 8, Architekturregeln über **Deptrac** oder **Pest Arch**.
- `node-setup` wird weiterhin gebraucht (Vite gehört ohnehin zu Laravel).

---

## 3. Repository-Landkarte

### 3.1 Dieses Repository (`platzhirschv2`) = Core

Nach deiner Vorgabe ist dies das Core-Modul und beherbergt die Admin-Oberfläche. Ich empfehle, **Application, Core, Contracts und Admin-Frontend zunächst hier zusammenzuhalten** und erst zu trennen, wenn die Contracts stabil sind (realistisch nach den ersten 2–3 Modul-Repos). Grund: Solange sich die Contracts wöchentlich ändern, kostet ein separates `platform-contracts`-Repo pro Änderung einen Release-Zyklus über drei Repos. Die Verzeichnistrennung wird trotzdem sofort eingezogen und per Architekturtest erzwungen, damit die spätere Trennung ein reiner `git filter-repo` ist.

```
platzhirschv2/
├── app/                          Composition Root (Laravel)
│   ├── app/  bootstrap/  config/  public/  routes/  storage/
│   ├── database/                 nur Plattform-Migrationen
│   └── composer.json             fordert core, contracts, module/*
├── core/                         → später platform-core
│   ├── src/
│   │   ├── Authentication/  Authorization/  Context/
│   │   ├── Database/             Cluster-Resolver vor stancl/tenancy (D2)
│   │   ├── Bus/                  CommandBus, QueryBus (eigen, siehe 2a.3)
│   │   ├── Module/               ModuleRegistry, Migrations-Runner
│   │   ├── Navigation/           NavigationRegistry (neu, siehe 6.3)
│   │   ├── Permission/           PermissionRegistry
│   │   ├── Logging/  Secrets/  Storage/
│   │   └── Provisioning/         Tenant-DB anlegen, Umzug, Cluster
│   └── composer.json             platzhirsch/core
├── contracts/                    → später platform-contracts
│   └── src/  Context/ Messaging/ Module/ Navigation/ Security/ Storage/
├── design-tokens/                → npm @platzhirsch/design-tokens (siehe 6.1)
│   ├── src/tokens.json           EINZIGE Design-Quelle für alle Oberflächen
│   ├── fonts/                    Work Sans, Newsreader, JetBrains Mono (woff2)
│   └── dist/                     generiert: tokens.css / tokens.ts / tokens.json
├── admin-ui/                     React + TypeScript (Vite)
│   ├── packages/ui/              → npm @platzhirsch/ui (Primitives, siehe 6.1.4)
│   ├── src/
│   │   ├── shell/                Layout, Sidebar, Chooser, Routing, Auth
│   │   ├── platform/             Plattform-Bereich (NAV_SYSTEM)
│   │   └── module-host/          Lädt Modul-UI-Pakete (siehe 6.3)
│   └── package.json              @platzhirsch/admin-ui
├── website/                      Öffentliche Website (Blade + Vite, siehe 6.5)
│   ├── views/                    Start, Funktionen, Preise, Registrierung, Recht
│   └── assets/                   nutzt dieselben Design-Tokens
├── design-template/              Submodul, bleibt als Referenz
├── docs/
│   ├── KONZEPT.md                dieses Dokument
│   ├── ARCHITEKTUR.md            aus grundstruktur.md fortgeschrieben
│   ├── MODULE-CONTRACT.md        wie man ein Modul baut
│   └── DESIGN-TOKENS.md          extrahierte Tokens
└── infrastructure/               → verweist auf ../middleware (siehe 9)
```

### 3.2 Eigene Repositories je Modul

Reihenfolge nach Abhängigkeit und Nutzen:

| Repo | Composer-Paket | Enthält | Phase |
| --- | --- | --- | --- |
| `module-identity` | `platzhirsch/identity` | Benutzer, Rollen, Rechte, MFA, Sessions | 1 |
| `module-customer` | `platzhirsch/customer` | Mandanten-Stammdaten, Registrierung, Restaurant-Profil | 1 |
| `module-provisioning` | `platzhirsch/provisioning` | Tenant-DB anlegen, Migrationen, Umzug, Cluster | 1 |
| `module-audit` | `platzhirsch/audit` | Audit-Log plattform- und mandantenseitig | 1 |
| `module-reservation` | `platzhirsch/reservation` | Reservierungen, Tische, Räume, Tischplan, Öffnungszeiten, Gäste | 2 |
| `module-widget` | `platzhirsch/widget` | Widget-Konfiguration, Widget-API, Embed-Code | 2 |
| `module-notification` | `platzhirsch/notification` | E-Mail/SMS, Templates, Bestätigungen | 2 |
| `module-billing` | `platzhirsch/billing` | Abos, Modul-Käufe, Rechnungen, Preise | 3 |
| `module-support` | `platzhirsch/support` | Tickets, Nachrichten, interne Notizen, SLA | 3 |
| `module-release` | `platzhirsch/release` | Release Notes je Modul | 3 |
| `module-reporting` | `platzhirsch/reporting` | Auswertungen, Exporte (D10) | 4 |
| `module-weather` | `platzhirsch/weather` | 7-Tage-Vorschau für wetterabhängige Räume | 4 |
| `module-integration-odoo` | `platzhirsch/integration-odoo` | Odoo JSON-RPC/XML-RPC-Sync | 4 |
| `widget-embed` | (npm) | Das eingebettete JS-Widget für Gästeseiten | 2 |

`module-reservation` ist bewusst **ein** Modul und nicht vier: Reservierung, Tisch, Raum und Öffnungszeit sind ein einziger Konsistenzbereich (eine Reservierung belegt einen Tisch in einem Raum zu einer Öffnungszeit). Ein Aufteilen erzwänge verteilte Transaktionen für die Kernoperation des Produkts.

### 3.3 Verteilung

**PHP-Pakete:** ein privater Composer-Repository-Server — **Satis** auf der bestehenden Gitea-Instanz (E4). Statischer `packages.json`, per CI nach jedem Tag neu gebaut. Die `platform-application` referenziert nur feste Versionen (`"platzhirsch/widget": "1.4.2"`), niemals `dev-main`.

**npm-Pakete** (`@platzhirsch/design-tokens`, `@platzhirsch/ui`, die Modul-UIs): über die **in Gitea bereits enthaltene npm-Registry**. Kein zusätzlicher Dienst nötig, gleiche Zugangsdaten wie für Git.

---

## 4. Zielbild der Systemlandschaft

```
 Gästewebsite      Interessent      Restaurant-Team    Plattform-Team
      │                 │                  │                 │
widget-embed.js   Öffentl. Website    admin-ui (React)  admin-ui (React)
      │            (Blade + Vite)          │                 │
      └───── HTTPS ────┴──────────────────┴─────────────────┘
                                 │
                    IIS ── TLS, CORS-Allowlist, Rate-Limit, Rewrite
                                 │
                          FastCGI (php-cgi-Pool)
                                 │
                    ┌────────────▼────────────┐
                    │  platform-application   │
                    │  Laravel                │
                    │  /  (öffentl. Website)  │
                    │  /api/v1/admin/…        │
                    │  /api/v1/widget/…       │
                    │  /api/v1/integrations/… │
                    └────────────┬────────────┘
                                 │
                    ┌────────────▼────────────┐
                    │      platform-core      │
                    │  TenantContext          │
                    │  Auth (Sanctum)/Policies│
                    │  Command/Query/EventBus │
                    │  ModuleRegistry         │
                    │  NavigationRegistry     │
                    │  stancl/tenancy         │
                    │   + ClusterResolver ────┼──► Cluster-Routing (D2)
                    └────────────┬────────────┘
                                 │
   ┌──────────┬──────────┬───────┴───────┬──────────┬──────────┐
identity  customer  reservation      widget    billing    support …
   └──────────┴──────────┴───────┬───────┴──────────┴──────────┘
                                 │
              ┌──────────────────┼──────────────────┐
        eu-db-01            eu-db-02            na-db-01
      platform DB         tenant_000002       tenant_000007
      tenant_000001       tenant_000005       tenant_000011
                                 │
                  Cache + Queue (MySQL, siehe W3)
                                 │
                 WinSW-Dienste: default / mail
                        / provisioning / import
```

---

## 5. Datenmodell

### 5.1 Plattform-Datenbank (`platform`)

Gegenüber der Grundstruktur ergänzt um Cluster-Verwaltung (D2), Modul-Katalog (D8) und Support (D3):

```
tenants                  id, name, slug, status(aktiv|testphase|gesperrt), since,
                         contact_name, email, phone, address
users                    id, email, password_hash, mfa_secret, status, last_login_at
tenant_users             tenant_id, user_id, role_id
roles                    id, scope(plattform|mandant), tenant_id NULL, name, code, locked
permission_families      id, module, code, label            ← aus Registry gespiegelt
permissions              id, family_id, code, label          ← aus Registry gespiegelt
role_permissions         role_id, permission_id, granted

db_servers               id, host, port, region, cluster, status      ← NEU (D2)
tenant_databases         tenant_id, db_server_id, db_name, db_user,
                         status, migrated_at                           ← erweitert (D2)

module_catalog           code, name, description, is_core, latest_version,
                         price_monthly                                 ← erweitert (D8)
tenant_modules           tenant_id, module_code, purchased_at,
                         activated_at, version, status                 ← erweitert (D8)

subscriptions            tenant_id, base_price, started_at, status
invoices                 tenant_id, period, amount, due_date, status
invoice_lines            invoice_id, label, amount

api_clients              tenant_id, client_id, secret_hash, scopes, rate_limit
widget_clients           tenant_id, token_hash, allowed_domains, expires_at

support_tickets          ticket_no, tenant_id, subject, category, priority,
                         severity, status, assignee_id, due_date         ← NEU (D3)
support_messages         ticket_id, author_id, kind(reply|internal), body ← NEU (D3)

releases                 module_code, version, notes, git_url, released_at ← NEU (D4)
release_categories       release_id, category                             ← NEU (D4)

system_audit             tenant_id, actor_id, action, module, resource_type,
                         resource_id, correlation_id, ip, user_agent,
                         result, created_at
```

### 5.2 Mandanten-Datenbank (`tenant_000001`)

Tabellenpräfix = Modulcode. Jede Tabelle gehört genau einem Modul.

```
identity_*        users, roles, role_permissions, sessions
customer_*        settings, profile
reservation_*     reservations, guests, tables, rooms, opening_hours, special_dates
widget_*          configurations, bookings
notification_*    preferences, templates, outbox
billing_*         (mandantenseitige Sicht, falls benötigt)
support_*         (mandantenseitige Ticketsicht)
audit_entries
module_migrations   module_name, migration_version, checksum, status,
                    executed_at, error_message
```

---

## 6. Frontend-Konzept

### 6.1 Zentrale Design-Quelle

Es gibt **vier Stellen, an denen Oberfläche entsteht**, und sie verwenden unterschiedliche Technik:

| Oberfläche | Technik | Besonderheit |
| --- | --- | --- |
| Admin-UI | React SPA | die mit Abstand größte Fläche |
| Modul-Screens | React, **aus fremden Repos** | müssen die Tokens beziehen können, ohne die Admin-UI zu kennen |
| Öffentliche Website | Blade + Vite | kein React |
| Widget-Embed | JS auf **fremden Gästeseiten** | darf nichts von der Host-Seite erben und nichts an sie vererben |

Wenn jede dieser vier Stellen ihre Farben selbst hinschreibt, driftet das Design innerhalb eines Jahres auseinander. Es braucht also genau **eine Quelle**, aus der alle vier sich bedienen.

#### 6.1.1 Warum kein globales SCSS

Ein `_variables.scss` löst das Problem nicht, aus drei Gründen:

1. **SCSS-Variablen existieren nur zur Kompilierzeit.** Nach dem Build sind sie verschwunden — im Browser stehen nur noch feste Farbwerte. Ein Modul-Repo, das sein eigenes CSS baut, müsste die SCSS-Datei zum Bauen einbinden und würde die Werte **einbacken**. Ändert sich später eine Farbe, muss jedes Modul neu gebaut und neu veröffentlicht werden.
2. **Das Widget braucht Laufzeit-Werte.** Der Embed-Code aus dem Prototyp übergibt `data-color="…"` je Mandant. Eine mandantenspezifische Akzentfarbe lässt sich mit einer Kompilierzeit-Variablen nicht abbilden.
3. **Was SCSS früher gebracht hat, kann CSS heute selbst.** Verschachtelung, Variablen und `@layer` sind nativ verfügbar. Der verbleibende Vorteil (Mixins, Schleifen) wiegt eine zusätzliche Build-Stufe in vier Projekten nicht auf.

Stattdessen: **CSS Custom Properties als Ausgabeformat, eine JSON-Datei als Quelle.** Custom Properties sind zur Laufzeit vorhanden, vererben sich, lassen sich pro Mandant überschreiben und funktionieren in React, Blade und im Widget identisch.

#### 6.1.2 Aufbau

```
platzhirschv2/
└── design-tokens/                    → npm-Paket @platzhirsch/design-tokens
    ├── src/tokens.json               ◄── DIE EINZIGE QUELLE
    ├── build.mjs
    └── dist/
        ├── tokens.css                :root { --ph-color-bg: …; }   für Blade & Widget
        ├── tokens.ts                 typisierte Konstanten          für React & Tests
        └── tokens.json               Rohwerte                       für Werkzeuge
```

`tokens.json` ist die Quelle, alles darunter wird generiert und ist nicht von Hand zu bearbeiten. Der Build erzeugt aus einem Eintrag drei Ausgaben:

```jsonc
// src/tokens.json
{ "color": { "bg": "oklch(0.19 0.004 260)", "accent": "oklch(0.68 0.14 45)" } }
```

```css
/* dist/tokens.css */
:root { --ph-color-bg: oklch(0.19 0.004 260); --ph-color-accent: oklch(0.68 0.14 45); }
```

```ts
// dist/tokens.ts
export const color = { bg: 'var(--ph-color-bg)', accent: 'var(--ph-color-accent)' } as const;
```

Der TypeScript-Export liefert bewusst **`var(--…)` statt des Farbwerts**. Damit ist auch in React die Laufzeit-Variable im Spiel und nicht der eingebackene Wert — Mandanten-Akzentfarben und ein späterer Hell-Modus funktionieren dadurch überall gleich.

Warum ein npm-Paket und nicht einfach ein Ordner: Es ist dasselbe Verteilungsproblem wie bei den PHP-Modulen (Abschnitt 3.3). Ein Modul in einem fremden Repo kann keinen relativen Pfad in die Admin-UI legen. **Gitea bringt eine npm-Registry bereits mit** — es braucht also kein zusätzliches Werkzeug neben Satis.

#### 6.1.3 Wie die vier Oberflächen zugreifen

**Admin-UI und Modul-Screens** — CSS Modules, Werte aus Custom Properties:

```css
/* Button.module.css */
.button { background: var(--ph-color-accent); color: var(--ph-color-accent-text);
          border: none; padding: var(--ph-space-3) var(--ph-space-4); }
```

**Öffentliche Website (Blade)** — `tokens.css` einbinden, dieselben Variablen:

```blade
@vite(['node_modules/@platzhirsch/design-tokens/dist/tokens.css', 'website/assets/site.css'])
```

**Widget-Embed** — Sonderfall. Es läuft auf fremden Seiten, deren CSS es weder stören noch von ihm gestört werden darf. Deshalb **Shadow DOM**, und die Tokens werden in den Shadow Root hineingeschrieben statt auf `:root`:

```ts
shadow.adoptedStyleSheets = [tokenSheet, widgetSheet];
if (config.color) shadow.host.style.setProperty('--ph-color-accent', config.color);
```

Das ist zugleich die Stelle, an der `data-color` aus dem Embed-Code (Zeile 5099 des Prototyps) wirksam wird — eine einzige überschriebene Variable färbt das gesamte Widget um.

#### 6.1.4 Komponenten: `@platzhirsch/ui`

Über den Tokens liegt eine zweite Schicht, ebenfalls als npm-Paket, damit Modul-Repos sie nutzen können:

```
admin-ui/packages/ui/            → @platzhirsch/ui
├── Button  Input  Select  Toggle  Badge
├── Table   Tile   Modal   Tabs   Drawer
└── Field   EmptyState  StatusDot
```

Es wird **kein UI-Framework** (Material UI/Mantine/Bootstrap) eingezogen. Der Prototyp definiert ein sehr spezifisches, kantiges, dunkles System — jedes Framework würde dagegen arbeiten und müsste zu >70 % überschrieben werden. Im Prototyp kommen ohnehin nur diese ~13 Muster vor. Für Zugänglichkeit und Fokusverhalten dienen **Radix Primitives** (ohne Styling) als Unterbau von Modal, Select, Tabs und Tooltip.

Ebenfalls kein **Tailwind**: Bei einem festen, nicht konfigurierbaren Design (E2) verlagert es die Gestaltung in lange Klassenlisten im JSX, statt sie in benannten Komponenten zu bündeln — und Modul-Repos müssten seine Konfiguration mitschleppen.

#### 6.1.5 Verbindliche Regel

> In `admin-ui/`, `website/`, den Modul-UIs und dem Widget steht **kein einziger literaler Farb-, Abstands- oder Schriftwert**. Jeder Wert kommt aus `@platzhirsch/design-tokens`.

Das wird per Lint-Regel erzwungen (`stylelint` mit `declaration-property-value-disallowed-list` für `oklch(`, `#`, `rgb(` außerhalb des Token-Pakets), sonst hält es keine zwei Monate.

#### 6.1.6 Wenn sich das Design ändert

`design-template/` bleibt als Submodul die Referenz. Kommt ein überarbeiteter Prototyp, ist der Ablauf: Werte aus dem neuen `.dc.html` extrahieren → mit `tokens.json` abgleichen → Token-Paket mit neuer Minor-Version veröffentlichen → Anwendungen ziehen sie über `npm update`. Neue *Komponenten* dagegen wandern zuerst in `@platzhirsch/ui` und erst dann in die Screens.

#### 6.1.7 Die extrahierten Werte

Aus dem Prototyp bereits ermittelt:

```ts
// design-tokens/src/tokens.json — hier als TS dargestellt
export const color = {
  bg:            'oklch(0.19 0.004 260)',   // App-Hintergrund
  bgSidebar:     'oklch(0.155 0.004 260)',
  bgSurface:     'oklch(0.235 0.005 260)',  // Karten, Tiles
  bgInput:       'oklch(0.15 0.004 260)',
  bgLogin:       'oklch(0.15 0.004 260)',
  text:          'oklch(0.94 0.002 260)',
  textMuted:     'oklch(0.58 0.006 260)',
  textFaint:     'oklch(0.5 0.006 260)',
  border:        'oklch(1 0 0 / 0.09)',
  borderStrong:  'oklch(1 0 0 / 0.16)',
  accent:        'oklch(0.68 0.14 45)',     // Terracotta
  accentSoft:    'oklch(0.68 0.14 45 / 0.14)',
  success:       'oklch(0.72 0.13 150)',
  warning:       'oklch(0.72 0.13 85)',
  danger:        'oklch(0.68 0.17 25)',
} as const;

export const font = {
  sans:    "'Work Sans', sans-serif",     // UI
  serif:   "'Newsreader', serif",         // Zahlen/Headlines im Admin
  mono:    "'JetBrains Mono', monospace", // Hosts, Codes, Embed-Snippets
} as const;

// Entscheidung E2: Metro-Look fest auf sharp/flat.
// Es gibt keinen Umschalter — Ecken sind eckig, Flächen ohne Schatten.
export const radius = { none: '0' } as const;
export const surface = { finish: 'flat' } as const;
```

Die `border-radius`-Platzhalter aus dem Prototyp (`r8`, `r9`, `r10`, `r11`, `rCard`) werden damit **nicht** übernommen — sie waren Teil des abwählbaren „rounded"-Modus. Alle Flächen bekommen scharfe Kanten und keine Schatten; Abgrenzung erfolgt ausschließlich über Hintergrundhelligkeit und 1px-Rahmen. Das entspricht der Darstellung der beiden Login-Screens, die bereits durchgängig ohne Radius gebaut sind.

Dazu kommen Abstände und Schriftgrößen, die im Prototyp ebenfalls durchgängig verwendet werden und mit zu extrahieren sind (`space`: 2/4/6/8/10/12/14/16/20/24/28/36 px; `fontSize`: 11/11.5/12/12.5/13/13.5/14/14.5/15/17/18/19/24/26 px — beim Übertragen auf eine saubere Skala zu vereinheitlichen).

Raumfarben (`ROOM_COLOR_OPTIONS`: terracotta, sage, sky, mustard, plum, slate) und Raum-Icons (`ROOM_ICON_OPTIONS`) gehören **nicht** in die Tokens — das sind Fachdaten des Reservierungsmoduls, die der Nutzer je Raum auswählt, und sie wandern dorthin.

Die Schriften werden **lokal ausgeliefert**, nicht von Google Fonts geladen wie im Prototyp: Der Widget-Code läuft auf fremden Seiten, und ein Aufruf an einen Google-Server von dort aus ist datenschutzrechtlich heikel. Die drei Familien (Work Sans, Newsreader, JetBrains Mono) liegen als `woff2` im Token-Paket.

### 6.2 Struktur der Shell

```
admin-ui/src/shell/
├── AuthGate.tsx        Login, MFA, Session-Refresh
├── ScopeChooser.tsx    "Restaurant" vs. "Plattform"  (isChooser im Prototyp)
├── AppShell.tsx        Sidebar (ein-/ausklappbar), Topbar, Content-Slot
├── Sidebar.tsx         gespeist aus NavigationRegistry, gefiltert nach Rechten
└── router.tsx          React Router, Routen aus der Registry
```

Die Sidebar ist im Prototyp bereits ein-/ausklappbar mit Favoriten (`sidebarFavItems`) — das bleibt, die Favoriten werden pro Benutzer in `identity_*` persistiert.

### 6.3 Wie Module Oberfläche beisteuern — die zentrale Entscheidung

Jedes Modul-Repo enthält **sowohl Backend (PHP) als auch Admin-UI (React)**. Die UI wird als npm-Paket publiziert und zur **Build-Zeit** eingebunden:

```
module-reservation/
├── src/                       PHP (Domain/Application/Infrastructure/PublicApi)
│   └── ReservationServiceProvider.php   registriert Routen, Migrationen, Rechte, Nav
├── ui/                        React
│   ├── src/screens/…          Reservierungen, Tischplan, Tische, Räume, Öffnungszeiten
│   ├── src/manifest.ts        Nav-Einträge, Routen, benötigte Rechte
│   └── package.json           @platzhirsch/module-reservation-ui
├── migrations/
└── composer.json
```

`manifest.ts` deklariert:

```ts
export default {
  code: 'reservation',
  nav: [
    { key: 'reservations', label: 'Reservierungen', order: 20,
      permission: 'reservation.view', scope: 'mandant',
      icon: CalendarIcon, screen: () => import('./screens/Reservations') },
    { key: 'tables',  label: 'Tischplan', order: 30, permission: 'tables.view', … },
  ],
} satisfies ModuleUiManifest;
```

Der `module-host` im Core sammelt alle installierten Manifeste, sortiert nach `order`, filtert gegen die vom Backend gelieferte Rechte- und Modul-Aktivierungsliste und baut daraus Sidebar und Routen. Die Screens werden lazy geladen.

**Warum Build-Zeit und nicht Module Federation zur Laufzeit:** Die Menge installierter Module ändert sich pro *Release*, nicht pro *Request*. Die Aktivierung pro Mandant ist eine Sichtbarkeitsfrage, keine Auslieferungsfrage. Laufzeit-Federation brächte Versions-Skew zwischen Shell und Modul-Bundles, doppelte React-Instanzen und eine erheblich schwerere Fehlersuche — für einen Nutzen, den `composer.lock` + `package-lock.json` bereits sauberer abbilden. Falls später Mandanten wirklich eigene Modulversionen fahren sollen, ist der Umstieg möglich, weil das Manifest-Interface identisch bleibt.

### 6.4 Datenzugriff

TanStack Query gegen `/api/v1/admin/…`. Die API-Typen werden aus der OpenAPI-Spezifikation generiert (`openapi-typescript`), die **Scramble** aus den Laravel-Controllern und Form Requests ableitet. Damit bricht eine Backend-Änderung den Frontend-Build, nicht die Produktion.

### 6.5 Öffentliche Website mit Selbstregistrierung

Ab Phase 2 Teil des Produkts (Entscheidung E5). Umfang: Startseite, Funktionsübersicht, Preise (die Preislogik aus D8 — Basispaket 29 € plus Zusatzmodule), Registrierungsformular, Impressum, Datenschutz, AGB.

Umsetzung als **Blade-Views mit Vite**, nicht als zweite React-Anwendung: Die Seite ist inhaltsgetrieben, muss von Suchmaschinen gelesen werden und schnell laden. Sie verwendet dieselben Design-Tokens aus 6.1, damit Website und Anwendung visuell zusammengehören.

**Im Design-Prototyp existiert dazu nichts.** Es fehlen also Entwürfe für alle genannten Seiten inklusive des Registrierungsformulars. Das ist ein eigenständiges Design-Arbeitspaket vor Phase 2 und nicht aus dem vorhandenen Material ableitbar.

Der Registrierungsablauf löst genau die Kette aus Kapitel 41 der Grundstruktur aus:

```
Formular → Validierung → Mandant angelegt (Status: testphase)
         → E-Mail-Bestätigung
         → Event CustomerRegistered
              ├── Provisioning: Datenbank, Benutzer, Rechte, Migrationen
              ├── Identity: erster Administrator + Einrichtungs-Link
              ├── Widget: Standardkonfiguration
              ├── Billing: Vertragsdaten
              ├── Notification: Willkommens-E-Mail
              └── Audit: Protokollierung
```

Damit wird `module-provisioning` **von einem internen Werkzeug zu einer öffentlich auslösbaren Funktion** — das ist die wichtigste Folge dieser Entscheidung. Erforderlich sind deshalb:

- E-Mail-Verifikation **vor** dem Anlegen der Datenbank (sonst legt jeder Bot Datenbanken an)
- Rate-Limit und Bot-Schutz (Captcha oder Honeypot) am Formular
- Mengenbegrenzung: maximal N Registrierungen pro IP und Tag, Alarm bei Überschreitung
- Aufräumauftrag: nicht bestätigte Mandanten nach X Tagen samt Datenbank entfernen
- Der Registrierungspfad läuft in einer eigenen IIS-Site mit eigenem Anwendungspool, getrennt von Admin- und Widget-API

Der im Design vorhandene Weg „Mandant einladen" durch das Plattform-Team **bleibt zusätzlich bestehen** — für Kunden, die telefonisch oder im Vertrieb gewonnen werden.

---

## 7. Backend-Konzept

### 7.1 Modul-Kontrakt

Jedes Modul liefert einen Service Provider, der zusätzlich ein `Module`-Interface implementiert. Der Provider erledigt die Laravel-Verdrahtung (`loadRoutesFrom`, `loadMigrationsFrom`, Bindings), das Interface liefert die Metadaten, die der Core für Registry, Boot-Prüfungen und Navigation braucht:

```php
interface Module
{
    public function name(): string;
    public function version(): string;
    public function dependencies(): array;
    public function optionalDependencies(): array;

    /** @return PermissionFamily[] — wird in platform.permissions gespiegelt */
    public function permissions(): array;

    /** Migrationen für die Mandanten-DB */
    public function tenantMigrationsPath(): ?string;

    /** Migrationen für die Plattform-DB */
    public function platformMigrationsPath(): ?string;

    /** Tabellenpräfix — Grundlage der Eigentümerprüfung */
    public function tablePrefix(): string;
}
```

### 7.2 Boot-Prüfungen

Beim Anwendungsstart (und im CI als Test) wird geprüft, was die Grundstruktur in Kapitel 13 fordert: Pflichtmodule vorhanden, Versionen kompatibel, keine doppelten Routen, keine doppelten Berechtigungscodes, keine Zyklen, alle Migrationen ausgeführt. Zusätzlich: **keine Tabellenpräfix-Kollision**.

### 7.3 Tenant-Kontext und Cluster-Routing

```php
final readonly class RequestContext
{
    public function __construct(
        public string $tenantId,
        public string $actorId,
        public string $correlationId,
        public ActorType $actorType,   // user | api_client | widget_client | system
    ) {}
}
```

Ein eigener `ClusterAwareDatabaseManager` hängt sich in `stancl/tenancy` ein und löst auf: `tenantId` → `tenant_databases` → `db_servers` → Connection-Konfiguration (Redis-gecacht), bevor das Paket die Verbindung herstellt. Der Client bestimmt die Datenbank **nie**; die Tenant-ID stammt ausschließlich aus dem verifizierten Sanctum-Token bzw. der Session.

### 7.4 Architekturtests

Verbindliche Regeln aus Kapitel 14 der Grundstruktur werden mit **Deptrac** bzw. **Pest Arch** erzwungen, nicht per Review. Mit Laravel sind sie wichtiger als mit Symfony, weil Facades und globale Helper das Umgehen von Grenzen bequem machen (siehe 2a.3):

- `Modules\X\*` darf `Modules\Y\*` nur über `Modules\Y\PublicApi\*` referenzieren
- Kein Eloquent-Modell verlässt sein Modul — `PublicApi` gibt ausschließlich DTOs zurück
- `Core\*` darf `Modules\*` überhaupt nicht referenzieren
- Keine `Illuminate\Support\Facades\*` innerhalb von `Modules\*`
- SQL-Statements dürfen nur Tabellen mit dem eigenen Präfix nennen (statischer Test über Repository-Klassen)

Ohne diese Tests erodiert die Modulgrenze innerhalb weniger Monate — das ist der übliche Ausgang von modularen Monolithen.

---

## 8. Sicherheit

Über die Liste in Kapitel 34 der Grundstruktur hinaus, hergeleitet aus dem Design:

| Fläche | Maßnahme |
| --- | --- |
| Setup-Tab (D7) | Kein freies SQL. Nur benannte, im Code hinterlegte, signierte Operationen. Nur `SYSTEM_ADMIN` + MFA-Reauthentifizierung. Jeder Lauf mit vollem Log ins Audit. |
| Mandanten-Umzug / Massen-Migration | Zwei-Faktor-Bestätigung (der Prototyp fordert bereits Backup- und Impact-Häkchen — das bleibt). Nur über `messenger-provisioning`-Worker, nie im Web-Request. |
| DB-Zugangsdaten in der UI | Passwörter werden nie zurückgeliefert, nur `••••••••`. Schreiben ist Ersetzen. Ablage verschlüsselt über Laravels `Crypt` mit separatem Key, nicht in der DB im Klartext. |
| Widget-API | Eigene kurzlebige Tokens, Domain-Allowlist aus `widget_clients.allowed_domains`, striktes Rate-Limit, keine Admin-Scopes, eigene CORS-Konfiguration. |
| Odoo-Integration (D5) | API-Key verschlüsselt, ausgehende Verbindungen über Allowlist, Sync ausschließlich asynchron. |
| Export `.sql` (D10) | Nur Plattform-Admin, Audit-pflichtig, zeitlich begrenzter Download-Link. |
| Rollen-Editor (D9) | Systemrollen (`locked = true`) nicht bearbeitbar. Eine Rolle kann keine Berechtigung vergeben, die der handelnde Nutzer selbst nicht hat (kein Privilege Escalation). |

---

## 9. Betrieb unter Windows und Anpassungen an den Middleware-Modulen

**Zielplattform ist Windows Server** (Entscheidung E6), später auf eigener Hardware (E7). Damit entfallen die Kapitel 35–37 der Grundstruktur (Linux, systemd, `/var/www`) vollständig und werden durch die folgenden Entsprechungen ersetzt. Die vorhandenen Middleware-Submodule sind damit die richtige Grundlage — sie sind bereits Windows-first und nutzen mit `NginxService.exe` / `PhpCgiService.exe` schon WinSW als Dienst-Wrapper.

### 9.1 Drei Punkte, die unter Windows anders gelöst werden müssen

Diese drei sind technische Gegebenheiten, keine Vorlieben. Sie sind vor Phase 1 zu entscheiden und zu verifizieren.

**W1 — PHP-FPM existiert unter Windows nicht.**
Es gibt nur `php-cgi.exe`, das keinen Prozesspool verwaltet. Ein einzelner `php-cgi`-Prozess bearbeitet Anfragen nacheinander; der heutige `PhpCgiService.exe` startet genau eine Instanz. Für Produktion ist das zu wenig.
Zwei Wege:
- **IIS + FastCGI** (Empfehlung). IIS bringt mit seinem FastCGI-Handler das mit, was php-fpm unter Linux leistet: einen verwalteten Pool von `php-cgi`-Prozessen mit `MaxInstances`, Recycling und Crash-Erholung. Das ist der einzige unter Windows offiziell unterstützte und produktiv erprobte PHP-Betriebsmodus.
- **Nginx + mehrere `php-cgi`-Instanzen.** Je Instanz ein WinSW-Dienst auf eigenem Port, in Nginx als `upstream`-Pool eingetragen. Funktioniert, ist aber Eigenbau: fällt eine Instanz aus, startet sie niemand nach, und die Poolgröße ist statisch.

**W2 — Nginx unter Windows ist deutlich schwächer als unter Linux.**
Die offizielle Nginx-Dokumentation weist darauf hin, dass die Windows-Version nur die `select()`-Verbindungsmethode kennt und faktisch mit einem Worker-Prozess arbeitet. Für die Widget-API, die von vielen Gästeseiten gleichzeitig angefragt wird, ist das die Engstelle des Systems.
Zusammen mit W1 spricht das dafür, **IIS als Webserver zu verwenden** und `nginx-setup` auf die Entwicklungsumgebung zu beschränken. IIS bringt zusätzlich fertig mit, was sonst nachgebaut werden müsste: TLS-Verwaltung über den Windows-Zertifikatspeicher, IP-Rate-Limiting (Dynamic IP Restrictions), Request Filtering und Header-Regeln (URL Rewrite).

**W3 — Redis wird für Windows nicht mehr offiziell gepflegt.**
Der Microsoft-Fork steht bei 3.2 (2016) und ist für Produktion ungeeignet. Drei Möglichkeiten:
- **Memurai** — kommerzielle, Redis-7-kompatible native Windows-Variante. Kostenpflichtig, aber ein direkter Ersatz ohne Anwendungsänderung.
- **Ganz auf Redis verzichten.** Laravel kann Cache, Session und Queue vollständig über MySQL betreiben (`cache.driver=database`, `queue.default=database`). Für die zu erwartende Größenordnung — einige hundert Mandanten, Reservierungen sind kein Hochlast-Szenario — ist das tragfähig und spart eine komplette Komponente samt Betrieb. **Das ist meine Empfehlung für den Start.**
- Redis in einer Linux-VM auf demselben Host. Bringt aber genau die Betriebssystem-Mischung zurück, die mit E6 vermieden werden sollte.

Die Entscheidung gegen Redis hat eine Folge: Der Queue-Durchsatz ist niedriger und `queue:work` pollt die Datenbank. Bei den in Kapitel 22 genannten Aufgaben (E-Mail, Provisioning, Importe) fällt das nicht ins Gewicht. Ein späterer Wechsel auf Memurai ist eine reine Konfigurationsänderung — deshalb ist die Entscheidung nicht bindend.

### 9.2 Entsprechungstabelle Linux → Windows

| Grundstruktur (Linux) | Umsetzung unter Windows |
| --- | --- |
| Nginx + PHP-FPM | IIS + FastCGI-Pool (W1/W2) |
| systemd-Unit je Worker | **WinSW**-Dienst je Worker (bereits im Einsatz), `php artisan queue:work --queue=…` |
| `systemctl is-active` | `Get-Service` / WinSW-Statusabfrage |
| Cron / systemd-Timer | Windows-Aufgabenplanung, `php artisan schedule:run` minütlich |
| `/var/www/platform/current` → Symlink | `C:\platzhirsch\current` als **Verzeichnisverknüpfung** (`mklink /J`) auf `releases\<version>` |
| `php-fpm reload` | `Restart-WebAppPool` bzw. IIS-Anwendungspool-Recycling |
| Certbot / ACME | `win-acme` (ACME-Client für IIS, automatische Erneuerung als geplante Aufgabe) |
| `www-data`-Benutzer | Dedizierter Dienstkonto-Benutzer (gMSA oder lokales Konto) ohne interaktive Anmeldung |
| Dateirechte via `chown` | NTFS-ACLs: `storage\`, `bootstrap\cache\` beschreibbar für das Dienstkonto, sonst nur lesend |

Zwei Windows-Fallstricke, die früh Ärger machen und deshalb ins Setup gehören:

- **Lange Pfade.** `vendor/`-Bäume überschreiten regelmäßig die 260-Zeichen-Grenze. `LongPathsEnabled` in der Registry setzen **und** die Installation flach halten (`C:\platzhirsch\`, nicht unter `C:\Users\…\Documents\…`).
- **Virenscanner.** Echtzeit-Scan auf `vendor\`, `storage\framework\` und dem MySQL-Datenverzeichnis kostet erheblich Leistung. Ausnahmen im Setup-Skript dokumentieren und setzen.

### 9.3 Fehlende Submodule

| Neu | Warum |
| --- | --- |
| `iis-setup` | IIS-Rollen, FastCGI-Handler für PHP, Anwendungspools je Site (Admin-API, Widget-API, öffentliche Website), URL Rewrite, Dynamic IP Restrictions. Ersetzt `nginx-setup` in Produktion (W1/W2). |
| `node-setup` | Node/pnpm für den Vite-Build von Admin-UI und öffentlicher Website. |
| `platform-deploy` | Release-Verzeichnisse, Junction-Umschaltung, WinSW-Dienste für die Worker, Anwendungspool-Recycling, Laravel-Cache-Kommandos, Health-Check. Ersetzt Kapitel 37/38 der Grundstruktur. |
| `backup-setup` | Kapitel 34 fordert getestete, **externe** Backups: `mysqldump` je Mandanten-DB per geplanter Aufgabe, Ablage außerhalb der Maschine, plus regelmäßiger Wiederherstellungstest. |
| `tls-setup` | `win-acme` gegen IIS, automatische Erneuerung. Der gesamte Zugriff ist HTTPS-only. |
| `redis-setup` | **Nur falls W3 auf Memurai fällt.** Bei der empfohlenen Datenbank-Variante entfällt das Submodul ersatzlos. |

### 9.4 Änderungen an bestehenden Submodulen

**`php-laravel-setup`**
Bleibt inhaltlich und namentlich richtig (siehe 2a). Zu ergänzen: Extensions explizit prüfen und installieren — `pdo_mysql`, `intl`, `mbstring`, `opcache`, `zip`, `sodium`, `bcmath`, `fileinfo`, `openssl` (`redis` nur bei W3 = Memurai). Für Produktion `opcache.enable=1` mit `validate_timestamps=0` konfigurieren (macht den Anwendungspool-Recycle beim Deployment zwingend). Laravel-Cache-Kommandos in die Deploy-Kette: `config:cache`, `route:cache`, `event:cache` — **kein** `view:cache`, solange keine Blade-Views ausgeliefert werden. `PhpCgiService.xml` auf mehrere Instanzen erweitern bzw. bei IIS ganz durch den FastCGI-Pool ersetzen.

**`nginx-setup`**
Wird zum reinen **Entwicklungs-Submodul** herabgestuft (Begründung W2), bleibt aber gepflegt, weil es lokal schneller aufgesetzt ist als IIS. `test.php` gehört nicht in ein Produktionspaket und wird entfernt.

**`mysql-setup`**
Trägt unter Windows mehr Verantwortung als geplant, wenn W3 auf die Datenbank-Variante fällt (Cache + Queue + Session laufen dann ebenfalls hier). Muss durchsetzen: `utf8mb4`/InnoDB als Server-Default, `sql_mode` strikt, Listener nur auf `127.0.0.1` bzw. dem internen Netz, getrennte Benutzer `app_platform` und `provisioning_service` mit minimalen Rechten (nur `provisioning_service` darf `CREATE DATABASE` / `CREATE USER`), MySQL als Dienst unter eigenem Konto, `innodb_buffer_pool_size` an die Hardware angepasst. Die mitgelieferte Version 9.7 ist vor Phase 1 gegen Eloquent und `stancl/tenancy` zu verifizieren.

**`Git-Setup`**
Für den Produktivserver nicht erforderlich (Deployment über Artefakte, nicht über `git pull`). Bleibt reines Entwicklerwerkzeug und wird im Konzept ausdrücklich so gekennzeichnet, damit es nicht in die Server-Installationskette rutscht.

### 9.5 Querschnittsänderungen an allen Submodulen

Diese vier Punkte sind der eigentliche Hebel:

1. **Binärdateien aus Git entfernen.** `php-8.4-…zip`, `mysql-9.7.1-winx64.zip`, `nginx-1.31.3.zip`, `Git-2.51.2-64-bit.exe`, `vc_redist.x64.exe`, `composer.phar`, die WinSW-Executables — zusammen mehrere hundert MB, die bei jedem Klon jedes Entwicklers und jedes CI-Laufs übertragen werden und die Historie dauerhaft belasten. Stattdessen: Download zur Installationszeit von der Herstellerquelle, mit **fest hinterlegter SHA-256-Prüfsumme** im Skript. Das ist zugleich ein Sicherheitsgewinn — heute vertraut die Installation einem Binary aus der Repo-Historie ohne jede Verifikation. Alternative bei Offline-Anforderung: Gitea Releases oder Git LFS.

2. **Idempotenz und Unattended-Modus.** Jedes Skript muss mehrfach ausführbar sein, ohne Schaden anzurichten, und ohne Rückfragen laufen (`-Unattended`), sonst ist keine automatisierte Provisionierung möglich. Einheitliche Exit-Codes (0 = ok, 1 = Fehler, 2 = bereits installiert). Skripte werden signiert oder laufen unter einer definierten `ExecutionPolicy` — nicht per `Bypass`.

3. **Maschinenlesbares Ergebnis.** Jedes Skript schreibt nach Abschluss ein `manifest.json`:
   ```json
   { "component": "iis", "version": "10.0.26200", "installedAt": "…",
     "configPath": "C:\\platzhirsch\\config\\iis",
     "serviceName": "W3SVC",
     "healthCheck": "Get-Service W3SVC",
     "status": "ok" }
   ```
   Damit kann die im Design vorhandene Ansicht **System-Status** (Webserver/PHP/MySQL/Queue-Worker je Cluster mit Uptime) echte Daten anzeigen statt Platzhalter. Ohne diesen Punkt bleibt der Screen dauerhaft eine Attrappe.

4. **Ein gemeinsamer Einstiegspunkt.** `middleware/Install-Platform.ps1` orchestriert die Submodule in korrekter Reihenfolge, liest eine `platform.config.json` (Rolle: `app` | `db` | `worker` | `all`) und installiert nur die passenden Komponenten. Heute muss man vier Skripte in der richtigen Reihenfolge von Hand starten, und die Reihenfolge steht nirgends.

### 9.6 Anbindung an dieses Repository

`middleware` wird **nicht** als Submodul in `platzhirschv2` eingehängt. Betriebs- und Anwendungscode haben unterschiedliche Lebenszyklen und Zugriffsrechte. Stattdessen: `docs/BETRIEB.md` referenziert die geprüfte Middleware-Version, und die CI-Pipeline zieht sie beim Bau des Server-Images per Tag.

---

## 10. Umsetzungsplan

### Phase 0 — Fundament (1–2 Wochen)

- Dieses Repo initialisieren: Laravel-Skelett, `core/`, `contracts/`, `admin-ui/` (Vite + React + TS)
- **`design-tokens` als Erstes** (6.1): Werte aus dem Prototyp extrahieren, Build auf `tokens.css`/`tokens.ts`, Schriften lokal einbetten, als npm-Paket veröffentlichen. Alles Weitere hängt daran.
- `@platzhirsch/ui` mit den ~13 Primitives, dann beide Login-Screens 1:1 nachbauen — sie sind klein und dienen als Referenzimplementierung des Design-Systems
- Satis **und Gitea-npm-Registry** einrichten, CI-Pipeline (Larastan Level 8, Deptrac/Pest Arch, Pest, Vitest, stylelint-Regel gegen literale Farbwerte)
- `stancl/tenancy` einbinden und den Cluster-Resolver (D2) als Erweiterungspunkt aufsetzen
- **W1/W2/W3 auf einem Testserver verifizieren**, bevor sie festgeschrieben werden: IIS + FastCGI-Pool mit PHP 8.4, Lasttest gegen die Widget-Route, Queue über MySQL. Das ist der risikoreichste Teil des Plans und gehört an den Anfang.
- `node-setup` und `iis-setup` in `middleware` anlegen; Binärdateien aus allen Submodulen entfernen (9.5.1)
- Design-Arbeitspaket öffentliche Website beauftragen (6.5) — es fehlt vollständig und ist Voraussetzung für Phase 2

### Phase 1 — Betriebsfähiger Kern (4–6 Wochen)

- Core: TenantContext, ClusterAwareDatabaseManager, Command/Query-Bus, ModuleRegistry, PermissionRegistry, NavigationRegistry, Migrations-Runner über alle Mandanten
- `module-identity`, `module-customer`, `module-provisioning`, `module-audit` als eigene Repos
- Admin-Shell: Login, MFA, Bereichsauswahl, Sidebar, Routing
- Plattform-Screens: Mandanten, Benutzer, Rollen & Rechte, Audit Log
- Ziel des Meilensteins: Ein Mandant kann über „Mandant einladen" angelegt werden, bekommt automatisch DB, Benutzer, Rechte, und der erste Admin kann sich anmelden.

### Phase 2 — Produktkern und Markteintritt (8–10 Wochen)

- `module-reservation` (Reservierungen, Tischplan, Tische, Räume, Öffnungszeiten, Gäste)
- `module-widget` + `widget-embed`
- `module-notification`
- Mandanten-Screens inkl. Auswertungs-Dashboard
- **Öffentliche Website mit Selbstregistrierung** (6.5) inkl. E-Mail-Verifikation, Bot-Schutz, Aufräumauftrag
- Ziel des Meilensteins: Ein Restaurant kann sich selbst registrieren und Reservierungen annehmen — über Admin-UI und über das eingebettete Widget.

Die Phase ist gegenüber der ursprünglichen Schätzung länger, weil Website und Selbstregistrierung hinzugekommen sind (E5) und `module-provisioning` dadurch von Anfang an öffentlich auslösbar und entsprechend abgesichert sein muss.

### Phase 3 — Geschäftsbetrieb (4–5 Wochen)

- `module-billing` mit Modul-Kauf und -Aktivierung (D8), angebunden an die Preisangaben der Website
- `module-support` (D3), `module-release` (D4)
- System-Einstellungen: DB-Verbindungen, Cluster, Mandanten-Umzug, kuratiertes Setup (D7)
- System-Status mit echten Daten aus den Middleware-Manifesten (9.5.3)

### Phase 4 — Ausbau (fortlaufend)

- `module-reporting` (D10), `module-weather` (D6), `module-integration-odoo` (D5)
- Transactional-Outbox für kritische Events
- Externer Penetrationstest vor Produktivstart

### Vorgelagert vor Produktivstart

Backups eingerichtet **und wiederhergestellt getestet**, TLS über `win-acme`, Monitoring/Alerting, Runbook für Serverausfall. Bei Selbstregistrierung zusätzlich: Missbrauchs-Überwachung scharf geschaltet (Registrierungsrate, Datenbankanzahl).

---

## 11. Was ich anders machen würde als die Vorlage

Vier Punkte, an denen dieses Konzept von der Grundstruktur abweicht:

0. **Laravel statt Symfony** (Abschnitt 2a). Deine Entscheidung. Die Architektur bleibt identisch; Mandantenfähigkeit wird deutlich einfacher, die Domain-Schicht dafür weniger rein.

1. **Contracts und Core zunächst nicht trennen** (Abschnitt 3.1). Drei Repos für Code, der sich in den ersten Monaten täglich ändert, erzeugt Release-Reibung ohne Gegenwert. Die Trennung erfolgt, wenn die Contracts stabil sind — die Verzeichnisstruktur ist von Tag 1 darauf vorbereitet.

2. **Kein UI-Framework** (Abschnitt 6.1). Die Grundstruktur nennt Material UI / Mantine / Bootstrap als Optionen. Der Design-Prototyp ist visuell zu eigenständig; jedes Framework würde zur Belastung statt zur Abkürzung.

3. **Reservierung als ein Modul, nicht vier** (Abschnitt 3.2). Modulgrenzen entlang von Konsistenzgrenzen ziehen, nicht entlang von Menüpunkten.

---

## 12. Getroffene Entscheidungen

Stand 05.08.2026. Diese Festlegungen sind im gesamten Dokument bereits eingearbeitet.

| # | Frage | Entscheidung |
| --- | --- | --- |
| E0 | Framework | **Laravel** statt Symfony. Siehe Abschnitt 2a. |
| E1 | MySQL fest oder PostgreSQL zusätzlich? (D1) | **Nur MySQL.** Der Engine-Umschalter fliegt aus der Settings-Maske. |
| E2 | Metro-Look — welche Variante? (D11) | **`sharp` / `flat`**, fest verdrahtet. Keine Radius-Token, kein Umschalter. Siehe 6.1. |
| E3 | Gitea-Organisation der Modul-Repos | **`Orryn`** — wie die bestehenden Repos. |
| E4 | Composer-Registry | **Satis** auf der Gitea-Maschine. |
| E5 | Öffentliche Website mit Selbstregistrierung | **Ja, ab Phase 2.** Blade + Vite. Design fehlt vollständig und ist zu beauftragen. Siehe 6.5. |
| E6 | Zielplattform | **Windows Server**, auch produktiv. systemd/Linux entfällt; Ersatz siehe 9.2. |
| E7 | Hosting | **Eigene Hardware**, später. Bis dahin ein einzelner Server; die Cluster-Struktur (D2) bleibt aber von Anfang an im Datenmodell. |
| E8 | Domain-Schicht | **Eloquent-Modelle als Domain, DTOs an der Modulgrenze** (2a.2, Weg 3). |
| E9 | Horizon | **Nein.** Queue-Überwachung über eigene Abfragen; passt zur MySQL-Queue aus W3. |

## 12a. Verbleibende offene Punkte

| # | Frage | Empfehlung | Wann fällig |
| --- | --- | --- | --- |
| W1 | PHP-Ausführung: IIS-FastCGI-Pool oder Nginx mit mehreren `php-cgi`-Instanzen? | **IIS.** Einziger unter Windows offiziell unterstützter Weg mit Prozesspool-Verwaltung. | Phase 0, mit Lasttest |
| W2 | Webserver produktiv: IIS oder Nginx? | **IIS** — folgt aus W1, zusätzlich TLS/Rate-Limit/Rewrite fertig enthalten. `nginx-setup` bleibt für Entwicklung. | Phase 0 |
| W3 | Cache und Queue: Memurai kaufen oder MySQL-Treiber nutzen? | **MySQL-Treiber.** Spart eine Komponente; ein späterer Wechsel ist reine Konfiguration. | Phase 0 |
| E10 | Wird ein Zahlungsanbieter angebunden (Stripe/Mollie), oder werden Rechnungen manuell gestellt? | Für den Start manuell — die Selbstregistrierung startet in der Testphase, Zahlung erst danach. | Vor Phase 3 |
| E11 | Wo endet die Testphase? Automatische Sperre nach N Tagen oder manuelle Freischaltung? | Automatische Sperre mit Vorwarn-E-Mail. Betrifft `module-billing` und den Aufräumauftrag aus 6.5. | Vor Phase 3 |

