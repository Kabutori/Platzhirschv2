# Updates, Rollback und koordinierte Wiederherstellung

Diese Abläufe werden lokal als Windows-Administrator im vollständig entpackten, vertrauenswürdigen Release-Paket gestartet. Sie gehören zur GitHub-/Windows-Anwendung. Die Weboberfläche erhält keine administrativen Windows- oder MySQL-Zugangsdaten.

## Update

```powershell
.\Update.bat -InstallPath C:\Platzhirsch -BackupRoot D:\Platzhirsch-Backups
```

Das Paket wird vor jeder Änderung vollständig gegen sein Release-Manifest geprüft. Der Ablauf unterstützt Anwendungsversionswechsel der aktuellen 0.1-Reihe mit den vorhandenen PHP-/MySQL-Laufzeiten. Ein Wechsel der MySQL-/PHP-Laufzeit oder Windows-Version ist kein Anwendungsupdate und benötigt einen eigenen freigegebenen Adapter.

1. Ein maschinenweiter Mutex verhindert parallele Update-/Recovery-Operationen. Ein Wartungsjournal bleibt nach einem Abbruch erhalten.
2. IIS und Scheduler werden angehalten; Worker dürfen keine neue Arbeit beginnen. Laufende PHP-Arbeit darf fertig werden. Nach 300 Sekunden wird abgebrochen, ohne sie zu töten. Unfertige Provisionierungs-/Umzugsaufträge verhindern die Sicherung.
3. Plattform und alle autorisierten Restaurantserver werden gemeinsam gesichert. Auf allen beteiligten MySQL-Instanzen werden Lesesperren gehalten, bevor der erste Tabellenexport beginnt. Dadurch existiert ein gemeinsamer ruhender Zustand. Die Sperren betreffen die gesamte jeweilige MySQL-Instanz: nur dafür freigegebene Server verwenden und Wartung einplanen.
4. Anwendungscode wird ersetzt; `.env`, Schlüssel und gespeicherte Dateien bleiben bestehen. Plattformmigrationen laufen über den lokalen administrativen Datenbankzugang. Aktive Restaurants erhalten Basismigrationen und Migrationen aktivierter Zusatzmodule; temporäre DDL-Rechte werden wieder entzogen.
5. Plattform, alle aktiven Restaurantdatenbanken und HTTP-Erreichbarkeit werden geprüft. Erst danach starten Hintergrundaufgaben wieder.
6. Bei Fehlern nach Beginn der Änderungen werden Datenbanken **und** alter Anwendungscode aus der Sicherung zurückgesetzt. Schlägt auch dies fehl, bleibt die Anwendung angehalten; das Journal nennt die Sicherung.

Ein Rollback stellt den Sicherungszeitpunkt wieder her. Bei einem späteren manuellen Rollback gehen danach entstandene Änderungen aus dem wieder aktiven Datenstand verloren; der aktuelle Stand wird deshalb vorher zusätzlich gesichert.

```powershell
.\Update.bat -Mode Status -InstallPath C:\Platzhirsch
.\Update.bat -Mode Rollback -InstallPath C:\Platzhirsch -BackupPath D:\Platzhirsch-Backups\before-update-...
.\Update.bat -Mode Resume -InstallPath C:\Platzhirsch
```

`Resume` ist nur für einen gestoppten, fertig gesicherten, geprüften oder bereits zurückgerollten Zustand erlaubt. Bei einer unterbrochenen Änderung zuerst Rollback. Die Skripte laufen nicht automatisch nach einem Rechnerneustart weiter; IIS-Autostart und Aufgaben bleiben während des kritischen Abschnitts deaktiviert.

## Gemeinsame Sicherung mehrerer Datenbankserver

```powershell
.\Recovery.bat -Mode Backup -InstallPath C:\Platzhirsch -Destination D:\Backups\abend-20260910
.\Recovery.bat -Mode Verify -InstallPath C:\Platzhirsch -Destination D:\Backups\abend-20260910
.\Recovery.bat -Mode Restore -InstallPath C:\Platzhirsch -Destination D:\Backups\abend-20260910
```

Das Ziel muss neu und außerhalb der Installation liegen. Sicherungen enthalten Anwendungscode, Konfiguration, APP_KEY, private Serverzugänge, Aufgaben sowie logische Tabellenexporte der Plattform und der Platzhirsch-Restaurants auf allen autorisierten Servern. Auch zurückbehaltene `ph_t_...`-Schemata auf diesen Servern werden erfasst. SHA-256 prüft die Archivdateien; nach dem Import werden Zeilenanzahl und ein reihenfolgeunabhängiger Inhaltsvergleich geprüft. Binärwerte werden verlustfrei kodiert; große Tabellen werden gestreamt und beim Import in 1000-Zeilen-Transaktionen verarbeitet.

Die lokalen Root-Zugangsdaten stammen aus der geschützten Installation. Externe Server verwenden die lokal autorisierten Provisionierungszugänge. Für Sicherungen benötigen diese zusätzlich MySQL-Lesesperrrechte (`RELOAD`/`FLUSH_TABLES`) und Zugriff auf alle zu sichernden Anwendungsschemata. Alternativ kann ein Administrator eine gesonderte Datei `app\storage\app\private\recovery-server-ID.json` mit `username`, `password` und `account_host` hinterlegen. Fehlende Rechte führen zum Abbruch; es werden keine Rechte automatisch erweitert. Externe Verbindungen verlangen eine vertrauenswürdige CA. Für die Rücksicherung werden Schema- und Benutzerverwaltungsrechte benötigt.

