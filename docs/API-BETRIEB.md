# Modul-API und MCP

Stand: 17. September 2026. Implementierte externe API v1, Paketstand 0.1.2.

## Zugang und Rechte

Systemadministratoren verwalten eigene Zugänge unter Infrastruktur → API & MCP; Restaurantadministratoren im Profil. Ein Token gehört genau diesem Konto und dessen Mandanten. Erstellung, Widerruf, Modulkonfiguration und Freigaben benötigen die angemeldete Sitzung, CSRF-Schutz, das aktuelle Kennwort und eine ausdrückliche Bestätigung. Tokens werden einmal angezeigt, nur als SHA-256-Hash gespeichert und laufen spätestens nach 90 Tagen ab. Für Rotation einen neuen Zugang erstellen, Verbraucher umstellen, alten Zugang widerrufen.

Pro Modul gibt es getrennte `:read`- und `:write`-Scopes. Es gibt keine Wildcards. Aktuelle Kontorechte, aktiver Mandant und gebuchte Wetter-/Reportingmodule werden bei jedem Aufruf erneut geprüft. Plattformkonten können keinen Restaurantkontext über einen Header übernehmen. Restauranttokens können nicht auf einen anderen Mandanten umgeschaltet werden. Rollenentzug oder Kontosperre invalidieren den Zugang. Zusätzlich sind IP-/CIDR-Beschränkungen möglich.

API und MCP lassen sich global und je Modul abschalten. Diese Schalter sperren Maschinenzugriffe; bestehende Portalrechte bleiben maßgeblich. API- und MCP-Pakete werden unabhängig vom Anwendungskern versioniert. Sie liegen zunächst zusammen mit dem Host-Paket im Repository `platzhirsch-module-host` (Unterordner `api`, `mcp`, `mcp-ui`); diese Pakete haben derzeit einen gemeinsamen Repository-Release.

## Aufrufe

Alle Maschinenaufrufe benötigen HTTPS und `Authorization: Bearer <token>`. Browser-Origin-Header werden abgewiesen; Sitzungscookies ersetzen keinen Token. Der explizite Entwicklungsschalter `PLATZHIRSCH_API_ALLOW_LOOPBACK_HTTP=true` erlaubt ausschließlich lokale Loopback-Aufrufe. Produktiv deaktiviert lassen.

- `GET /api/external/v1/catalog`: aktuell erlaubte Operationen dieses Tokens.
- `GET /api/external/v1/openapi.json`: OpenAPI 3.1 mit Methoden, Pfadparametern, Scopes und Freigabeanforderungen.
- Fachoperation: `/api/external/v1/{module}/{bisheriger Pfad nach api/v1/}`.
- Beispiel: `GET /api/external/v1/reservation/restaurant/reservations`.
- Beispiel: `POST /api/external/v1/support/support` mit JSON-Objekt und UUID in `Idempotency-Key`.

Die Allowlist verweist auf die bestehenden Controller und führt deren Rollen-, Mandanten-, Fach- und Eingabeprüfungen weiter aus. Kein freier ORM-Zugriff, keine frei wählbaren Controller oder SQL-Abfragen. Neue Fachrouten müssen in einem Modulvertrag klassifiziert sein; der CI-Vertragstest verhindert unbemerkte Lücken.

Die OpenAPI beschreibt derzeit die Transportverträge; Feldschemas der Fachobjekte sind generische Objekte. Die verbindlichen Detailvalidierungen bleiben in den jeweiligen Controllern. Daraus noch keinen vollständig typisierten Client generieren.

## Schreibaktionen und Freigabe

Jede Schreibaktion benötigt JSON und einen UUID-Idempotenzschlüssel. Derselbe Schlüssel mit derselben Anfrage liefert innerhalb von 24 Stunden das gespeicherte Ergebnis, ohne die Fachaktion erneut auszuführen. Eine geänderte Anfrage erhält 409; nach Ablauf erhält der bekannte Schlüssel 410. Bei laufendem oder unklarem Ergebnis wird 409 zurückgegeben: Ergebnis im Portal prüfen, nicht automatisch mit einem neuen Schlüssel erneut senden. Antworten werden verschlüsselt gespeichert. Große/gestreamte oder fehlgeschlagene Ergebnisse gelten als unklar.

