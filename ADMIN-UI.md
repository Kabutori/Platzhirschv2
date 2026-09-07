# Admin-UI — Umsetzung und API-Vertrag

Stand: 26.08.2026 · Grundlage: `docs/KONZEPT.md` (Abschnitte 6, 8, 12) und
`design-template/` (Login-Screens, Admin-Prototyp, E2: sharp/flat).

## 1. Struktur

```
design-tokens/                     → npm @platzhirsch/design-tokens
├── src/tokens.json                EINZIGE Design-Quelle (Farben, Schriften,
│                                  Größen, Abstände, Breakpoints, Font-Faces)
├── fonts/                         Work Sans / Newsreader / JetBrains Mono (woff2, lokal)
├── build.mjs                      generiert dist/ (tokens.css, tokens.ts,
│                                  tokens.d.ts, tokens.json); --check für CI
└── dist/                          generiert, nicht von Hand bearbeitbar

admin-ui/                          → npm @platzhirsch/admin-ui (Vite + React + TS)
├── index.html                     gültiger Vite-Eintrag (lang=de, #root)
├── vite.config.ts                 base /admin/, optionaler Dev-Proxy auf VITE_API_PROXY
├── packages/ui/                   → npm @platzhirsch/ui (Primitives, CSS Modules)
│   └── src/                       Button, Input, Select, Textarea, Toggle, Badge,
│                                  Table, Tile, Modal, Tabs, Field, EmptyState,
│                                  StatusDot, Spinner, Alert, LoadingScreen, ErrorState
└── src/
    ├── main.tsx                   QueryClient + Router
    ├── App.tsx                    Routen (Login, Setup, Chooser, /system/*, /customer)
    ├── styles/global.css          Reset, nur Token-Werte
    ├── components/Icon.tsx        SVG-Glyphen aus dem Design-Prototyp
    ├── hooks/useMediaQuery.ts     Breakpoints aus den Tokens (matchMedia)
    ├── api/                       client.ts, endpoints.ts, types.ts, queries.ts
    ├── module-host/               Manifest-Typ, Registry, Plattform-Manifest
    ├── shell/                     AuthGate, LoginScreen, SetupScreen, ScopeChooser,
    │                              AppShell, Sidebar, Topbar, PermGuard
    └── platform/                  Dashboard, Tenants, Modules, Users, Roles,
                                   AuditLog, Settings (+ CustomerPlaceholder)
```

## 2. Design-Regeln und ihre Durchsetzung

- **Keine literalen Farb-/Abstands-/Schriftwerte außerhalb der Token-Quelle**
  (KONZEPT 6.1.5): ESLint-Regel `no-restricted-syntax` verbietet `oklch(`/`#`/`rgb(`-Literale
  in TS/TSX; stylelint verbietet Farb-Literale, nicht-tokenisierte `font-*`/`padding`/`gap`/
  `margin`/`letter-spacing`-Werte, jede `border-radius` außer `var(--ph-radius-none|dot)`
  sowie `box-shadow`/`text-shadow` vollständig.
- **sharp/flat (E2)**: `radius.none = '0'`, keine Schatten, Abgrenzung über
  Hintergrundhelligkeit + 1px-Rahmen. Einzige Ausnahme: `radius.dot = '50%'` für die
  Status-Punkte des verbindlichen Login-Screens (dort im Prototyp fester
  `border-radius:50%`, nicht Teil des abgewählten „rounded“-Modus).
- Schriften werden lokal ausgeliefert (`fonts/*.woff2`, `@font-face` aus `tokens.json`
  generiert), keine Google-Fonts-Aufrufe (KONZEPT 6.1.7).
- Breakpoints (`breakpoint.sm|md|lg`) werden in `tokens.ts` als Rohwerte exportiert,
  weil Media Queries keine CSS Custom Properties können; alle anderen Token exportieren
  `var(--ph-…)` (Laufzeitwerte, KONZEPT 6.1.2).

## 3. Bootstrap- und Auth-Ablauf (keine Fake-Security)

1. Jede geschützte Route läuft durch `AuthGate`:
   `GET /api/v1/admin/auth/me` (Cookie-Session, `credentials: include`).
2. `401` → `GET /api/bootstrap-status`:
   - `{ "bootstrapped": false }` → `/setup` (Erst-Administrator)
   - `{ "bootstrapped": true }` → `/login`
   - Netzwerkfehler → Vollbild-Fehlerzustand mit „Erneut versuchen“
