# Windows: direkt in der IDE entwickeln

Aktueller Ausbau: [Updates, koordinierte Sicherung und Wiederherstellung](UPDATES-UND-WIEDERHERSTELLUNG.md). Die folgenden älteren Aussagen zu fehlenden Anwendungsupdates und ausschließlich lokalen Snapshots werden durch diesen Ablauf ergänzt. Grenzen und aktueller CI-Nachweis stehen in der neuen Anleitung.

Die Entwicklungsumgebung verwendet ein echtes Git-Checkout und Vite mit Live-Aktualisierung. Eine installierte Produktionsversion kann parallel laufen. Es gibt keinen automatischen Push und keine automatische Übernahme in Produktion.

## Einfach starten

Das neue Windows-Release-ZIP vollständig entpacken und **Start-Development.bat doppelklicken**. Die Datei `development/Prepare-Development.ps1` muss neben der BAT im entpackten Unterordner bleiben. Es sind keine vorherigen Git-Befehle nötig.

Beim ersten Start:

1. Git und Node.js prüfen. Fehlende Werkzeuge werden über WinGet installiert, wenn WinGet vorhanden ist. Windows kann dafür Bestätigungen verlangen. Ist WinGet nicht vorhanden (z. B. auf manchen Servern), nennt die BAT das fehlende Werkzeug; nach dessen Installation dieselbe BAT erneut starten. Node.js muss mindestens Version 22 haben; ältere vorhandene Versionen werden nicht stillschweigend ersetzt.
2. Quellcode aus `Kabutori/Platzhirschv2`, Branch `codex/windows-application`, holen. Bei privatem Repository gegebenenfalls über Git/Git Credential Manager bei GitHub anmelden.
3. Unter `%USERPROFILE%\source\Platzhirschv2` ein Git-Checkout und einen eigenen Branch `dev/local-JJJJMMTT-HHMMSS` anlegen. Den ausgegebenen Ordner in der IDE öffnen.
4. Composer bei Bedarf lokal unter `.development/tools` installieren; der heruntergeladene Installer wird vor Ausführung mit der offiziellen SHA-384-Prüfsumme verglichen.
5. Entwicklungsdatenbank, API, Oberfläche und Hintergrundaufgaben starten.

Bei späteren Starts wird das vorhandene Checkout verwendet. Keine automatischen Pulls, Resets, Branchwechsel oder Pushes. Vorhandene lokale Änderungen bleiben erhalten. Eine bereits vorhandene fremde Zielstruktur wird abgewiesen. Beim Aufruf der BAT innerhalb eines Git-Checkouts wird direkt dieses Checkout verwendet.

PHP und MySQL müssen als Laufzeitdateien vorhanden sein. Standard ist die vorhandene Platzhirsch-Installation unter `C:\Platzhirsch\runtime`; dein Windows-Benutzer muss die Programmdateien lesen und ausführen können. Der DEV-Starter startet mit diesen Dateien eine **eigene** Datenbankinstanz. Er installiert nicht automatisch eine Produktionsinstallation. Die geschützten Produktionsrechte nicht pauschal öffnen.

Nur bei abweichenden Ordnern brauchst du einen Befehl:

```powershell
.\Start-Development.bat -DevelopmentPath D:\Entwicklung\Platzhirsch -RuntimePath D:\Platzhirsch\runtime
```

Alternativ eigene portable Laufzeiten über `-PhpPath C:\tools\php\php.exe -MySqlBin C:\tools\mysql\bin` angeben. Ein zusätzliches `Set-ExecutionPolicy` ist nicht nötig. Ältere Release-Pakete enthalten diesen Bootstrap noch nicht.

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

In der IDE Änderungen prüfen und die gewünschten Quelldateien committen. Anschließend **Create-PullRequest.bat doppelklicken** (im Checkout oder im neuen, vollständig entpackten Release-Paket).

Die BAT prüft Repository, eigenen Branch und einen sauberen Arbeitsstand. Sie zeigt die neuen Commits und fragt vor dem Push einmal nach. Nach Bestätigung pusht sie den Entwicklungsbranch und öffnet den GitHub-Vergleich mit Ziel `codex/windows-application`. Bei einem abweichenden Checkout-Ordner `-DevelopmentPath` wie beim DEV-Starter verwenden.

Im Browser **Create pull request** wählen und Titel/Beschreibung ergänzen. Existiert bereits ein offener PR für diesen Branch, diesen öffnen; neue Pushes aktualisieren ihn. Bei einem privaten Repository ist GitHub-Anmeldung erforderlich. Die BAT benötigt keine zusätzliche GitHub-CLI und speichert keine Tokens.

Nach erfolgreichen Prüfungen den PR auf GitHub zusammenführen. Die BAT committet und merged nichts automatisch und pusht niemals erzwungen. Der Paketworkflow baut geänderte Oberflächen aus den Quellen und enthält sie im neuen Installations-ZIP. Ein Push auf einen persönlichen Branch allein veröffentlicht noch kein Paket; maßgeblich sind der PR-Prüflauf und die Übernahme in den Paketbranch.

**Produktion:** ein geprüftes Paket gezielt bereitstellen. Der versionsübergreifende Updater für bestehende Installationen ist weiterhin offen. Deshalb gibt es bewusst keinen „DEV nach PROD kopieren“-Knopf. Entwicklungsdaten, Passwörter und lokale Konfiguration gehören nicht in ein Release. Bis zum Updater neue Versionen getrennt abnehmen und keine bestehende Installation überschreiben.