Alle MCP-Schreibaktionen sowie kritische API-Schreibaktionen an Identität, Kunden, Abrechnung, Provisionierung, Releases und Plattform benötigen zusätzlich eine einmalige Freigabe:

1. `POST /api/external/v1/confirmations` mit `operation`, `parameters`, `query`, `body` (jeweils exakt wie später verwendet, Pfadparameter als Strings).
2. Tokeninhaber prüft die Vorschau im Portal und bestätigt mit Kennwort. Geheimnisfelder sind ausgeblendet; der Hash bindet trotzdem den vollständigen Inhalt.
3. Innerhalb von fünf Minuten dieselbe Fachanfrage mit `X-Api-Confirmation: <id>` und `Idempotency-Key` senden.

Freigaben gelten nur für diesen Token und genau diese Anfrage. Sie werden atomar verbraucht. Tokenbesitz allein erlaubt keine Portalbestätigung. Bestehende zusätzliche Fachbestätigungen, etwa TOTP beim Datenbankumzug, bleiben erforderlich.

## MCP starten

Node.js 22 oder neuer. Im Windows-Paket liegt der Adapter unter `mcp/server.mjs`, im Repository unter `admin-ui/packages/mcp/src/server.mjs`. Der MCP-Client startet ihn als lokalen stdio-Prozess:

```json
{
  "mcpServers": {
    "platzhirsch": {
      "command": "node",
      "args": ["C:/Platzhirsch-Paket/mcp/server.mjs"],
      "env": {
        "PLATZHIRSCH_API_URL": "https://platzhirsch.example",
        "PLATZHIRSCH_API_TOKEN": "<MCP-Token aus dem Portal>"
      }
    }
  }
}
```

Geheimnisse über den Secret Store des Clients bereitstellen; nicht ins Repository einchecken. Der Adapter verlangt die Token-Zielgruppe `mcp`, prüft vor jedem Toolaufruf erneut den Katalog und folgt keinen HTTP-Weiterleitungen. `platzhirsch_prepare` fordert die Portalbestätigung an; es führt die Fachaktion nicht aus. Downloads werden als eingebettete Ressourcen zurückgegeben (maximal 10 MB). Der Adapter implementiert stdio/MCP 2025-11-25, keinen öffentlich erreichbaren MCP-HTTP-/OAuth-Server.

## Betrieb und Grenzen

Limit: 300 Aufrufe je IP/Minute und 120 je Token/Minute; Freigabeanforderungen zusätzlich 10/Minute. Maximal 1 MB Anfrage. Antworten tragen `no-store`, Request-ID und sichere Fehlertexte; 401 enthält die Bearer-Challenge, 429 Retry-After. Das API-Zugriffsprotokoll speichert Operation, Token-ID, Status und Request-ID, keine Tokengeheimnisse oder Nutzlasten. Tokenverwaltung und Freigaben werden zusätzlich im bestehenden Audit protokolliert.

Tabellen `api_requests`, `api_confirmations` und `api_access_events` in die Aufbewahrungsplanung aufnehmen. Idempotenzschlüssel nicht vorzeitig löschen: Löschung kann erneute Ausführung erlauben. Der Anwendungsschlüssel wird zum Entschlüsseln gespeicherter Antworten benötigt.

Login, Registrierung, MFA und Tokenverwaltung bleiben bewusst interaktive Sitzungsabläufe. Öffentliche Widget-Endpunkte, Stripe-Webhooks und Paketdownloads behalten ihre eigene Authentifizierung. Odoo bleibt der vereinbarte Platzhalter: nur Statusabfrage, keine behauptete Synchronisierung.

Referenz: [MCP-Transport](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports), [MCP-Tools](https://modelcontextprotocol.io/specification/2025-11-25/server/tools).
