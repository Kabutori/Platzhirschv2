# Wetterautomatik

Die optionale Wettererweiterung liefert sieben Tage Vorhersage für als wetterabhängig markierte Räume. Im Restaurantportal unter **Räume → Wetter einstellen** werden Standortkoordinaten, Anbieternutzung und Warnschwelle gespeichert. Das Wettermodul muss zuvor freigeschaltet und aktiviert sein.

## Betrieb ohne geöffneten Browser

Der bereits installierte Windows-Scheduler ruft Laravel regelmäßig auf. `weather:refresh` läuft alle fünf Minuten und prüft aktive Restaurants mit Wettermodul. Frische Vorhersagen werden 30 Minuten gemeinsam für Hintergrundprozess und Weboberfläche zwischengespeichert. Erst danach erfolgt ein neuer Anbieterabruf. Überlappende Schedulerläufe werden verhindert.

Bei Anbieterfehlern bleibt eine vorhandene Vorhersage bis zu sechs Stunden als **veraltet** sichtbar; ohne Cache erscheint **nicht verfügbar**. Der nächste Abrufversuch wird frühestens nach fünf Minuten zugelassen. Ein Tageswechsel verwendet einen neuen Cache, sodass gestrige Tage nicht als aktuelle Vorschau erscheinen. Änderungen am Standort oder an den Einstellungen verwenden ebenfalls einen neuen Cache.

Ein Restaurantfehler verhindert die Prüfung weiterer Restaurants nicht. Datenbankzuordnung und Mandantensperren laufen über den vorhandenen TenantRuntime-Adapter. Deaktivierte Module werden vor Öffnen ihrer Datenbank übersprungen und beim tatsächlichen Abruf erneut geprüft. Der Kommandoausgang nennt nur zusammengefasste Zahlen; Anbieterfehler und Zugangsdaten werden nicht ausgegeben.

Die Weboberfläche fragt den gemeinsamen Stand bei geöffnetem Bildschirm jede Minute ab. Für den Hintergrundbetrieb muss die installierte Scheduleraufgabe aktiv sein. Ein einmaliger lokaler Funktionstest ist `php artisan weather:refresh` im Verzeichnis `app`; Exitcode 1 bedeutet mindestens einen Fehler oder fehlende aktuelle Anbieterinformationen.

## Warnungen und Grenzen

Die Warnung gilt ab der eingestellten Regenwahrscheinlichkeit oder bei einem Niederschlags-/Gewittercode. Sie ist eine Planungshilfe aus Tageswerten. Es werden keine Raumsperren erstellt, Buchungen verändert oder Gäste benachrichtigt.

Der vorhandene gewerbliche Modus verwendet den serverseitig konfigurierten Open-Meteo-Schlüssel (`WEATHER_API_KEY`); der Auswertungsmodus ist für nichtgewerbliche Tests vorgesehen. Es wurden keine echten Restaurantkoordinaten oder Anbieterzugänge eingerichtet.

## Prüfungen

Frontend-Build und TypeScript-Prüfung; PHP-Regressionstests für Hintergrundabruf und gemeinsamen Cache, Fehlerisolation, übersprungene Module sowie Schedulerregistrierung mit Überlappungsschutz. Die bestehenden Browserprüfungen decken Einstellungen, Warnung und veraltete Daten auf Desktop und Mobil ab. Laufzeitergebnisse sind dem GitHub-Prüflauf des jeweiligen Commits zu entnehmen; das Vorhandensein eines Tests ist kein bestandener Prüflauf.
