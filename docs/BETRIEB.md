# Platzhirsch unter Windows

## Status

Entwicklungsstand 0.1.0. Kein bereits abgenommenes Komplettprodukt. Vor jeder Installation `UMSETZUNGSSTAND.md` lesen. Auf einer separaten Test-VM beginnen; keine bestehenden Kundendaten verwenden.

## Was gestartet wird

`Install.bat` startet `installer/Install-Platzhirsch.ps1` mit Windows PowerShell 5.1. Keine globale ExecutionPolicy-Änderung und kein ExecutionPolicy-Bypass. Organisationsrichtlinien können eine Signatur oder Freigabe verlangen; der Installer umgeht sie nicht.

Zielplattformen: Windows Server 2025/2022, x64. Windows 11 Pro/Enterprise besitzt einen separaten Feature-Aktivierungspfad, ist aber kein freigegebener Produktionsserver. Installation benötigt Administratorrechte, IIS-Komponentenquelle und ausreichend freien Speicher (mindestens 10 GB empfohlen). Der Installer nutzt standardmäßig `C:\Platzhirsch`, Web-Port 8378 und MySQL-Port 3308. Installationspfade sind absichtlich auf sichere einfache Verzeichnisse ohne Leerzeichen begrenzt.

## Paket bauen

1. Im GitHub-Repository den Workflow **Windows installation package (preview)** ausführen (nach Übernahme des Workflows auf den Standardbranch über Actions verfügbar).
2. Der Workflow installiert PHP-Abhängigkeiten, führt Funktionstests aus, baut die UI und lädt Herstellerpakete herunter. PHP wird gegen einen fest hinterlegten SHA-256-Wert geprüft, Microsoft-/Oracle-MSI bzw. EXE gegen deren Authenticode-Signaturen.
3. Das Workflow-Artefakt enthält das eigentliche `Platzhirsch-...-windows-x64.zip`. Dieses entpacken und nicht direkt aus dem ZIP ausführen.
4. Jeder Paketinhalt wird im Release-Manifest mit SHA-256 aufgeführt. Dieses Manifest schützt gegen Beschädigung, ersetzt aber keine Signatur des eigenen Release-Pakets. Nur Artefakte des eigenen verifizierten GitHub-Prüflaufs verwenden.

Die Quellcode-ZIP-Datei von GitHub allein ist **kein** fertiges Installationspaket. Der Installer stoppt ohne Release-Manifest und gebaute Anwendung. Es wird kein ungeprüftes `main` heruntergeladen und auf dem Zielserver kompiliert.

## Erstinstallation

Als Administrator im entpackten Installationspaket:

```powershell
.\Install.bat
# Oder explizit:
.\installer\Install-Platzhirsch.ps1 -InstallPath C:\Platzhirsch -Port 8378 -DatabasePort 3308
```

Bei gefordertem Neustart meldet das Skript Exitcode 3010. Nach dem Neustart denselben Befehl wiederholen. Bereits erzeugte Daten und Schlüssel bleiben erhalten. Die Wiederaufnahme ist als Codepfad implementiert, aber noch durch Abbruchtests auf Windows nachzuweisen.

Am Ende `http://localhost:8378/admin/` öffnen und den angezeigten Einmal-Schlüssel verwenden. Namen, E-Mail und mindestens zwölf Zeichen langes Passwort für den ersten Administrator eingeben. Anschließend normal anmelden und im Bereich „Mein Konto“ TOTP aktivieren. Die Einrichtung nimmt nicht automatisch eine Anmeldung vor.

Die Standardinstallation öffnet keine Firewall-Regel und bindet HTTP nur an 127.0.0.1. Der Einmal-Schlüssel ist nicht als öffentlicher URL-Parameter oder im Repository gespeichert. Die Datei `installation.json` enthält Wiederaufnahme- und Wiederherstellungsgeheimnisse und darf nur für lokale Administratoren und SYSTEM lesbar sein.

## Erstes Restaurant

