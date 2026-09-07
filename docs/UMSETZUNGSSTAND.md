# Platzhirsch – tatsächlicher Umsetzungsstand

Stand: 2026-09-07. Version: 0.1.0 (Entwicklungsstand, keine Produktionsfreigabe).

Dieses Dokument beschreibt ausschließlich den Quellcode dieses Repositories. Die älteren Dokumente KONZEPT.md, ADMIN-UI.md und BACKEND-BASIS.md beschreiben teilweise andere, hier nicht vorhandene Worktrees. Deren Testberichte gelten nicht für diesen Code.

## Implementiert

- Laravel-Anwendung mit zentraler Plattform-Datenbank und eigenem MySQL-Schema sowie eigenem Datenbankbenutzer je Restaurant.
- Erstadministrator mit Einmal-Schlüssel, gesperrtem Sentinel und Datenbanktransaktion; kein automatischer Login nach Setup.
- Session-Anmeldung, Logout, Kontosperren, CSRF-Schutz, Login-Limitierung, Passwort-Reset per konfiguriertem SMTP, TOTP-Einrichtung und Replay-Schutz.
- Plattformübersicht, Mandantenanlage und asynchrone Provisionierung, Sperren/Freigeben, Benutzeranlage und Einladungslinks, Audit-Protokoll, Betriebsdaten.
- Drei geschützte Grundrollen sowie eigene Mitarbeiterrollen pro Restaurant mit getrennten Lese-, Schreib-, Storno-, Export-, Konfigurations-, Widget- und Supportrechten. Teammitglieder können bearbeitet, gesperrt und Rollen zugewiesen werden. Fremde Rollen, Selbstentzug von Administratorrechten und veraltete Rollenänderungen werden abgewiesen.
- Restaurantprofil, Räume, Tische, wöchentliche Öffnungszeiten und Sondertage.
- Reservierungen erstellen, bearbeiten und stornieren; Kapazitätsprüfung, Öffnungszeitenprüfung, zeitzonenbezogene Eingabe, Speicherung in UTC, transaktionale Tischsperren und Prüfung auf zeitliche Überschneidungen.
- Reservierungsübersicht, Tageskennzahlen, Belegungsansicht je Tisch, CSV-Export mit Schutz gegen Tabellenformeln.
- Buchungslinks mit Ablaufdatum und Widerruf, öffentliche Buchungsseite und öffentliche eingeschränkte Buchungs-API. Shadow-DOM-Embed mit Verfügbarkeitsabfrage, konfigurierbarer Buchungsdauer und Akzentfarbe; keine Gästedaten in der öffentlichen Verfügbarkeitsantwort.
- Support-Tickets, Nachrichten und interne Notizen mit Mandantengrenzen.
- React/TypeScript-Oberfläche in Anlehnung an die gelieferten Prototypen, dunkles sharp/flat-Design, lokal ausgelieferte Schriften, Formularvalidierung, Lade-/Fehler-/Leerzustände.
- Windows-Installer-Quellcode: BAT-Einstieg, PowerShell 5.1, IIS/FastCGI, PHP NTS, eigene MySQL-Instanz, lokale Erstinstallation, gesonderte HTTPS-Freigabe und Backup-Skript.
- Windows-Release-Workflow und CI für Frontend, PHP-Funktionstests, Dependency-Prüfung und PowerShell-Syntax.

## Absichtlich noch nicht als fertig bezeichnet

Die vollständige Anwendung aus allen Konzeptphasen ist mit diesem ersten Stand **nicht** umgesetzt. Insbesondere fehlen:

- Dynamische Berechtigungsfamilien aus externen Modulen, organisationsübergreifender Rollen-Rollout, SSO und Sidebar-Favoriten. Restaurant-Mitarbeiterrollen sind implementiert.
- Produktive Modulregistrierung, Modul-Marktplatz, Kauf/Aktivierung/Versionsmanagement und unabhängige Modul-Repositories.
- Abos, rechtlich geprüfte Rechnungen, Zahlungsanbieter, automatische Abrechnung und Testphasenpolitik.
- Mehrere Datenbankserver, Cluster-Resolver, Umzug/Massenmigration von Mandanten.
- Weitere Widget-Designvorlagen und automatische Buchungs-E-Mails/SMS.
- Öffentliche Marketing-Website, öffentliche Selbstregistrierung, E-Mail-Verifikation, Rechtstexte und deren Gestaltung.
- Odoo-, Wetter- und weitergehende Reporting-Integrationen; PDF/XLSX/SQL/XML-Exporte.
- Versionsübergreifender Update-/Rollback-Automat, Wiederherstellung auf neuer Maschine, signierter eigener Installer, Zertifikatserneuerung, externe Backup-Ablage und Monitoring-Alarmierung. Vollständige Offline-Snapshots und Wiederherstellung derselben Installation sind implementiert; der zusätzliche Windows-Abnahmelauf steht unten.
- Vollständige visuelle 1:1-Abnahme, Bildschirmleser-/Tastatur-Abnahme, durchgängige browserbasierte Admin-E2E-Suite und mobile Detailabnahme; drei Widget- und zwei Rollen-Browsertests sind vorhanden.

