# Platzhirsch unter Windows

## Status

Entwicklungsstand 0.1.0. Kein bereits abgenommenes Komplettprodukt. Vor jeder Installation `UMSETZUNGSSTAND.md` lesen. Auf einer separaten Test-VM beginnen; keine bestehenden Kundendaten verwenden.

## Was gestartet wird

`Install.bat` startet `installer/Install-Platzhirsch.ps1` mit Windows PowerShell 5.1. Die BAT setzt `-ExecutionPolicy Bypass` ausschließlich für den gestarteten PowerShell-Prozess. Eine vorherige manuelle `Set-ExecutionPolicy`-Eingabe ist nicht erforderlich; die globale Richtlinie bleibt unverändert. Organisationsrichtlinien behalten Vorrang.

Zielplattformen: Windows Server 2025/2022, x64. Windows 11 Pro/Enterprise besitzt einen separaten Feature-Aktivierungspfad, ist aber kein freigegebener Produktionsserver. Installation benötigt Administratorrechte, IIS-Komponentenquelle und ausreichend freien Speicher (mindestens 10 GB empfohlen). Der Installer nutzt standardmäßig `C:\Platzhirsch`, Web-Port 8378 und MySQL-Port 3308. Installationspfade sind absichtlich auf sichere einfache Verzeichnisse ohne Leerzeichen begrenzt.

## Fertiges Paket herunterladen

