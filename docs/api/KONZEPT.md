# Platzhirsch: Modul-API, Tokens und MCP

Stand: 17.09.2026. **Ursprünglicher Architekturentwurf.** Die inzwischen implementierte API und der stdio-MCP-Adapter sind in [API-BETRIEB.md](../API-BETRIEB.md) und [API-ABDECKUNG.md](../API-ABDECKUNG.md) dokumentiert; diese Betriebsdokumentation hat bei Abweichungen Vorrang. Insbesondere liegen API und MCP zunächst im Host-Repository, die Verträge unter `src/api.json`, und OpenAPI-Feldschemas sind noch generisch. Die heutigen `/api/v1/admin/*`- und `/api/v1/restaurant/*`-Routen sind Sitzungs-APIs der Oberfläche. Widget-Tokens sind eigene, eingeschränkte Zugangsdaten; sie werden keine allgemeinen API-Tokens.

## Entscheidung und Odoo-Vorbild

Odoo 19 bietet JSON-2 über `POST /json/2/<model>/<method>` mit Bearer-API-Schlüsseln. Dabei gelten Benutzerrechte, Datensatzregeln und Feldrechte; jeder Aufruf bildet eine eigene Transaktion. Für Automatisierung empfiehlt Odoo eigene Bot-Benutzer. Die Dokumentation beschreibt zudem Erstellung, Ablauf und Widerruf von Schlüsseln sowie dynamische Dokumentation. Quelle: [Odoo 19 External JSON-2 API](https://www.odoo.com/documentation/19.0/developer/reference/external_api.html), geprüft am 17.09.2026.

Platzhirsch übernimmt die einheitliche Anmeldung, gemeinsame Rechteprüfung und dokumentierte Moduloperationen. Die öffentliche Schnittstelle erhält explizite Ressourcen und Fachaktionen. Ein beliebiger ORM-Methodenaufruf würde interne Tabellen, Migrationen und Implementierungsdetails zum öffentlichen Vertrag machen und die unabhängige Modulpflege erschweren.

## Zielarchitektur und Zuständigkeiten

| Baustein         | Aufgabe                                                                                            | Ablage/Release                                            |
| ---------------- | -------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| contracts        | Stabile Interfaces: `ApiModule`, `ApiOperation`, `ApiPrincipal`, Policy- und Audit-Schnittstellen  | bestehendes contracts-Repo                                |
| module-host      | Registriert geprüfte Modulbeschreibungen; lehnt doppelte Operationen und inkompatible Verträge ab  | bestehendes module-host-Repo                              |
| api              | Tokenverwaltung, Bearer-Middleware, Mandantenkontext, Ratenlimits, Idempotenz, OpenAPI-Aggregation | neues eigenständiges `platzhirsch-api`-Repo vorgeschlagen |
| Fachmodule       | Eingaben/Ausgaben, Fachregeln, Rechte, Operationen und Modul-OpenAPI                               | jeweils eigenes Modul-Repo                                |
| mcp              | MCP-Protokolladapter; ruft dieselben freigegebenen Fachoperationen auf                             | neues eigenständiges `platzhirsch-mcp`-Repo vorgeschlagen |
| integration-odoo | Ausgehende Verbindung zu Odoo, Mapping und Synchronisationsstatus                                  | bestehendes integration-odoo-Repo                         |

Die vorgeschlagenen neuen Repositories sind noch nicht angelegt. API und MCP werden in der vorhandenen Modulverwaltung getrennt angezeigt, aktualisiert und deaktiviert. MCP hängt von einer exakt gepinnten API-Paketversion ab. Fachmodule hängen nur von gemeinsamen Contracts ab, nicht vom MCP-Adapter. Ein Modul ohne HTTP-Ressourcen (z. B. contracts) deklariert ausdrücklich `library`, statt eine sinnlose öffentliche Route zu erhalten.

## API-Vertrag für jedes Modul

Jedes neue oder geänderte Fachmodul liefert zusammen mit PHP/UI:

1. `api/manifest.json`: Modulcode, Paketversion, unterstützte API-Major-Version, Vertragsversion, Operationen, Scopes, Kontext (`tenant`/`platform`), Entitlement und optionale MCP-Namen.
2. `api/openapi.json`: OpenAPI 3.1, Anfrage-/Antwortschemas, Fehler, Pagination, Beispiele und Auth-Anforderungen. Kein Geheimnis und keine internen Serverpfade.
3. Implementierte Handler, die denselben Application-Service wie die Oberfläche aufrufen. Die öffentliche API umgeht weder Reservierungssperren noch Rechnungsfreigaben.
4. Vertragstests: Berechtigungen, Mandantenisolation, deaktiviertes Modul, Eingabeprüfung, Wiederholung und konkurrierende Änderungen.
5. Changelog mit Kompatibilitätsentscheidung. CI verwirft entfernte Felder/Operationen oder engere Eingaberegeln innerhalb derselben API-Major-Version.

Das mitgelieferte `module-manifest.example.json` zeigt den Zielvertrag. Es ist ausdrücklich kein Laufzeit-Register. Die Einführung einer CI-Pflicht erfolgt mit dem API-Modul und der Migration aller bestehenden Module; eine bloße Metadatei würde noch keine funktionierende API garantieren.

## URL, Authentifizierung und Antworten

Neuer, getrennter Namensraum: `/api/external/v1`. Beispiele:

| Methode/Pfad                                             | Fachaktion                                                    | Scope                |
| -------------------------------------------------------- | ------------------------------------------------------------- | -------------------- |
| GET `/modules`                                           | Verfügbare und für diesen Principal erlaubte Module/Versionen | `modules:read`       |
| GET `/reservation/reservations?date=2026-09-17&limit=50` | Tagesreservierungen                                           | `reservation:read`   |
| POST `/reservation/reservations`                         | Eine Buchung atomar anlegen                                   | `reservation:write`  |
| POST `/reservation/reservations/{id}/cancel`             | Stornierung mit Versionsprüfung                               | `reservation:cancel` |
| GET `/reporting/days?from=…&to=…`                        | Freigeschaltete Auswertung                                    | `reporting:read`     |
| GET `/billing/invoices/{id}/pdf`                         | Ausgestellten eigenen Beleg laden                             | `billing:read`       |
| GET `/support/tickets`                                   | Eigene Supportvorgänge                                        | `support:read`       |
| GET `/weather/forecast`                                  | Freigegebene Wetterdaten des Restaurants                      | `weather:read`       |

Diese URLs sind geplant. Die implementierten Sitzungs-Exportwege stehen in `../EXPORTE.md`.

`Authorization: Bearer ph_live_<id>.<secret>` wird nur über HTTPS akzeptiert. Keine Tokens in URL, Cookies, Downloadlinks oder Browser-LocalStorage. Browseroberfläche behält Sitzung und CSRF. Ein Bearer-Token wird nie als CSRF-Ersatz an heutigen Webrouten akzeptiert. CORS ist standardmäßig geschlossen; freigeschaltete Origins werden explizit konfiguriert.

Listen: `{"data":[],"meta":{"next_cursor":null,"request_id":"…"}}`, Standardlimit 50, Maximum 200, stabile Sortierung `(created_at,id)`, signierter Cursor mit Filter-/Mandantenbindung. Datenfelder sind allowlisted; keine frei wählbaren Tabellen, SQL-Fragmente oder beliebig tiefen Relationen. Beträge als Integer-Cent plus ISO-Währung; Zeitstempel RFC3339 mit Offset, Kalendertage in dokumentierter Restaurantzeitzone.

Fehler als `application/problem+json`: `type`, `title`, `status`, `code`, `request_id` und bei 422 feldbezogene `errors`. Keine Stacktraces, SQL oder Tokens. 401 ungültige Anmeldung, 403 fehlendes Recht/Entitlement, 404 unbekannt oder fremder Datensatz, 409 Versions-/Idempotenzkonflikt, 422 ungültige Eingabe, 429 mit `Retry-After`, 503 vorübergehend nicht verfügbar. Fehlgeschlagene Anmeldung verrät weder Tokeninhaber noch Restaurant.

## Token-Lebenszyklus in der Oberfläche

Unter **Verwaltung → API-Zugänge**: Anzeigen, Erstellen, Rotieren, Widerrufen, Nutzung ansehen. Restaurantadmins verwalten ausschließlich den eigenen Mandanten; Plattformzugänge benötigen einen getrennten Systemadmin-Dialog und erneute Kennwortbestätigung.

- Name, Zweck, verantwortlicher Benutzer/Service-Account, genau ein Mandant oder expliziter Plattformkontext, auswählbare Scopes, Ablaufdatum und optional CIDR-Liste.
- Secret: 32 kryptografisch zufällige Bytes, einmalige Anzeige. Datenbank speichert nur SHA-256 des hochentropischen Secrets, öffentliche Token-ID, Prefix, Besitzer, Scope-Liste, Ablauf, Widerruf, Erstellung und letzte Nutzung. Vergleich zeitkonstant. Kein Klartext in Logs, Audit oder späteren API-Antworten.
- Vorgeschlagene Policy: 30 Tage Standard, 90 Tage maximal; kürzere Vorgaben administrativ möglich. Rotation erstellt einen neuen Token mit höchstens gleichen Rechten, maximal 24 Stunden Überlappung, explizit widerrufbar.
- Effektive Rechte = Token-Scopes ∩ aktuelle Benutzer-/Service-Account-Rechte ∩ aktive Module/Entitlements ∩ Datensatz-/Feldregeln. Deaktivierte Benutzer, Mandanten oder Module wirken sofort. Keine Berechtigungs-Snapshots, die nach Rechteentzug weitergelten.
- Mandant kommt ausschließlich aus dem Token. Ein abweichendes `X-Tenant-ID` oder `tenant_id` wird abgewiesen. Plattformtokens benötigen eigene Operationen; keine globale Rechtevererbung auf Restaurantdaten.
- Startlimits: 120 Lese- und 30 Schreibaufrufe pro Minute/Token, zusätzlich Mandanten- und IP-Limits. PDF synchron 5/min; teure Aufträge zusätzlich begrenzte Parallelität. Grenzwerte später anhand Lasttests anpassen.
- Audit: Token-ID, Principal, Mandant, Operation, Objekt-ID, Ergebnis, Request-ID; keine Payloads mit Gastdaten, Rechnungsdetails oder Secrets. Lösch- und Aufbewahrungsfristen als Betreiberkonfiguration.

Datenschema im API-Modul: `api_tokens`, `api_token_scopes`, `api_idempotency`, `api_access_events`. Service-Accounts erhalten keinen interaktiven Login. Ein eigener Scope `tokens:manage` ist in v1 nicht öffentlich vorgesehen; Erstellung bleibt im bestätigten Admin-Dialog.

## Atomarität, Wiederholungen und Versionierung

Mutationen verlangen `Idempotency-Key` (UUID, höchstens 128 Zeichen). Schlüssel ist an Token, Mandant, Operation und Hash der kanonischen Anfrage gebunden. Gleiche Anfrage liefert 24 Stunden lang dasselbe Ergebnis; anderer Inhalt mit gleichem Schlüssel ergibt 409. Parallele Wiederholungen dürfen keine zweite Buchung oder Zahlung erzeugen. Status `processing` wird unter Datenbanksperre beansprucht; nach unklarem externem Ergebnis erfolgt Abgleich beim Anbieter, kein blindes Wiederholen.

Reservierungsanlage einschließlich Tischen ist eine Fachtransaktion. Änderungsoperationen verlangen `version`/`If-Match`; veraltete Änderungen ergeben 409/412 nach festgelegtem Endpunktvertrag. SMTP und Zahlungsanbieter laufen über Outbox/Queue nach erfolgreichem Commit, mit ihren eigenen Idempotenzschlüsseln. API-Antwort `202` enthält eine mandantengebundene Auftrags-ID; „angenommen“ bedeutet nicht „E-Mail zugestellt“.

Paketversion (`0.1.1`) beschreibt das unabhängig veröffentlichte Modul; API-Major (`v1`) den Kundenvertrag; `contract_version` dessen additive Entwicklung. Ein Paketupdate darf API v1 erweitern, aber nicht brechen. API v2 läuft bei Bedarf parallel; Deprecation- und Sunset-Header plus dokumentierte Übergangsfrist, vorgeschlagen mindestens sechs Monate. Importprüfungen testen die gesamte exakt gepinnte Modulzusammenstellung vor Aktivierung.

## MCP-Modul

MCP exportiert nur Operationen, die das jeweilige Fachmodul dafür freigegeben hat. `tools/list` liefert ausschließlich für den angemeldeten Principal nutzbare Werkzeuge. `tools/call` prüft Rechte, Entitlement und Mandant erneut; ein alter Werkzeugkatalog verleiht keine Rechte.

Beispiele: `reservation_list`, `reservation_create`, `reservation_cancel`, `reporting_daily`, `billing_invoice_get`, `support_ticket_create`. Eingabe-/Ausgabeschemas kommen aus dem Modulvertrag. Werkzeugnamen sind stabil und kollisionsfrei. Lesefunktionen erhalten `readOnlyHint`; destruktive Funktionen `destructiveHint`. Diese Hinweise ersetzen keine serverseitige Autorisierung. Exporte liefern geschützte Ressourcen oder kurzlebige, mandantengebundene Downloadtickets, keine Tokens im Link und keine frei wählbaren URLs.

Schreibaktionen: strukturierte Vorschau mit Gast, Datum, Tischen oder Kosten; Bestätigung erzeugt ein einmaliges, kurzlebiges, an Principal, Operation und Payload-Hash gebundenes Ticket. Der Server prüft es beim Commit. Besonders sensible Aktionen (Rechnung verbindlich ausstellen, Zahlung auslösen, Modulupdates, Serververwaltung, Tokenverwaltung) bleiben in der ersten MCP-Version gesperrt. Inhalte von Webseiten, Tickets und Gastnotizen sind Daten und können keine Rechte/Toolfreigaben verändern.

Zwei Transportvarianten werden getrennt behandelt:

- **Lokales stdio**: API-Token über Prozessumgebung/Secret Store, eigener beschränkter Service-Account; nichts in Kommandozeilenparametern oder Beispielkonfigurationen einchecken.
- **Remote Streamable HTTP**: OAuth 2.1 mit PKCE, Protected Resource Metadata, expliziten Scopes, Resource-/Audience-Prüfung und exakter Redirect-URI-Validierung. API-PATs sind kein vollständiger Ersatz für diese MCP-Anmeldung. Der MCP-Server akzeptiert nur für ihn ausgestellte Access-Tokens. Er reicht fremde Tokens nicht ungeprüft an andere Dienste durch.

Diese Unterscheidung folgt der [MCP-Autorisierungsspezifikation](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization). Protokollversion und SDK werden bei Implementierungsbeginn fest gepinnt und per Interoperabilitätstest geprüft.

## Umfang je bestehendem Modul

| Modul                            | Öffentliche Oberfläche im Zielbild                                           | MCP zunächst                    |
| -------------------------------- | ---------------------------------------------------------------------------- | ------------------------------- |
| reservation                      | Reservierungen, Verfügbarkeit, Räume/Tische, Warteliste; Rechte je Aktion    | Lesen, bestätigte Anlage/Storno |
| reporting                        | Tageswerte, gespeicherte Auswertungen, Exporte                               | Lesen                           |
| billing                          | Eigene Belege, Status, Produkte; administrative Abrechnung getrennt          | Eigene Belege lesen             |
| weather                          | Restaurantvorhersage und Automatikstatus                                     | Lesen                           |
| support                          | Eigene Tickets und Antworten                                                 | Lesen, bestätigtes Ticket       |
| customer                         | Freigegebene Kundenstammdaten, Feldminimierung                               | Lesen, später bestätigte Pflege |
| identity                         | Service-Account-Zuordnung über Admin-Funktionen; keine Passwort-/MFA-Secrets | gesperrt                        |
| provisioning                     | Auftragsstatus; Serveraktionen nur Plattformrechte                           | gesperrt                        |
| notification                     | Versandstatus und freigegebene Vorlagen; keine SMTP-Secrets                  | Status lesen                    |
| audit                            | gefilterte Ereignisse mit eigener Audit-Berechtigung                         | zunächst gesperrt               |
| release                          | Module/Kompatibilitäten lesen; Updates nur Admin-Workflow                    | Lesen                           |
| widget                           | bestehende eng begrenzte Widget-API separat erhalten                         | kein zusätzlicher MCP-Zugang    |
| integration-odoo                 | Konfiguration ohne Secrets, Sync-Aufträge und Status                         | Status lesen                    |
| contracts/module-host/ui-runtime | Bibliotheks-/Registrierungsvertrag, keine fachlichen Endpunkte               | keine Tools                     |

Odoo-Anbindung separat halten: externe Schlüssel verschlüsselt im Secret Store, versionsabhängiger Client, explizite Feldzuordnung (Mandant ↔ Firma, Kunde ↔ Partner, Beleg ↔ Rechnung), lokale/externe ID-Zuordnung, Outbox und Konfliktprotokoll. Welche Belegseite führend ist, muss vor einer schreibenden Synchronisation festgelegt werden. Eine API-Anbindung darf nicht zwei unabhängige Rechnungsnummerierungen vermischen.

## Umsetzung und Abnahme

1. Contracts und API-Modul mit Tokenverwaltung, Mandantenbindung, Widerruf und Scope-Prüfung; Migration und Admin-UI.
2. Reservation/Reporting/Billing als erste echte Modulverträge, OpenAPI-Generierung und curl-Beispiele mit Testtokens. Alle bestehenden Fachmodule anschließend auf denselben Standard bringen.
3. Contract-CI verbindlich: Jede deklarierte Operation hat Route/Handler, Auth, Schema und negative Tests. Installation eines unvollständigen Fachmoduls scheitert verständlich; Bibliotheken sind explizit ausgenommen.
4. MCP-stdio zunächst nur lesend, dann bestätigte Reservierungsaktionen; danach Remote-OAuth und Streamable HTTP.
5. Last-/Isolationstests und unabhängige Releases von API/MCP samt exakt gepinnter Hauptanwendung.

Abnahmekriterien: Fremdmandant unzugänglich; Widerruf ohne Neustart wirksam; Rechteentzug trotz altem Token wirksam; doppelte parallele Anlage erzeugt einen Datensatz; fremder Cursor/Bestätigungsticket abgewiesen; deaktiviertes Modul verschwindet aus Discovery und bleibt auch direkt gesperrt; API/MCP liefern gleiche fachliche Ergebnisse wie die Oberfläche; keine Secrets in Fehlern, Downloads, Logs oder Dokumentation.