## Technische Entscheidungen und Abweichungen

1. Laravel 13 (PHP ab 8.3) statt der älteren Laravel-12-Referenz. Das Windows-Paket verwendet PHP 8.5.10 NTS und MySQL 8.4.11 LTS; heruntergeladene Versionen müssen im Release-Build real bestätigt werden.
2. IIS ist der einzige Installer-Webserver. Kein Docker, WSL, Nginx, Redis, Git, Composer oder Node-Build auf dem Zielserver erforderlich.
3. Cache, Sessions und Queue nutzen die zentrale MySQL-Datenbank. Anwendungsschlüssel bleiben bei Wiederaufnahme unverändert.
4. Browser-API nutzt Laravel-Web-Middleware mit Session und CSRF, nicht zusätzliche Sanctum-Tokens. Öffentliche API ist separat und ohne Admin-Session.
5. Mandantenauflösung ist zunächst eine kleine dedizierte Verbindungskomponente, nicht stancl/tenancy. Es gibt genau einen physischen DB-Server. Jede Anfrage löst die erlaubte Tenant-ID serverseitig auf; gewöhnliche Benutzer können den Kontext nicht per Header ändern.
6. Provisionierungszugänge liegen in einer von IIS nicht lesbaren Datei. Der Windows-Hintergrundprozess läuft als LocalService und liest diese Datei. Separate Windows-Service-Konten je Worker sind vor einer gehärteten Produktion weiter zu prüfen.
7. Hintergrundprozesse werden über geplante Aufgaben gestartet. Der PowerShell-Elternprozess begrenzt die Laufzeit eines einzelnen Jobs, da PCNTL unter Windows nicht verfügbar ist. Kein WinSW-Binary nötig.
8. UI-Navigation verwendet komponenteninternen Zustand; Hash-Routen sind für Passwort-Reset und Buchungsseiten vorgesehen. Noch kein React-Router-Manifest-System.
9. Gemeinsame CSS-Variablen statt des geplanten veröffentlichten JSON-Design-Token-Pakets. Das spätere Modul-Paketsystem bleibt ein Folgearbeitspaket.
10. Öffnungszeiten sind Zeitfenster innerhalb desselben Kalendertags. Buchungen über Mitternacht, Tischkombinationen und Wartelisten sind noch nicht unterstützt.
11. Plattformlisten zeigen begrenzte erste Seiten; vollständige Paginierungsbedienung fehlt noch.

## Neue Erweiterungen und laufende Abnahme