3. `SetupScreen` validiert lokal (12-Zeichen-Passwort, Bestätigung) und sendet
   `POST /api/bootstrap/first-admin`. Nach `201` **kein** automatischer Login:
   Weiterleitung zu `/login` mit Hinweisbanner. `409` (bereits gebootstrappt) → `/login`.
4. `LoginScreen`: `POST /api/v1/admin/auth/login`; Antwort
   `{ mfa_required: true }` blendet das TOTP-Feld ein („Bestätigen“), sonst Session +
   Weiterleitung. Jeder Fehler (401/422/Netz) wird als Banner mit Server-/Netzwerk-Meldung
   angezeigt — es gibt keinen clientseitigen Erfolgspfad.
5. `Logout` ruft `POST /api/v1/admin/auth/logout` und resettet den `me`-Cache.

Alle Screens laden über TanStack Query mit `isPending`-Ladezustand, `isError`-Zustand
inklusive Retry und pro Mutation Ladestatus/Fehlerbanner. Ohne laufendes Backend zeigen
die Screens ehrliche Fehlerzustände statt Mock-Daten.

## 4. API-Vertrag (für das Backend verbindlich umzusetzen)

Alle Antworten sind JSON; Authentifizierung über HttpOnly-Session-Cookie (Sanctum).
Fehler: `{ "message": string }` mit passendem HTTP-Status.

| Methode | Pfad | Request | Antwort |
| --- | --- | --- | --- |
| GET | `/api/bootstrap-status` | – | `200 { "bootstrapped": bool }` |
| POST | `/api/bootstrap/first-admin` | `{ name, email, password, password_confirmation }` | `201 { "user": AdminUser }` · `409` wenn bereits gebootstrappt · `422` Validierung |
| POST | `/api/v1/admin/auth/login` | `{ email, password, mfa_code? }` | `200 { "mfa_required": true }` oder `200 { "user": AdminUser }` · `401` |
| POST | `/api/v1/admin/auth/logout` | – | `204` |
| GET | `/api/v1/admin/auth/me` | – | `200 AdminUser` · `401` |
| GET | `/api/v1/admin/dashboard` | – | `200 { tenants_total, tenants_active, tenants_testphase, tenants_blocked, users_total, modules_total, modules_activated_total, recent_audit: AuditEntry[] }` |
| GET | `/api/v1/admin/tenants` | `?search=&status=aktiv\|testphase\|gesperrt&page=&per_page=` | `200 { data: Tenant[], page, per_page, total }` |
| POST | `/api/v1/admin/tenants` | `{ name, contact_name?, email, phone?, address? }` | `201 Tenant` (legt Mandant an + versendet Einladung) |
| PATCH | `/api/v1/admin/tenants/:id` | `{ contact_name?, email?, phone?, address? }` | `200 Tenant` |
| GET | `/api/v1/admin/modules` | – | `200 ModuleCatalogEntry[]` (`code, name, description, is_core, latest_version, price_monthly, status, tenants_using`) |
| GET | `/api/v1/admin/users` | – | `200 PlatformUser[]` (`id, name, email, role_code, status, last_login_at`) |
| POST | `/api/v1/admin/users` | `{ name, email, role_code }` | `201 PlatformUser` |
| PATCH | `/api/v1/admin/users/:id` | `{ name?, email?, role_code?, status? }` | `200 PlatformUser` |
| GET | `/api/v1/admin/roles` | – | `200 Role[]` (`id, code, name, locked, permission_codes`) |
| POST | `/api/v1/admin/roles` | `{ name, permission_codes[] }` (Code wird serverseitig als Slug vergeben) | `201 Role` |
| PATCH | `/api/v1/admin/roles/:id` | `{ name?, permission_codes[] }` | `200 Role` · `409/422` bei gesperrten Systemrollen |
| DELETE | `/api/v1/admin/roles/:id` | – | `204` · `409` bei gesperrten Systemrollen |
| GET | `/api/v1/admin/permissions/families` | – | `200 PermissionFamily[]` (`module, code, label, permissions[{code,label}]`) — Spiegel der Registry (D9) |
| GET | `/api/v1/admin/audit-log` | `?scope=plattform\|mandant&tenant_id=&page=&per_page=` | `200 { data: AuditEntry[], page, per_page, total }` |
| GET | `/api/v1/admin/db-servers` | – | `200 DbServer[]` (`id, host, port, region, cluster, status, tenant_count`) |
| POST | `/api/v1/admin/db-servers` | `{ host, port, region?, cluster? }` | `201 DbServer` |
| GET | `/api/v1/admin/settings/connection` | – | `200 { engine: "mysql", host, port, database, user, password_set, ssl }` (Passwort wird nie zurückgeliefert, KONZEPT 8) |
| PATCH | `/api/v1/admin/settings/connection` | `{ host, port, database, user, password?, ssl }` (Passwort schreiben = Ersetzen) | `200` wie GET |
| POST | `/api/v1/admin/settings/connection/test` | – | `200 { ok, latency_ms, message }` |
| POST | `/api/v1/admin/tenants/:id/migrate` | `{ target_db_server_id, confirm_backup, confirm_impact }` | `200 { operation_id, status, started_at }` · `422` wenn Quelle=Ziel oder Bestätigungen fehlen |
| GET | `/api/v1/admin/setup/operations` | – | `200 SetupOperation[]` (`id, name, description, version, requires_confirmation`) — kuratierter Katalog (D7) |
| POST | `/api/v1/admin/setup/operations/:id/run` | – | `200 { operation_id, status, started_at }` (asynchron über Worker, Audit-pflichtig) |