Aktuell geprüft: [Windows-Vorschau 13](https://github.com/Kabutori/Platzhirschv2/releases/tag/windows-preview-13-1). Erstinstallation und Anwendungstest sind auf Windows Server 2022 und 2025 erfolgreich.

Unter [GitHub Releases](https://github.com/Kabutori/Platzhirschv2/releases) eine Windows-Vorschau öffnen und unter **Assets** die Datei `Platzhirsch-…-windows-x64.zip` herunterladen. Vollständig entpacken und `Install.bat` als Administrator ausführen. Die automatisch angebotenen „Source code“-Archive enthalten keine gebauten Laufzeitkomponenten. Wenn noch keine Vorschau vorhanden ist, sind die Installationstests noch nicht vollständig erfolgreich abgeschlossen.

Die Datei `SHA256SUMS.txt` enthält die Prüfsumme des ZIPs; unter PowerShell mit `Get-FileHash -Algorithm SHA256 .\Platzhirsch-…-windows-x64.zip` vergleichen. Vorschauen sind Entwicklungsstände, keine Freigabe aller Konzeptanforderungen.

## Paket bauen

1. Änderungen am Anwendungs-PR starten den Workflow **Windows installation package (preview)** automatisch. Nach Übernahme des Workflows auf den Standardbranch kann er zusätzlich manuell unter Actions ausgeführt werden.
2. Der Workflow installiert PHP-Abhängigkeiten, führt Funktionstests aus, baut die UI und lädt Herstellerpakete herunter. PHP wird gegen einen fest hinterlegten SHA-256-Wert geprüft, Microsoft-/Oracle-MSI bzw. EXE gegen deren Authenticode-Signaturen.
3. Nur nach erfolgreichen Installationstests auf Windows Server 2022 und 2025 wird die Vorschau unter Releases veröffentlicht. Das vorher erzeugte Workflow-Artefakt enthält das eigentliche `Platzhirsch-...-windows-x64.zip`. Dieses entpacken und nicht direkt aus dem ZIP ausführen.
4. Jeder Paketinhalt wird im Release-Manifest mit SHA-256 aufgeführt. Dieses Manifest schützt gegen Beschädigung, ersetzt aber keine Signatur des eigenen Release-Pakets. Nur Artefakte des eigenen verifizierten GitHub-Prüflaufs verwenden.

Die Quellcode-ZIP-Datei von GitHub allein ist **kein** fertiges Installationspaket. Der Installer stoppt ohne Release-Manifest und gebaute Anwendung. Es wird kein ungeprüftes `main` heruntergeladen und auf dem Zielserver kompiliert.

## Erstinstallation

Als Administrator im entpackten Installationspaket:

```powershell
.\Install.bat
# Oder explizit:
.\installer\Install-Platzhirsch.ps1 -InstallPath C:\Platzhirsch -Port 8378 -DatabasePort 3308
```

Bei gefordertem Neustart meldet das Skript Exitcode 3010. Nach dem Neustart denselben Befehl wiederholen. Bereits erzeugte Daten und Schlüssel bleiben erhalten. Die Wiederholung einer abgeschlossenen Installation mit unveränderten Schlüsseln und erhaltenen Reservierungen ist auf Server 2022 und 2025 geprüft. Wiederaufnahme nach einem tatsächlichen Abbruch oder Windows-Neustart ist noch separat nachzuweisen.

Am Ende `http://localhost:8378/admin/` öffnen und den angezeigten Einmal-Schlüssel verwenden. Namen, E-Mail und mindestens zwölf Zeichen langes Passwort für den ersten Administrator eingeben. Anschließend normal anmelden und im Bereich „Mein Konto“ TOTP aktivieren. Die Einrichtung nimmt nicht automatisch eine Anmeldung vor.

Die Standardinstallation öffnet keine Firewall-Regel und bindet HTTP nur an 127.0.0.1. Der Einmal-Schlüssel ist nicht als öffentlicher URL-Parameter oder im Repository gespeichert. Die Datei `installation.json` enthält Wiederaufnahme- und Wiederherstellungsgeheimnisse und darf nur für lokale Administratoren und SYSTEM lesbar sein.

## Erstes Restaurant

1. Plattform → Mandanten → Mandant anlegen. Name, Kontakt und Zeitzone eingeben.
2. Der Provisionierungsworker erstellt Datenbank und Datenbankbenutzer. Status muss auf „Aktiv“ wechseln. Bei „Fehlgeschlagen“ Logs prüfen, Ursache beheben und gezielt erneut versuchen.
3. Plattform → Benutzer: Restaurant-Administrator mit Mandanten-ID anlegen. Anfangspasswort über einen sicheren Kanal übergeben oder nach SMTP-Konfiguration einen Einrichtungslink senden.
4. Bereich wechseln → Restaurant auswählen. Räume und Tische anlegen; Öffnungszeiten hinterlegen.
5. Eine Reservierung erstellen. Außerhalb der Öffnungszeiten oder bei Überschneidung lehnt der Server die Buchung ab.
6. Unter Widget einen Buchungslink oder Einbettungscode erstellen: Website-Ursprünge, Gültigkeit, Buchungsdauer und optionale Akzentfarbe festlegen. Den einmal angezeigten Code auf der Website einfügen. Das Widget kapselt seine Gestaltung in einem Shadow DOM und bietet nach Datum/Uhrzeit und Gästezahl passende freie Tische an. Die endgültige Buchung prüft die Verfügbarkeit erneut transaktional. Server und Website benötigen für die öffentliche Einbettung HTTPS.

## HTTPS und Netzwerkzugriff

Voraussetzungen: DNS-Name, gültiges Zertifikat mit privatem Schlüssel in `LocalMachine\My` und vertrauenswürdige Zertifikatskette. Diese Daten kann kein Installer ohne die Infrastruktur des Betreibers automatisch beschaffen.

```powershell
.\installer\Enable-PublicAccess.ps1 -InstallPath C:\Platzhirsch `
  -HostName reservierung.example.org -CertificateThumbprint <Zertifikat-Fingerabdruck>
```

Der Befehl fragt vor Freigabe nach, prüft abgeschlossene Ersteinrichtung und Zertifikat, setzt die Anwendungs-URL sowie sichere Cookies, fügt eine IIS-HTTPS-Bindung hinzu und öffnet Port 443 für Domain-/Private-Firewallprofile. Er veröffentlicht keinen MySQL-Port. Bei abweichendem Netzwerkprofil oder externer Firewall ist eine bewusste separate Freigabe nötig. Automatische Zertifikatserneuerung ist noch nicht eingerichtet.

## E-Mail

In `C:\Platzhirsch\app\.env` die tatsächlichen SMTP-Daten eintragen:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.org
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD="..."
MAIL_FROM_ADDRESS=reservierung@example.org
```

Danach als Administrator `C:\Platzhirsch\runtime\php\php.exe C:\Platzhirsch\app\artisan config:cache` ausführen und den Anwendungspool `Platzhirsch` neu starten. Niemals `.env` oder Schlüsseldateien nach Git committen. Ohne SMTP werden keine Passwort-Reset-Geheimnisse in das Mail-Log geschrieben. Automatische Reservierungsbestätigungen sind noch nicht implementiert.

## Hintergrundbetrieb und Rechte

- `PlatzhirschMySQL`: eigene MySQL-Windows-Service-Instanz, nur Loopback, LocalService.
- IIS-Pool `Platzhirsch`: eigene ApplicationPoolIdentity, keine lokalen Administratorrechte, keine Leserechte auf Provisionierungszugänge und keine Schreibrechte auf die ausführbaren Bootstrap-Caches. Diese erzeugt der Installer als Administrator.
- Geplante Aufgaben `Platzhirsch-default` und `Platzhirsch-provisioning`: Windows-Starttrigger, LocalService, eigener PowerShell-Überwachungsprozess. Jeder PHP-Prozess bearbeitet höchstens einen Job. Der Elternprozess beendet einen überlangen Job nach 180 Sekunden; erneute Sichtbarkeit in der Queue erst nach 300 Sekunden.
- `Platzhirsch-Scheduler`: `schedule:run` jede Minute, kein überlappender Start.
- Der DB-Benutzer der Webanwendung hat nur Plattform-DML. Tenant-Datenbankbenutzer haben nach der Migration nur DML-Rechte im eigenen Schema. Der Provisionierungsbenutzer darf Benutzer und ausschließlich passend benannte Tenant-Schemata provisionieren. Provisionierung, Migration und anschließende Buchungen mit diesen Zugängen sind im echten Windows-/MySQL-Test geprüft. Weitere negative Datenbank-Rechteprüfungen stehen aus.
- Provisionierungszugang: `app\storage\app\private\provision.json`, nicht für IIS lesbar. Der Webprozess besitzt keine DDL-Berechtigung, auch nicht für beliebiges SQL aus der Oberfläche.

## Logs und Fehler

- Installer: verständliche Phasenausgabe und Exitcode; ein dauerhaftes redigiertes Installer-Protokoll fehlt noch.
- PHP: `app\storage\logs\php.log` (für die IIS-Anwendungsidentität schreibbar).
- MySQL: `logs\mysql.log`.
- Anwendung: `app\storage\logs\laravel-*.log`.
- Fehlgeschlagene Queue-Aufgaben: zentrale Tabelle `failed_jobs` (enthält interne Fehlerdetails, Zugriff begrenzen).
- Systemübersicht: DB-Erreichbarkeit, Warteschlange und letzter Scheduler-Heartbeat. Ein grüner Datenbanktest beweist keine fehlerfreie E-Mail- oder Worker-Funktion.

## Rollen und Team

Restaurant → Rollen & Rechte: Mitarbeiterrollen anlegen, Rechte einzeln auswählen und speichern. Buchungsänderungen, Storno und Export benötigen zusätzlich das Leserecht. Restaurant → Team: Benutzer bearbeiten und die eigene Rolle zuweisen; die Grundrolle bleibt „Mitarbeiter“. Ohne eigene Rolle gelten die bisherigen Mitarbeiterrechte. Administratoren behalten die Team- und Rollenverwaltung. Eigene Administratorrechte können nicht über dieses Formular entzogen werden.

Rollenänderungen wirken ab der nächsten API-Anfrage. Die UI zeigt nur erlaubte Bereiche; der Server prüft jeden Zugriff unabhängig davon. Bereits verwendete Rollen lassen sich erst löschen, wenn die Zuordnungen entfernt wurden. Veraltete gleichzeitige Rollenänderungen werden abgewiesen.

## Backup und Wiederherstellung

Für eine vollständige Sicherung auf derselben Windows-Installation:

```powershell
.\installer\Snapshot-Platzhirsch.ps1 -Mode Backup -InstallPath C:\Platzhirsch -Destination D:\Backups
```

Der Befehl hält IIS, Hintergrundaufgaben und MySQL an, kopiert die vollständige Installation einschließlich MySQL-Systemdatenbank, Tenant-Daten, Benutzerzugängen, Anwendung und Schlüsseln, sichert NTFS-Rechte und berechnet SHA-256-Prüfsummen. Anschließend startet er den Betrieb wieder. Für das Kopieren und Prüfen entsteht eine Wartungsunterbrechung. Das Sicherungsverzeichnis ist nur für lokale Administratoren und SYSTEM zugänglich.

Der ausgegebene Sicherungspfad wird zur Wiederherstellung angegeben:

```powershell
.\installer\Snapshot-Platzhirsch.ps1 -Mode Restore -InstallPath C:\Platzhirsch `
  -Destination D:\Backups\platzhirsch-20260907-120000-12345678
```

Die Wiederherstellung prüft zuerst alle Dateien. Sie akzeptiert ausschließlich dieselbe Maschine, denselben Installationspfad, dieselbe Paketversion und unveränderte Ports beziehungsweise öffentliche Anwendungsadresse. Nach Bestätigung sichert sie den aktuellen Stand zusätzlich unter `vor-wiederherstellung-…`, bevor Dateien und Datenbanken auf den gewählten Zeitpunkt zurückgesetzt werden. Schlägt das eigentliche Zurückkopieren oder die Rechtewiederherstellung fehl, bleibt die Anwendung angehalten; die vorherige Sicherung bleibt für die Rückkehr erhalten. Automatisierte Aufrufe können die PowerShell-Bestätigung mit `-Confirm:$false` nach bewusst gewählter Sicherung ausschalten.

Dies ist eine Wiederherstellung der bestehenden Installation, kein Umzug auf eine neue Maschine: IIS-Bindungen, Zertifikatsspeicher, Firewall, Aufgabenregistrierung und MSI-Registrierung liegen außerhalb der kopierten Dateien. Für einen Hardwareausfall ist weiterhin eine vollständige Windows-/VM-Sicherung erforderlich. Das Skript lehnt symbolische Links und Junctions ab.

`Backup-Platzhirsch.ps1` bleibt für zusätzliche logische SQL-Exporte verfügbar. Diese enthalten keine MySQL-Systembenutzer und können nicht an den Snapshot-Restore übergeben werden.

Backups enthalten personenbezogene Daten und Zugangsdaten. Verschlüsseltes externes Ziel, Aufbewahrung, Löschregeln und regelmäßige Rücksicherungstests gehören zum Betrieb. Ein zweiter Ordner auf demselben Datenträger schützt nicht vor dessen Ausfall.

## Updates und Deinstallation

Der Installer akzeptiert Wiederholung derselben Version und lehnt automatische Versionswechsel ab. Kein `git pull`, kein automatisches Löschen oder Neuerstellen von Datenbanken, keine Rotation von APP_KEY beim Update. Ein migrationssicherer Update-/Rollback-Ablauf ist noch nicht freigegeben. Datenbank-Rollback ist nicht mit dem Zurückkopieren alter PHP-Dateien gleichzusetzen.

Es gibt absichtlich keine automatische datenlöschende Deinstallation. Vor manuellen Änderungen Backup und Dienst-/Pfadzuordnung prüfen.

## Verwendete Herstellerreferenzen

- PHP NTS unter IIS: https://www.php.net/manual/en/install.windows.iis.php
- PHP Windows-Builds und Prüfsummen: https://www.php.net/downloads.php?os=windows
- MySQL Windows-Installation: https://dev.mysql.com/doc/refman/8.4/en/windows-installation.html
- MySQL 8.4 LTS Downloads: https://dev.mysql.com/downloads/mysql/8.4.html
- IIS FastCGI: https://learn.microsoft.com/en-us/iis/configuration/system.webserver/fastcgi/application/
- IIS URL Rewrite: https://www.iis.net/downloads/microsoft/url-rewrite
- PCNTL ist unter Windows nicht verfügbar: https://www.php.net/manual/en/pcntl.installation.php

- NTFS-Rechte sichern/wiederherstellen: [Microsoft icacls](https://learn.microsoft.com/en-us/windows-server/administration/windows-commands/icacls)
- Kopierparameter und Fehlercodes: [Microsoft Robocopy](https://learn.microsoft.com/en-us/windows-server/administration/windows-commands/robocopy)

## Testrestaurant und Plattformrollen

Administration → Mandanten → Testrestaurant einrichten erstellt einen getrennten Testmandanten mit frei gewähltem Restaurantadministrator (E-Mail und Passwort), Testraum, drei Tischen und täglichen Öffnungszeiten. Die Bereitstellung läuft als Hintergrundjob; erst bei Status „aktiv“ unter `/restaurant/login` anmelden. Es gibt kein allgemein gültiges Demo-Passwort.

Administration → Rollen & Rechte verwaltet Plattformrollen. „Lokal speichern“ speichert einen Entwurf; „Entwurf prüfen“ prüft dessen registrierte Berechtigungscodes; „Rechte aktivieren“ übernimmt den geprüften Entwurf für folgende Anfragen. Diese Prüfung ist kein verteilter Testserver-Rollout. System Administrator bleibt gesperrt und unveränderbar. Zugewiesene Rollen können nicht gelöscht werden. Plattformmitarbeiter erhalten eine aktivierte Rolle bei der Benutzeranlage; sie erhalten dadurch keinen Restaurantzugriff.

Administration → Module zeigt die tatsächlich registrierten Module, Versionen und Abhängigkeiten. Kauf, mandantenbezogene Aktivierung und unabhängige Composer-/npm-Auslieferung sind noch nicht implementiert. Datenbankserver bleiben Prüfziele; Provisionierung erfolgt lokal.

Neue Versionspakete sind weiterhin für Neuinstallationen vorgesehen. Der Installer verweigert einen Versionswechsel einer vorhandenen Installation; vorhandene Datenordner nicht löschen oder mit einem neuen Paket überschreiben.
