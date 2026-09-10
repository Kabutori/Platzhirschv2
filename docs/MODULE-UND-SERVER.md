# Module und zusätzliche Datenbankserver

Stand: Entwicklung für den Branch `codex/windows-application`. Maßgeblich sind die erfolgreichen Prüfungen des jeweiligen Release-Commits; diese Anleitung allein bestätigt keine Installation.

## Installation und Testrestaurant

Das gebaute Windows-ZIP vollständig entpacken und `Install.bat` als Administrator starten. Die BAT setzt `ExecutionPolicy Bypass` ausschließlich für den gestarteten PowerShell-Prozess. Eine zusätzliche Datei oder eine dauerhafte Änderung der Windows-Ausführungsrichtlinie ist nicht erforderlich.

Unter **Administration → Mandanten → Testrestaurant einrichten** Restaurantname, Besitzername, Login-E-Mail und ein eigenes Passwort eingeben. Der Hintergrundauftrag erstellt eine getrennte Datenbank, drei Tische und sieben Öffnungstage. Erst bei Status **Aktiv** über `/restaurant/login` anmelden. `/administration/login` bleibt der getrennte Plattformzugang.

Bestehende Installationen anderer Versionen werden weiterhin nicht automatisch aktualisiert. Vorhandene Installationen und Daten niemals löschen, um diese Versionsprüfung zu umgehen.

## Paketstruktur

| Bereich | PHP-Paket | npm-Paket |
|---|---|---|
| Anmeldung, Konten und Rollen | `platzhirsch/identity` | `@platzhirsch/identity-ui` |
| Mandanten und Restaurantprofil | `platzhirsch/customer` | `@platzhirsch/customer-ui` |
| Räume, Tische, Öffnung und Buchungen | `platzhirsch/reservation` | `@platzhirsch/reservation-ui` |
| Einbettbares Buchungswidget | `platzhirsch/widget` | `@platzhirsch/widget-ui` |
| Support | `platzhirsch/support` | `@platzhirsch/support-ui` |
| Angebote, Bestellungen und Freigaben | `platzhirsch/billing` | `@platzhirsch/billing-ui` |
| Erweiterte Auswertungen | `platzhirsch/reporting` | `@platzhirsch/reporting-ui` |
| Datenbankserver und SQL-Zugriff | `platzhirsch/provisioning` | `@platzhirsch/provisioning-ui` |
| Modulregistrierung | `platzhirsch/module-host` | `@platzhirsch/module-host` |
| Gemeinsame Schnittstellen und Gestaltung | `platzhirsch/contracts` | `@platzhirsch/ui-runtime`, `@platzhirsch/design-tokens` |

Composer verwendet feste Versionen und lokale Path-Repositories ohne Symlinks; npm verwendet Workspaces. Das Windows-Release enthält die PHP-Pakete im `vendor`-Verzeichnis und die fertig gebauten UI-Dateien. Zielserver benötigen weder Composer noch Node.js.

Fachcontroller und ihre Oberflächen liegen in diesen Paketen. Die Anwendung enthält weiterhin die Integrationsadapter, Queue-Orchestrierung, Middleware und Installationskonfiguration. Bestehende Tabellen- und Migrationsnamen bleiben aus Kompatibilitätsgründen erhalten; nicht alle historischen Tabellen besitzen bereits den vorgesehenen Modulpräfix. Eigene Git-Repositories und die im Konzept vorgesehene private Satis-/npm-Registry sind noch nicht eingerichtet.

Neue Rechte werden vom jeweiligen Modul registriert. Rechte für Restaurants erscheinen nicht als zuweisbare Plattformrechte. Der Support ist in beiden Bereichen verfügbar, mit getrennten Datenzugriffsregeln. Die UI blendet nicht verfügbare Funktionen aus; die API prüft die Berechtigung zusätzlich.

## Modulangebot, Bestellung und Aktivierung

1. Der Systemadministrator legt unter **Angebote & Bestellungen** den Monatspreis fest und schaltet das Angebot frei. Standardmäßig sind Angebote ohne Preis deaktiviert.
2. Die Restaurantleitung bestellt im **Modul-Shop**. Die API prüft den angezeigten Preis nochmals; Preisänderungen führen zur Ablehnung statt zu einer unbemerkten Mehrbelastung. Ein noch offener Auftrag verhindert einen zweiten offenen Auftrag für dasselbe Modul.
3. Nach tatsächlichem externem Zahlungseingang bestätigt der Systemadministrator den vollständigen Betrag mit einer eindeutigen Zahlungsreferenz. Wiederholte Bestätigungen verlängern den Zeitraum nicht erneut.
4. Die Restaurantleitung aktiviert das freigegebene Modul. Der Provisionierungsworker erhält vorübergehend Migrationsrechte, führt ausschließlich die mitgelieferten Mandantenmigrationen aus und entzieht die zusätzlichen Rechte wieder.
5. Erst nach erfolgreicher Migration wird das Modul aktiv. Deaktivierung und Ablauf des Nutzungszeitraums sperren den Zugriff; gespeicherte Daten bleiben erhalten.

Reporting ist der erste kostenpflichtig freischaltbare Zusatz: Tageszahlen, gespeicherte Zeiträume und getrennte Lese-/Schreibrechte. Der Bestellweg ist eine **manuelle externe Zahlungsbestätigung**, kein angebundener Zahlungsdienst. Es gibt noch keine automatische Abbuchung, Rechnungserstellung oder automatische Aboverlängerung.

