# Windows: direkt in der IDE entwickeln

Die Entwicklungsumgebung verwendet ein echtes Git-Checkout und Vite mit Live-Aktualisierung. Eine installierte Produktionsversion kann parallel laufen. Es gibt keinen automatischen Push und keine automatische Übernahme in Produktion.

## Einmal vorbereiten

Benötigt: Git, Node.js 22 mit npm, Composer 2, PHP 8.5 mit den Erweiterungen des Windows-Pakets und MySQL 8.4. Git, Node/npm und Composer müssen im PATH liegen. Nach ihrer Installation ein neues Terminal öffnen. GitHub-Anmeldung erfolgt über die IDE oder den Git Credential Manager, nicht über ein gespeichertes Token im Skript.

Die PHP-/MySQL-Programmdateien einer vorhandenen Platzhirsch-Installation können verwendet werden. Standard ist `C:\Platzhirsch\runtime`; dein Windows-Benutzer muss sie lesen und ausführen können. Der Starter installiert diese Entwicklerwerkzeuge nicht selbst. Alternativ eigene portable Laufzeiten über `-PhpPath` und `-MySqlBin` angeben. Die geschützten Produktionsrechte nicht pauschal öffnen.

```powershell
git clone --branch codex/windows-application https://github.com/Kabutori/Platzhirschv2.git C:\src\Platzhirschv2
cd C:\src\Platzhirschv2
git switch -c dev/meine-aenderungen
.\Start-Development.bat
```

Optional bei anderem Laufzeitordner:

```powershell
.\Start-Development.bat -RuntimePath D:\Platzhirsch\runtime
```

Oder mit getrennten, für deinen Benutzer zugänglichen Programmdateien:

```powershell
.\Start-Development.bat -PhpPath C:\tools\php\php.exe -MySqlBin C:\tools\mysql\bin
```

Kein Start aus dem Release-ZIP: Es enthält fertig gebaute Anwendungsteile. Die Startdatei liegt im Quellcode-Repository. Ein zusätzliches `Set-ExecutionPolicy` ist nicht nötig.

## Was gestartet wird

| Teil | Standard |
|---|---|
| Administration | http://127.0.0.1:5173/administration/login |
| Restaurant | http://127.0.0.1:5173/restaurant/login |
| PHP-API | 127.0.0.1:8000, über Vite erreichbar |
| Eigene MySQL-Instanz | 127.0.0.1:33018 |
| Entwicklungsdaten und Protokolle | `.development/` im Checkout |
| Plattform-Schema | `platzhirsch_development` |
| Restaurant-Schemata | `ph_t_…` ausschließlich in dieser Entwicklungsinstanz |

Der erste Start erzeugt eigene Schlüssel und Zugangsdaten, installiert die festgeschriebenen Composer-/npm-Abhängigkeiten und führt Entwicklungsmigrationen aus. Ein Einrichtungsschlüssel erscheint lokal im Terminal. Damit den ersten Administrator anlegen; anschließend kann in der Administration ein Testrestaurant mit Besitzerlogin erstellt werden.

Mit **Q** im Startfenster geordnet beenden. Bei erneutem Start bleiben Daten und Schlüssel erhalten. Ein bestehendes `app/.env`, gecachte Anwendungskonfiguration oder belegte Ports führen zum Abbruch. Der Starter übernimmt oder beendet keine bereits laufenden Datenbankdienste. Bei hartem Schließen des Terminals können Kindprozesse verbleiben; dann die eigenen Entwicklungsprozesse kontrolliert beenden, bevor erneut gestartet wird.

## Bearbeiten und sehen

- `admin-ui/packages/*/src`: Oberflächen der Module; React/CSS werden von Vite live aktualisiert.
- `admin-ui/src`: gemeinsamer Portalrahmen und Navigation.
- `app/packages/*/src`: PHP-Fachlogik, Routen und Modulmigrationen.
- `app/app`: Integrationsadapter und Hintergrundaufträge.
- `widget-embed/src`: öffentliches Widget; nach Änderung `node widget-embed/build.mjs` ausführen. Live-Aktualisierung betrifft zunächst die Portaloberflächen.

PHP-Webänderungen werden beim nächsten Request geladen. Entwicklungsworker starten für jeden Queue-Durchlauf einen neuen PHP-Prozess. Nach Änderungen an Abhängigkeiten, Konfiguration, Migrationen oder Modulregistrierung den Starter neu ausführen. Keine erzeugten Dateien unter `app/public/admin` bearbeiten.

`.development`, `.env`, Zugangsdaten und Abhängigkeiten werden nicht eingecheckt. Die Entwicklungsumgebung dient der lokalen Arbeit und bindet nur an Loopback. Keine Produktivdatenbank eintragen oder Produktivkonfiguration in das Checkout kopieren.

## Bewusst nach GitHub und später in Produktion

In der IDE Änderungen prüfen, ausgewählte Quelldateien committen und den eigenen Branch pushen:

```powershell
git status
git add admin-ui/packages/reservation/src/Screens.tsx
git commit -m "Reservierungsansicht anpassen"
git push -u origin dev/meine-aenderungen
```

Danach einen Pull Request in `codex/windows-application` erstellen. Die Anwendungsprüfungen laufen für Pull Requests; Windows-Pakete werden bei relevanten Änderungen ebenfalls geprüft. Quellcode wird gebaut, getestet und als Vorschau veröffentlicht. Das allein verändert keine vorhandene Installation.

**Produktion:** ein geprüftes Paket gezielt bereitstellen. Der versionsübergreifende Updater für bestehende Installationen ist weiterhin offen. Deshalb gibt es bewusst keinen „DEV nach PROD kopieren“-Knopf. Entwicklungsdaten, Passwörter und lokale Konfiguration gehören nicht in ein Release. Bis zum Updater neue Versionen getrennt abnehmen und keine bestehende Installation überschreiben.