`AdminUser = { id, name, email, roles: [{id, code, name}], scopes: ["system"|"customer"], permissions: string[] }`.
`permissions` enthält `"*"` für System-Administratoren.

## 5. Bewusste Abweichungen vom Prototyp (begründet)

1. **SSO-Button** auf dem Login fehlt: es existiert kein SSO-Endpunkt im Vertrag; ein
   Button ohne Backend wäre Attrappe. SSO ist Backend-Nacharbeit.
2. **„Lokal speichern / Testen / Rollout“** im Rollen-Editor ist auf „Speichern /
   Verwerfen“ reduziert: der zweistufige Rollout-Lauf ist Phase 3 und braucht eigene
   Backend-Endpunkte. Das Sperren von Systemrollen (`locked`) bleibt (D9, KONZEPT 8).
3. **DB-Engine-Umschalter** entfernt (E1: nur MySQL); der Screen zeigt fest „MySQL“.
4. **Aktions-Buttons** (z. B. „Mandant einladen“) sitzen im Inhalt statt im
   Seitenkopf: die Kopfzeile gehört zur Shell, die Aktionen zum jeweiligen Screen
   (React-Komponentengrenze). Optik bleibt die des Prototyps.
5. **Favoriten in der Sidebar** (Prototyp `sidebarFavItems`) sind nicht umgesetzt —
   sie benötigen Persistenz je Benutzer in `identity_*` (KONZEPT 6.2) und folgen mit
   dem Identity-Modul.
6. **Bereichswechsler**: Der Chooser erscheint nur bei Benutzern mit beiden Scopes
   (Vertrag `scopes`); der Restaurant-Bereich zeigt einen ehrlichen
   Phase-2-Platzhalter statt Attrappen-Screens.
7. `base: '/admin/'` — die SPA wird vom Webserver unter `/admin/` ausgeliefert;
   der API-Proxy/`/api/*` bleibt unverändert.

## 6. Noch offene Backend-Arbeit (vollständig serverseitig)

- Alle Endpunkte aus Abschnitt 4 (Laravel-Controller, Form Requests, Policies,
  Scramble/OpenAPI). Rechteprüfung serverseitig; die UI filtert nur die Sichtbarkeit.
- `platform_bootstrap`-Sentinel und `FirstAdminBootstrap` (Referenzimplementierung im
  Worktree `admin-db-bootstrap`) als HTTP-Endpunkte exponieren.
- Session-/TOTP-Fluss (Login inkl. `mfa_required`-Antwort), Session-Refresh.
- Mandant-Einladung (Provisioning-Events, Einladungs-E-Mail), Umzug über
  `provisioning`-Worker mit Zwei-Faktor-Bestätigung (KONZEPT 8).
- Kuratierter Setup-Katalog (D7): benannte, versionierte Operationen, nur
  `SYSTEM_ADMIN` + Reauth, Audit-pflichtig, nie freies SQL.
- SSO, Sidebar-Favoriten, Rollen-Rollout-Workflow.