- Rollenverwaltung: [Prüflauf 34145834334](https://github.com/Kabutori/Platzhirschv2/actions/runs/34145834334) erfolgreich, einschließlich sieben zusätzlicher PHP-Rechtetests und zwei neuer Rollen-Browsertests.
- Offline-Snapshot und Wiederherstellung: gemeinsamer Sicherungszeitpunkt aller Datenbanken inklusive MySQL-Konten, Anwendung, Schlüssel und NTFS-Rechte. Prüfsummen vor dem Restore; zusätzliche Sicherung des aktuellen Stands; bei unvollständiger Rückkopie kein Neustart. Beschränkt auf dieselbe Maschine, Version und Installationskonfiguration.
- Der erweiterte Windows-Test erstellt eine Sicherung, legt danach eine weitere Buchung an, prüft die Ablehnung einer beschädigten Sicherung und stellt den ursprünglichen Datenstand samt Schlüsseln wieder her. Diese zusätzliche Windows-Abnahme ist zum Zeitpunkt dieses Dokuments noch nicht abgeschlossen. Vorschau 13 enthält diese Erweiterungen nicht.

## Verifikationsgrenze

- Der [Anwendungs-Prüflauf 34144092198](https://github.com/Kabutori/Platzhirschv2/actions/runs/34144092198) für Commit `e262b1cb4112f11d11f49330aaf248f585ebe27e` ist vollständig grün: 24 PHP-Tests / 100 Assertions einschließlich tatsächlichem HTTP-Einstiegspunkt und begrenzter Fehlerdiagnose, drei Chromium-Widget-Tests, Frontend-Build, Dependency-Prüfung und PowerShell-5.1-Syntax. PHP und PowerShell sind lokal nicht verfügbar; diese Laufzeitprüfungen erfolgen über GitHub Actions.
- Widget-Browsertests verwenden API-Mocks. Der Windows-Integrationstest arbeitet zusätzlich mit der tatsächlichen IIS-/MySQL-Installation und der öffentlichen Widget-API.
- Der [Windows-Prüflauf 34144092188](https://github.com/Kabutori/Platzhirschv2/actions/runs/34144092188) für denselben Code ist vollständig erfolgreich: Paketbau, Installation und Integration auf **Windows Server 2022 und 2025**, anschließend öffentliche Veröffentlichung.
- Geprüftes Paket: [Windows-Vorschau 13](https://github.com/Kabutori/Platzhirschv2/releases/tag/windows-preview-13-1), Asset `Platzhirsch-0.1.0-preview.13-windows-x64.zip`. SHA-256: `0d9189f46b7c57edc6457e83baf79a1df30679199560a47b64c5da802881fba4`. Diese Vorschau enthält die Korrekturen für IIS-Konfigurationssektionen, HTTP-Einstiegspunkt, PHP-8.5-OPcache, Admin-Startdatei und die maskierten GRANT-/REVOKE-Datenbanknamen. IIS darf die ausführbaren Bootstrap-Caches nur lesen.
- Ältere Actions-Artefakte enthalten Installations- beziehungsweise Provisionierungsfehler. Für einen Installationstest die oben verlinkte veröffentlichte Vorschau verwenden. Die grünen Integrationsprüfungen sind keine Freigabe sämtlicher Konzeptanforderungen.
- Der Windows-Test umfasst Administrator-Einrichtung, Anmeldung, asynchrone Restaurant-Provisionierung, Reservierung, Konflikt, Storno, zwei nebenläufig gesendete Widget-Buchungen, Worker/Scheduler und Wiederholung des Installers mit unveränderten Schlüsseln und erhaltenen Reservierungen. Ein echter Windows-Neustart, Nebenläufigkeit bei Tischwechseln/Konfigurationsänderungen und Backup/Restore sind dadurch nicht abgenommen.
- Quellcode öffentlich auf Branch `codex/windows-application`, [Entwurfs-PR #1](https://github.com/Kabutori/Platzhirschv2/pull/1). Keine Zusammenführung nach `main` und keine Produktionsfreigabe der vollständigen Konzeptphasen.

## Definition für die Produktionsfreigabe

1. Alle erforderlichen CI-Prüfungen grün; Composer-Lockdatei aus einem geprüften Build im Repository fixiert.
2. Install.bat auf frischem Windows Server 2025 und Server 2022 getestet, inklusive erforderlicher Windows-Neustarts und zweitem Installer-Lauf.
3. Setup ohne gültigen Schlüssel gesperrt; Schlüssel nach Abschluss wirkungslos; CSRF, MFA-Replay und Kontosperren nachgewiesen.
4. Zwei Restaurants angelegt; gegenseitiger Zugriff auf Daten und Tickets zuverlässig abgewiesen, einschließlich direkter Objekt-IDs und manipulierten Headern.
5. Zwei tatsächlich gleichzeitige MySQL-Buchungen desselben Tisches: genau eine erfolgreich; nebenläufige Tischwechsel, Stornierung und Konfigurationsänderungen ebenfalls testen.
6. Nach Serverneustart laufen MySQL, IIS, beide Worker und Scheduler ohne interaktive Anmeldung.
7. HTTPS, gültige Zertifikatskette, sichere Cookies, SMTP, protokollierte Fehler und Datenbankzugriffsrechte verifiziert.
8. Backup in isolierter Umgebung vollständig wiederhergestellt, einschließlich Anwendungsschlüssel, Tenant-Zugänge und DB-Benutzer.
9. Fehlende Produktmodule nach vereinbartem Umfang implementiert und mit echten End-to-End-Tests abgenommen.
