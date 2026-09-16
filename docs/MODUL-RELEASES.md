# Eigenständige Modulpakete

Aktualisierung: Alle 18 Einzel-Repositories sind befüllt. Die kontrollierte Übernahme ihrer festen Commits ist in [EINZEL-REPOSITORIES.md](EINZEL-REPOSITORIES.md) beschrieben.

Die Modulversion wird jetzt aus der jeweiligen `composer.json` gelesen. Ein Modulupdate erfordert keine Änderung der Versionsnummer des Module Host. Die Versionsangaben in PHP und UI müssen übereinstimmen; andere Module und der Kern behalten ihre eigenen Versionen. Aktuelle Abhängigkeiten sind weiterhin exakt festgelegt: ein inkompatibler oder nicht angepasster Paketstand wird abgelehnt.

## Paketbau

`python tools/modules/packages.py --check` prüft alle lokalen PHP-/npm-Pakete einschließlich der von der Anwendung angeforderten Versionen. `python tools/modules/packages.py --module reporting --output dist/reporting` erstellt ausschließlich die PHP-/UI-Archive dieses Moduls, `modules.json` mit Quellcommit und Abhängigkeiten sowie `SHA256SUMS.txt`. Ohne `--module` werden alle Pakete gebaut. Ein bestehendes Ausgabeziel wird nicht überschrieben.

Die Archive enthalten nur versionierte Quelldateien aus den expliziten Paketverzeichnissen. Keine unversionierten Arbeitsdateien, Schlüssel oder Laufzeitverzeichnisse. PHP-ZIPs besitzen `composer.json` an der Wurzel; npm-Tarballs enthalten den Standardordner `package/`. Archivzeitstempel sind festgelegt, damit gleiche Inhalte dieselben Prüfsummen erhalten. Lokale Builds können versionierte, noch nicht eingecheckte Änderungen enthalten; die Veröffentlichung verwendet ausschließlich das CI-Checkout.

GitHub Actions → **Build and publish one module**: Modulverzeichnis und bereits eingecheckte Version angeben. Ohne „publish“ entsteht nur ein Download-Artefakt. Mit „publish“ auf `main` wird nach Backend-Prüfungen und Anwendungsbuild ein eigener Release `module-reporting-v0.1.0` mit den Modulpaketen erstellt. Existierende Releases werden nicht überschrieben; Modul-Releases ersetzen nicht den neuesten Windows-Release. Vollständige Browser- und Windows-Abnahme erfolgen bei der Integration in das Anwendungspaket.

## Integration und Oberfläche

Administration → Module zeigt die tatsächlichen Modulversionen und bietet einen JSON-Export des installierten Versionsstands. Das Einspielen läuft weiterhin über ein gebautes, geprüftes Anwendungspaket. Keine PHP-Dateien im laufenden Webverzeichnis ersetzen.

Für eine neue Modulversion: PHP-/UI-Version ändern, abhängige exakte Paketanforderungen und die Anwendungsanforderungen anpassen, Composer-/npm-Lockdateien mit den jeweiligen Paketmanagern aktualisieren, Kompatibilitätsprüfung und Build ausführen. Ein inkompatibler Versionsmix stoppt vor der Auslieferung.

## Administration und private Paketquelle

Die private Composer-/npm-Quelle, Versionsauswahl, Kompatibilitätsvorschau, passwortgeschützte Buildfreigabe und Bereitstellung geprüfter Windows-Pakete sind implementiert. Einrichtung und Ablauf: [MODUL-UPDATES.md](MODUL-UPDATES.md).

Der Gesamtrollback bleibt maßgeblich: Code und zugehörige Datenbanken werden als gemeinsam geprüfter Stand zurückgesetzt. Unterschiedliche Modulversionen je Restaurant und Laufzeit-Nachladen sind nicht Bestandteil der initialen Architektur.