## Server autorisieren und zuordnen

Die Administration speichert Prüfzugänge für einen Datenbankserver. Diese allein berechtigen nicht zur Provisionierung. Als Zweck **Mandantenserver** oder **Primäre Datenbank** wählen. Externe Verbindungen verlangen TLS mit Zertifikatsprüfung; `DB_SERVER_CA_FILE` muss auf die vertrauenswürdige CA-Datei auf dem Anwendungsserver zeigen. Unverschlüsselte Verbindungen sind ausschließlich zu Loopback zugelassen.

Ein Windows-Administrator autorisiert einen erfassten Server lokal, beispielsweise bei Installation unter `C:\Platzhirsch`:

```powershell
& C:\Platzhirsch\runtime\php\php.exe C:\Platzhirsch\app\artisan server:authorize 2
```

Abgefragt werden Provisionierungsbenutzer, dessen Kennwort und der konkrete Host/IP des Anwendungsservers aus Sicht von MySQL. Keine `%`-Freigabe verwenden. Der Benutzer benötigt Benutzeranlage und delegierbare Rechte auf den verwalteten `ph_t_…`-Datenbanken, einschließlich Tabellenlesen, Schemaanlage, Migration und Tabellen-Lesesperren. Die Windows-Installation richtet diese Rechte für ihren lokalen Worker bereits ein. Auf zusätzlichen Servern muss der Datenbankadministrator sie gezielt bereitstellen.

Die privilegierten Zugangsdaten werden ausschließlich in einer administrativ geschützten Datei auf dem Anwendungsserver gespeichert. Die Weboberfläche kann diese Worker-Zugangsdaten nicht lesen. Autorisierte Server sind gegen nachträgliche Änderungen der Verbindung gesperrt. Anschließend kann bei **Mandant anlegen** ein freigegebener Zielserver gewählt werden.

Unter **SQL-Zugangsdaten** sieht der Systemadministrator die tatsächlich zugeordnete Verbindung und kann nach erneuter Kennwortprüfung das eingeschränkte Anwendungspasswort kurzzeitig anzeigen. Das ist kein MySQL-Root-Passwort.

## Mandanten umziehen

Unter **Serverzuordnung & Umzüge** Restaurant und freigegebenen Zielserver auswählen. Ein frischer Zwei-Faktor-Code, das Administratorkennwort und Bestätigungen für eine geprüfte Sicherung sowie die abgestimmte Ausfallzeit sind erforderlich. Externe direkte SQL-Schreibzugriffe vorab stoppen.

Der Auftrag sperrt den Restaurantzugang, wartet bestehende Anwendungszugriffe ab und kopiert in eine neue Datenbank mit neuem Benutzer. Tabellen werden anhand ihrer Primärschlüssel übertragen; Anzahl und SHA-256-Prüfung der geordneten Inhalte werden verglichen. Die Quelldaten bleiben bis zur erfolgreichen Umschaltung schreibgesperrt und werden danach nicht gelöscht. Fremde Views, Trigger, tabellenübergreifende Datenbankreferenzen oder Tabellen ohne Primärschlüssel werden nicht übernommen; der Auftrag bricht mit erhaltener Quelle ab.

Nach Erfolg verwenden alle neuen Zugriffe die neue Zuordnung. Bei einem Fehler vor der Umschaltung bleibt die Quelle maßgeblich. Unvollständige Zielkopien und alte Quellen werden aus Gründen des Datenerhalts nicht automatisch gelöscht; ihre Bereinigung ist eine gesonderte Administratoraufgabe. Eine automatische Rückschaltung nach neuen Schreibzugriffen am Ziel ist nicht vorgesehen.

**Sicherung bei mehreren Servern:** Der vorhandene lokale Windows-Snapshot erfasst zusätzliche MySQL-Instanzen nicht. Sobald ein weiterer Server autorisiert ist, verweigert das Skript lokale Komplettsicherungen und Wiederherstellungen. Für diese Topologie ist eine koordinierte Sicherungsstrategie aller Server erforderlich; sie ist noch nicht automatisiert.

## Unterbrochene Modulaktivierung

Bei normalem Migrationsfehler entzieht der Worker die DDL-Rechte und sperrt das betroffene Modul. Ein hart abgebrochener Worker kann das Restaurant bis zur Prüfung im Wartungszustand lassen. Nach Prüfung teilweise ausgeführter Migrationen kann ein lokaler Administrator einen fehlgeschlagenen Auftrag reparieren:

```powershell
& C:\Platzhirsch\runtime\php\php.exe C:\Platzhirsch\app\artisan module:repair 42 --acknowledge-partial-migrations
```

Der Befehl wartet auf die Mandantensperre, prüft Auftrag und Zuordnung, entzieht vorhandene Migrationsrechte und lässt das Modul gesperrt. Er löscht keine Tabellen und setzt keine Daten zurück. Erst eine erneute erfolgreiche Aktivierung gibt das Modul frei.

## Grenzen der Abnahme

Die Windows-Prüfung verwendet zwei unabhängige lokale MySQL-Instanzen auf Wegwerfmaschinen. Ein Betrieb über ein externes Netzwerk mit eigener CA, große Datenmengen, ein echter Windows-Neustart, versionsübergreifende Updates und koordinierte Wiederherstellung verteilter Datenbanken benötigen weitere Abnahme. Die Vorschau ist keine Vollabnahme sämtlicher Zusatzfunktionen des Gesamtkonzepts.
