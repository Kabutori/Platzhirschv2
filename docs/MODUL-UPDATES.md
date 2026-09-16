# Modulversionen und private Paketquelle

Administration → Module → **Versionen & Updates** verbindet die 18 Einzel-Repositories mit dem bestehenden Windows-Updateablauf. Nur Systemadministratoren erhalten Zugriff.

## Einmalige Einrichtung in der Oberfläche

1. Ein aktuelles Windows-Paket installieren. Es enthält einen privaten Composer-/npm-Paketkatalog mit allen eingebauten Modulversionen.
2. **Private Paketquelle einrichten** öffnen. Einen Fine-grained GitHub-Token für `Kabutori` eintragen: für die 18 Modul-Repositories **Contents: Read**, für `Platzhirschv2` **Contents: Read**, **Actions: Read/Write** und **Secrets: Read/Write**. GitHub kann Berechtigungen bei einem Token auf alle ausgewählten Repositories anwenden; den Zugriff auf diese 19 Repositories begrenzen.
3. **Build-Pipeline auf GitHub einrichten** auswählen und mit Administrator-Passwort bestätigen. Der Token wird lokal mit dem Laravel-App-Schlüssel verschlüsselt. Das Actions-Secret `MODULE_REPOSITORY_TOKEN` wird über den öffentlichen Repository-Schlüssel verschlüsselt gesetzt. Die Oberfläche gibt den GitHub-Token nicht zurück.
4. Optional ein Lesetoken für externe Composer-/npm-Clients erzeugen. Es erscheint genau einmal. Eine Rotation widerruft das bisherige Lesetoken. Externe Zugriffe benötigen HTTPS. Dieser reine Lesezugang hat keine Build- oder Schreibrechte.

Der GitHub-Zugang muss vom Betreiber einmal in seiner Installation hinterlegt werden; er ist absichtlich nicht Bestandteil eines Downloads. Entfernen des lokalen Zugangs löscht nicht das separat gespeicherte GitHub-Actions-Secret. Dieses bei einer vollständigen Stilllegung auch auf GitHub löschen bzw. den Token widerrufen.

## Neue Modulversion übernehmen

1. Im betreffenden Einzel-Repository PHP-/UI-Manifeste und `module.json` versionieren. Einen Tag `vX.Y.Z` veröffentlichen. Der dortige Workflow erzeugt den Release mit `packages.json` und Archiven.
2. In der Administration beim Repository **Versionen laden**. Es werden die neuesten 30 stabilen Releases geprüft und bisher unbekannte Versionen unveränderlich in den privaten Katalog aufgenommen. Ein vorhandener Versionsstand wird nicht überschrieben.
3. Zielversionen auswählen und **Zusammenstellung prüfen**. Alle internen PHP-, npm- und Peer-Abhängigkeiten müssen exakt zusammenpassen. Bei Konflikten die zusammengehörigen Pakete gemeinsam auswählen.
4. **Geprüften Build starten** und mit Passwort bestätigen. Die Zusammenstellung wird mit Repository-Commits gespeichert. Der Workflow verwendet den freigegebenen `main`-Stand und prüft unveränderte Release-Commits und Archivprüfsummen. Er installiert die Pakete über Composer-/npm-Registry-Protokolle, führt Backend-/Browsertests sowie Windows-Installation und Wiederherstellung aus.
5. **Status aktualisieren**, dann **Update bereitstellen**. Der Windows-Dienst prüft erneut den erfolgreichen Build und lädt ausschließlich das dazugehörige GitHub-Vorschaupaket. Er kontrolliert Download-Prüfsumme, Dateimanifest und Auftrags-ID.
6. Unter **Freigegebene Updates** → **Update installieren** separat bestätigen. Der vorhandene Updater übernimmt Wartungsmodus, Sicherung, Plattform-/Mandantenmigrationen und automatischen Datenbank-/Code-Rollback bei Fehlern.

Ein Build installiert nichts automatisch. Unterschiedliche Modulversionen je Restaurant und PHP-Hotloading werden nicht unterstützt. Ein Rollback setzt die gesamte geprüfte Zusammenstellung mit ihren Datenbanken zurück. Der Laufstatus ist über „Status aktualisieren“ abrufbar; die Suche berücksichtigt die letzten 100 Buildläufe.

## Paketclients

Die API unter `/api/module-registry` ist unabhängig von Browser-Sitzungen geschützt und verlangt `Authorization: Bearer <Lesetoken>`.

- Composer: Repository vom Typ `composer`, URL `https://SERVER/api/module-registry/composer/packages.json`. Das Lesetoken außerhalb des Projekts in Composer `auth.json` unter `bearer` für den Servernamen speichern.
- npm: `@platzhirsch:registry=https://SERVER/api/module-registry/npm/`. Das Lesetoken in einer nicht versionierten npm-Konfiguration für `//SERVER/api/module-registry/:_authToken` setzen, damit Metadaten und Archive autorisiert werden.

Die interne Registry ist eine schreibgeschützte Composer-/npm-Quelle. Veröffentlichungen kommen über die geprüften Einzel-Repository-Releases, nicht über unkontrolliertes `npm publish` aus der Oberfläche. Die CI verwendet eine ausschließlich an Loopback gebundene, temporäre Quelle mit denselben Paketmetadaten. Module werden nicht mehr aus Composer-Pfad-Repositories oder npm-Workspaces installiert, wenn ein Modulbuild angefordert wird. Der normale Quellcode-Entwicklungsbuild bleibt unverändert möglich.

## Bestand und Sichtbarkeit

Ein bestehender OperationsUI-Dienst muss die neuen Dienstskripte erhalten. Nach dem ersten Upgrade aus einem älteren Paket einmal `installer/Enable-OperationsUI.ps1 -InstallPath C:\Platzhirsch` aus dem neuen Paket als Administrator ausführen. Neue Installationen erledigen dies automatisch.

Die Einzel-Repositories und Registry-Zugriffe sind privat. Das Haupt-Repository und seine Windows-Vorschau-Releases sind weiterhin öffentlich; die gebauten Windows-Pakete enthalten daher auch die eingebauten Modulquellen. Der Registry-Schutz ändert diese bestehende Veröffentlichungspolitik nicht.