MySQL-Root-/Systemkonten anderer Server werden nicht überschrieben. Restaurantkonten werden aus den gesicherten Plattformdaten mit ihren bisherigen Passwörtern und reinen DML-Rechten eingerichtet. Fremde Datenbanken und individuell eingerichtete Benutzer außerhalb von Platzhirsch gehören nicht zu diesem Backup. Eigene Views, Trigger, Routinen und Events in Anwendungsschemata werden erkannt und abgelehnt, damit sie nicht unbemerkt fehlen.

Backups enthalten sämtliche Zugangsdaten und personenbezogene Daten. Das Verzeichnis wird auf Administratoren/SYSTEM beschränkt; zusätzlich ein verschlüsseltes externes Ziel und eine Aufbewahrungsregel verwenden. Prüfsummen sind keine Signatur gegen einen Angreifer, der das gesamte Archiv verändern kann. Nur eigene vertrauenswürdige Sicherungen zurückspielen. Der alte physische `Snapshot-Platzhirsch.ps1` bleibt unverändert für lokale Einzelserver-Snapshots verfügbar.

## Wiederherstellung auf einem neuen Rechner

1. Die alte Anwendung vollständig abschalten. Keine parallelen Writer zulassen.
2. Dasselbe geprüfte Release auf dem neuen Windows-Rechner frisch installieren. Noch keinen ersten Administrator einrichten. Installationspfad und lokaler Port dürfen sich unterscheiden.
3. Für externe Datenbanken neue leere MySQL-Ziele bereitstellen. Die vorhandenen Restaurantdatenbanken dürfen dort noch nicht existieren. Zielzuordnung mit Zugangsdaten lokal geschützt hinterlegen; Server-IDs aus `databases\databases.json` übernehmen.
4. Wiederherstellen:

```powershell
.\Recovery.bat -Mode NewMachine -InstallPath C:\Platzhirsch `
  -Destination D:\Backups\abend-20260910 `
  -TargetMapping C:\Platzhirsch\recovery-targets.json -SourceOffline
```

Beispielstruktur der Zielzuordnung (keine echten Zugangsdaten):

```json
{
  "local": {"host":"127.0.0.1","port":3308,"username":"root","password":"LOKALES-ZIELPASSWORT","account_host":"127.0.0.1","ca":null},
  "server-1": {"host":"mysql2.example.org","port":3306,"username":"recovery_admin","password":"ZIELPASSWORT","account_host":"NEUE-APP-SERVER-IP","ca":"C:/Certificates/mysql-ca.pem"}
}
```

Die lokale Zuordnung muss exakt zur frischen Zielinstallation passen. Die neue lokale MySQL-Instanz behält ihre eigenen Administrator- und Dienstzugänge. APP_KEY, Benutzer, Mandantendaten und Einstellungen werden übernommen. Externe Serveradressen und ihre Autorisierungsdateien werden auf die ausdrücklich angegebenen Ziele angepasst. Den für die Anwendung benötigten gemeinsamen CA-Pfad `DB_SERVER_CA_FILE` auf dem Ziel vor der Wiederherstellung konfigurieren.

Neue IIS-Bindungen und lokale URL bleiben erhalten; Zertifikatsspeicher, DNS und Firewall werden nicht aus der alten Maschine blind kopiert. Öffentlichen Zugriff anschließend mit `Enable-PublicAccess.ps1` einrichten und prüfen. Die neue Maschine benötigt die gleiche MySQL-Hauptversion. Ist eine Wiederherstellung fehlgeschlagen, bleibt sie im Wartungsmodus; die Sicherung des frischen Zielzustands wird im Journal genannt.

## Neustart- und Belastungsprüfung

```powershell
.\installer\Test-OperationsReadiness.ps1 -InstallPath C:\Platzhirsch -Requests 100 -Concurrency 8
.\installer\Test-RebootRecovery.ps1 -InstallPath C:\Platzhirsch
# Optional mit ausdrücklich bestätigtem sofortigem Windows-Neustart:
.\installer\Test-RebootRecovery.ps1 -InstallPath C:\Platzhirsch -RestartNow
```

Die Neustartprüfung registriert eine einmalige Startaufgabe, vergleicht die tatsächliche Windows-Bootzeit und prüft MySQL, Worker, alle aktiven Restaurantdatenbanken und parallele HTTP-Aufrufe. Bei Erfolg entfernt sie die Aufgabe. Ergebnis: `logs\operations-readiness.json`, bei endgültigem Fehler zusätzlich `logs\reboot-check-failed.txt`.

Die HTTP-Prüfung misst Fehler, p95 und maximale Antwortzeit bei einer festgelegten Parallelität. Sie ist ein begrenzter technischer Belastungstest, keine allgemeine Kapazitätsfreigabe für beliebig große Installationen. Der automatisierte Recovery-Test enthält 10000 Zeilen mit Binärwerten und prüft deren unveränderte Rückkehr. Ein tatsächlicher Windows-Neustart lässt sich nicht als bestanden behaupten, bevor die Startaufgabe auf einer geeigneten Maschine erfolgreich gelaufen ist.

## Release-Prüfungen

Die Windows-Pipeline ergänzt Tests auf Server 2022/2025 für zwei MySQL-Instanzen, beschädigte Sicherungen, echte Plattform-/Mandantenmigrationen, manuellen Rollback und absichtlich fehlgeschlagene Migrationen mit automatischer Wiederherstellung. Ein weiterer sauberer Windows-Runner prüft die Wiederherstellung auf einem anderen Rechner mit anderem Pfad und Port. Veröffentlichung benötigt alle drei Prüfabschnitte. Das kurzlebige Recovery-Artefakt enthält ausschließlich synthetische CI-Daten und wegwerfbare CI-Zugangsdaten.
