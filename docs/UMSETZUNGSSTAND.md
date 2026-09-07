# Platzhirsch – tatsächlicher Umsetzungsstand

Stand: 2026-09-07. Version: 0.1.0 (Entwicklungsstand, keine Produktionsfreigabe).

Dieses Dokument beschreibt ausschließlich den Quellcode dieses Repositories. Die älteren Dokumente KONZEPT.md, ADMIN-UI.md und BACKEND-BASIS.md beschreiben teilweise andere, hier nicht vorhandene Worktrees. Deren Testberichte gelten nicht für diesen Code.

## Implementiert

- Laravel-Anwendung mit zentraler Plattform-Datenbank und eigenem MySQL-Schema sowie eigenem Datenbankbenutzer je Restaurant.
- Erstadministrator mit Einmal-Schlüssel, gesperrtem Sentinel und Datenbanktransaktion; kein automatischer Login nach Setup.
- Session-Anmeldung, Logout, Kontosperren, CSRF-Schutz, Login-Limitierung, Passwort-Reset per konfiguriertem SMTP, TOTP-Einrichtung und Replay-Schutz.
- Plattformübersicht, Mandantenanlage und asynchrone Provisionierung, Sperren/Freigeben, Benutzeranlage und Einladungslinks, Audit-Protokoll, Betriebsdaten.
- Drei unveränderliche Rollen: System-Administrator, Restaurant-Administrator und Mitarbeiter. Keine frei editierbare Rollenverwaltung.
- Restaurantprofil, Räume, Tische, wöchentliche Öffnungszeiten und Sondertage.
- Reservierungen erstellen, bearbeiten und stornieren; Kapazitätsprüfung, Öffnungszeitenprüfung, zeitzonenbezogene Eingabe, Speicherung in UTC, transaktionale Tischsperren und Prüfung auf zeitliche Überschneidungen.
- Reservierungsübersicht, Tageskennzahlen, Belegungsansicht je Tisch, CSV-Export mit Schutz gegen Tabellenformeln.
- Buchungslinks mit Ablaufdatum und Widerruf, öffentliche Buchungsseite und öffentliche eingeschränkte Buchungs-API. Noch kein Shadow-DOM-Embed.
- Support-Tickets, Nachrichten und interne Notizen mit Mandantengrenzen.
- React/TypeScript-Oberfläche in Anlehnung an die gelieferten Prototypen, dunkles sharp/flat-Design, lokal ausgelieferte Schriften, Formularvalidierung, Lade-/Fehler-/Leerzustände.
- Windows-Installer-Quellcode: BAT-Einstieg, PowerShell 5.1, IIS/FastCGI, PHP NTS, eigene MySQL-Instanz, lokale Erstinstallation, gesonderte HTTPS-Freigabe und Backup-Skript.
- Windows-Release-Workflow und CI für Frontend, PHP-Funktionstests, Dependency-Prüfung und PowerShell-Syntax.

## Absichtlich noch nicht als fertig bezeichnet

Die vollständige Anwendung aus allen Konzeptphasen ist mit diesem ersten Stand **nicht** umgesetzt. Insbesondere fehlen:

- Frei konfigurierbare Rollen/Berechtigungsfamilien, organisationsübergreifender Rollen-Rollout, SSO und Sidebar-Favoriten.
- Produktive Modulregistrierung, Modul-Marktplatz, Kauf/Aktivierung/Versionsmanagement und unabhängige Modul-Repositories.
- Abos, rechtlich geprüfte Rechnungen, Zahlungsanbieter, automatische Abrechnung und Testphasenpolitik.
- Mehrere Datenbankserver, Cluster-Resolver, Umzug/Massenmigration von Mandanten.
- Shadow-DOM-Widget und konfigurierbare Widget-Designs, automatische Buchungs-E-Mails/SMS.
- Öffentliche Marketing-Website, öffentliche Selbstregistrierung, E-Mail-Verifikation, Rechtstexte und deren Gestaltung.
- Odoo-, Wetter- und weitergehende Reporting-Integrationen; PDF/XLSX/SQL/XML-Exporte.
- Vollständiger Update-/Rollback-/Restore-Automat, signierter eigener Installer, Zertifikatserneuerung, externe Backup-Ablage und Monitoring-Alarmierung.
- Vollständige visuelle 1:1-Abnahme, Bildschirmleser-/Tastatur-Abnahme, browserbasierte E2E-Suite und mobile Detailabnahme.

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

## Verifikationsgrenze

- Der React-/TypeScript-Produktionsbuild wurde in der Arbeitsumgebung erfolgreich ausgeführt.
- PHP und PowerShell sind lokal nicht verfügbar. Die Laufzeitprüfungen wurden daher über GitHub Actions ausgeführt.
- Nach Korrektur der Testbenutzer-Vorbereitung ist der [CI-Lauf 34129420659](https://github.com/Kabutori/Platzhirschv2/actions/runs/34129420659) für Commit `acb4ea8ca6397a8f749345d0695099430ad871e6` vollständig grün: PHP 8.5.10, 17 Tests / 64 Assertions, PHP-Syntax, Composer-Sicherheitsprüfung, Frontend-Produktionsbuild, npm-Sicherheitsprüfung und Windows-PowerShell-5.1-Syntaxprüfung. Die dort erzeugte Composer-Lockdatei wird unverändert übernommen; das heruntergeladene ZIP wurde gegen den von GitHub gelieferten SHA-256-Wert geprüft.
- Quellcode öffentlich auf Branch `codex/windows-application`, [Entwurfs-PR #1](https://github.com/Kabutori/Platzhirschv2/pull/1). Keine Zusammenführung nach `main`, kein gebautes Windows-Release und keine Produktionsfreigabe. Spätere Commits benötigen eigene erfolgreiche Prüfläufe.
- SQLite-Funktionstests prüfen Geschäftsregeln und Zugriffsgrenzen, aber beweisen weder MySQL-DDL noch konkurrierende InnoDB-Sperrsemantik. Dafür ist ein gesonderter echter MySQL-Paralleltest erforderlich.
- Ein gebautes ZIP beweist noch keine erfolgreiche Windows-Installation. Ein vollständiger VM-Test mit Neustart, Wiederaufnahme, Mandanten-Provisionierung, Login, Buchung, Backup und isoliertem Restore ist Pflicht vor Freigabe.

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
