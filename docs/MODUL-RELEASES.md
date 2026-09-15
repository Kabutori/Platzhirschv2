# Eigenständige Modulpakete – erster Umsetzungsschritt

Die Modulversion wird jetzt aus der jeweiligen `composer.json` gelesen. Ein Modulupdate erfordert keine Änderung der Versionsnummer des Module Host. Die Versionsangaben in PHP und UI müssen übereinstimmen; andere Module und der Kern behalten ihre eigenen Versionen. Aktuelle Abhängigkeiten sind weiterhin exakt festgelegt: ein inkompatibler oder nicht angepasster Paketstand wird abgelehnt.

## Paketbau

`python tools/modules/packages.py --check` prüft alle 24 lokalen PHP-/npm-Pakete einschließlich der von der Anwendung angeforderten Versionen. `python tools/modules/packages.py --module reporting --output dist/reporting` erstellt ausschließlich die PHP-/UI-Archive dieses Moduls, `modules.json` mit Quellcommit und Abhängigkeiten sowie `SHA256SUMS.txt`. Ohne `--module` werden alle Pakete gebaut. Ein bestehendes Ausgabeziel wird nicht überschrieben.

Die Archive enthalten nur versionierte Quelldateien aus den expliziten Paketverzeichnissen. Keine unversionierten Arbeitsdateien, Schlüssel oder Laufzeitverzeichnisse. PHP-ZIPs besitzen `composer.json` an der Wurzel; npm-Tarballs enthalten den Standardordner `package/`. Archivzeitstempel sind festgelegt, damit gleiche Inhalte dieselben Prüfsummen erhalten. Lokale Builds können versionierte, noch nicht eingecheckte Änderungen enthalten; die Veröffentlichung verwendet ausschließlich das CI-Checkout.

GitHub Actions → **Build and publish one module**: Modulverzeichnis und bereits eingecheckte Version angeben. Ohne „publish“ entsteht nur ein Download-Artefakt. Mit „publish“ auf `main` wird nach Backend-Prüfungen und Anwendungsbuild ein eigener Release `module-reporting-v0.1.0` mit den Modulpaketen erstellt. Existierende Releases werden nicht überschrieben; Modul-Releases ersetzen nicht den neuesten Windows-Release. Vollständige Browser- und Windows-Abnahme erfolgen bei der Integration in das Anwendungspaket.

## Integration und Oberfläche

Administration → Module zeigt die tatsächlichen Modulversionen und bietet einen JSON-Export des installierten Versionsstands. Das Einspielen läuft weiterhin über ein gebautes, geprüftes Anwendungspaket. Keine PHP-Dateien im laufenden Webverzeichnis ersetzen.

Für eine neue Modulversion: PHP-/UI-Version ändern, abhängige exakte Paketanforderungen und die Anwendungsanforderungen anpassen, Composer-/npm-Lockdateien mit den jeweiligen Paketmanagern aktualisieren, Kompatibilitätsprüfung und Build ausführen. Ein inkompatibler Versionsmix stoppt vor der Auslieferung.

## Weiter offen gemäß Konzept 3.2, 3.3 und 6.3

- Tatsächliche Trennung in eigene Modul-Repositories und Einrichtung privater Composer-/npm-Registries. Die Archive liefern die Paketgrenzen; die Repositories und Registries sind damit noch nicht eingerichtet.
- Bezug veröffentlichter Module aus diesen Registries statt lokaler Workspace-Pakete.
- Geschützter Auswahl-, Vorschau- und Freigabeablauf für Modulversionen in der Verwaltung, der einen geprüften Anwendungsbuild auslöst.
- Eigenständiger Modulrollback mit passender Datenmigration. Der vorhandene Anwendungsrollback bleibt maßgeblich.

Unterschiedliche Modulversionen je Restaurant und Laufzeit-Nachladen sind weiterhin nicht Bestandteil der initialen Architektur. Version 0.1.0 bleibt unverändert veröffentlicht.