1. Plattform → Mandanten → Mandant anlegen. Name, Kontakt und Zeitzone eingeben.
2. Der Provisionierungsworker erstellt Datenbank und Datenbankbenutzer. Status muss auf „Aktiv“ wechseln. Bei „Fehlgeschlagen“ Logs prüfen, Ursache beheben und gezielt erneut versuchen.
3. Plattform → Benutzer: Restaurant-Administrator mit Mandanten-ID anlegen. Anfangspasswort über einen sicheren Kanal übergeben oder nach SMTP-Konfiguration einen Einrichtungslink senden.
4. Bereich wechseln → Restaurant auswählen. Räume und Tische anlegen; Öffnungszeiten hinterlegen.
5. Eine Reservierung erstellen. Außerhalb der Öffnungszeiten oder bei Überschneidung lehnt der Server die Buchung ab.
6. Optional einen Buchungslink erstellen und auf der Restaurant-Website verlinken. Eine bereits vorhandene Tischbelegung wird bei der Buchung geprüft, nicht vorab mit Gästedaten öffentlich ausgegeben.

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
- IIS-Pool `Platzhirsch`: eigene ApplicationPoolIdentity, keine lokalen Administratorrechte, keine Leserechte auf Provisionierungszugänge.
- Geplante Aufgaben `Platzhirsch-default` und `Platzhirsch-provisioning`: Windows-Starttrigger, LocalService, eigener PowerShell-Überwachungsprozess. Jeder PHP-Prozess bearbeitet höchstens einen Job. Der Elternprozess beendet einen überlangen Job nach 180 Sekunden; erneute Sichtbarkeit in der Queue erst nach 300 Sekunden.
- `Platzhirsch-Scheduler`: `schedule:run` jede Minute, kein überlappender Start.
- Der DB-Benutzer der Webanwendung hat nur Plattform-DML. Tenant-Datenbankbenutzer haben nach der Migration nur DML-Rechte im eigenen Schema. Der Provisionierungsbenutzer darf Benutzer und ausschließlich passend benannte Tenant-Schemata provisionieren. Diese MySQL-GRANT-Regeln müssen im Windows-/MySQL-Test nachgewiesen werden.
- Provisionierungszugang: `app\storage\app\private\provision.json`, nicht für IIS lesbar. Der Webprozess besitzt keine DDL-Berechtigung, auch nicht für beliebiges SQL aus der Oberfläche.

## Logs und Fehler

- Installer: verständliche Phasenausgabe und Exitcode; ein dauerhaftes redigiertes Installer-Protokoll fehlt noch.
- PHP: `logs\php.log`.
- MySQL: `logs\mysql.log`.
- Anwendung: `app\storage\logs\laravel-*.log`.
- Fehlgeschlagene Queue-Aufgaben: zentrale Tabelle `failed_jobs` (enthält interne Fehlerdetails, Zugriff begrenzen).
- Systemübersicht: DB-Erreichbarkeit, Warteschlange und letzter Scheduler-Heartbeat. Ein grüner Datenbanktest beweist keine fehlerfreie E-Mail- oder Worker-Funktion.

## Backup und Wiederherstellung

```powershell
.\installer\Backup-Platzhirsch.ps1 -InstallPath C:\Platzhirsch -Destination D:\Backups
```

Das Skript exportiert Plattform- und Tenant-Datenbanken mit `--single-transaction`, legt essentielle Konfiguration/Schlüssel dazu und berechnet Prüfsummen. Es exportiert keine MySQL-Systemdatenbank und ist deshalb kein fertiger automatischer Restore. Vor Produktivbetrieb muss ein Restore-Ablauf die DB-Benutzer und Grants aus geschützten Metadaten rekonstruieren, Schlüssel wiederherstellen und die Anwendung prüfen. Eine komplette Rücksicherung ist noch zu implementieren und zu testen.

Backups enthalten personenbezogene Daten und Zugangsdaten. Verschlüsseltes externes Ziel, Aufbewahrung, Löschregeln und regelmäßige Rücksicherungstests sind Betreiberpflicht. Ein zweiter Ordner auf demselben Datenträger ist kein hinreichender Ausfallschutz.

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
