# Design-Abgleich – 8. September 2026

## Nachprüfung vom 10. September 2026

Das Inventar wurde mit der unveränderten Referenz erneut erzeugt: **183 Definitionen**, Referenz-Hash siehe unten. Die veralteten Codebefunde für Mehrfachumzüge (B063–B065), Wetterzuordnung (B085) und den ausdrücklich gewünschten Odoo-Platzhalter (B172–B174) wurden korrigiert. Der Wetterabruf läuft jetzt auch ohne geöffneten Bildschirm über den Scheduler; siehe [Wetterautomatik](WETTERAUTOMATIK.md).

**Die vollständige visuelle Einzelabnahme ist nicht bestanden.** Der in dieser Bearbeitung verfügbare Browser blockiert den lokalen Anwendungszugriff (`ERR_BLOCKED_BY_CLIENT`). Deshalb wurden keine zusätzlichen Buttons ohne Sichtprüfung als abgenommen markiert. Bestehende Desktopbefunde bleiben erhalten; offene mobile und andere Zustände bleiben offen. GitHub-Browsertests prüfen die Anwendung mit simulierten API-Antworten und erzeugen Aufnahmen, ersetzen aber ohne Einzelzuordnung und Sichtprüfung keine 183-fache Abnahme.

Referenz: `design-template-main.zip` aus dem Projekt Platzhirschv2 (erneut bereitgestellt am 8. September). Die Admin-Vorlage `Platzhirsch Admin.dc.html` hat SHA-256 `eb67fafaae41861c29388a08eb7e635de89586954cdee1038faa8f5fdbae6768`. Referenzabschnitte: Navigation (ab Zeile 54), Einstellungen (ab 1762), Rollen (ab 2001).

## Dieser Umsetzungsschritt

| Vorlage | Umsetzung |
|---|---|
| Einzelne schmale Karten für Verhalten, Favoriten und Export-Formate | Separate Karten, maximal 640 px breit, flache Flächen und rechts ausgerichtete Schalter |
| Favoriten oberhalb von „Weitere“, übrige Punkte darunter | Aufklapper steht jetzt zwischen beiden Gruppen; mobile und eingeklappte Navigation bleiben bedienbar |
| Export-Formate ein-/ausblenden | CSV-Angebot und gesamte Exportanzeige separat pro Konto/Portal/Browser einstellbar; Reservierungsansicht verwendet diese Einstellungen |
| Gruppenschalter an/aus/teilweise | Einheitlicher Schalter, Zwischenposition und zugänglicher Dreizustand für Berechtigungsgruppen |
| Restaurantrollen im selben visuellen Schema | Gruppenkacheln mit Zähler und Schalter, Suche nach Name/Code, einheitliche Berechtigungszeilen und Rollenreiter |
| Modulbezogene Berechtigungsgruppen | Auswahl verwendet Modul plus Gruppencode, damit gleichnamige Gruppen verschiedener Module nicht verwechselt werden |
| Kartenfarbe | Gemeinsamer Surface-Token entspricht `oklch(0.235 0.005 260)` der Vorlage |

Die gemeinsame Schalter-Komponente liegt im npm-Paket `@platzhirsch/ui-runtime`. Fachansichten bleiben in ihren Modulpaketen. Datenbank- und Autorisierungsregeln werden durch diese Darstellung nicht ersetzt. Geschützte Plattformrollen bleiben unveränderbar. Restaurantrechte werden beim Speichern unmittelbar aktiviert; Plattformrechte durchlaufen weiterhin Entwurf, Prüfung und Aktivierung.

## Bewusst noch offen

- Exportstand aktualisiert: CSV, echtes XLSX und Browser-Drucken/PDF sind umgesetzt. Ein direkter serverseitiger PDF-Download und Exporte aller Übersichtsseiten fehlen weiterhin.
- Rechte dürfen nur aus registrierten Modulfunktionen stammen. Freie technische Berechtigungscodes und ein bloß simulierter mandantenübergreifender Rollout werden nicht übernommen.
- Vollständige Abnahme sämtlicher 183 Button-Definitionen, aller Detaildialoge, Buchungs-/Tischansichten, Loginoptionen und Systemeinstellungsreiter steht weiter aus.
- Diese Änderungen betreffen die Windows-/Laravel-Anwendung im GitHub-Projekt. Die separat veröffentlichte Sites-Designvorschau ist eine Referenz und wird hier nicht ersetzt.

## Prüfung

Frontend-Build und TypeScript lokal erfolgreich. Browserprüfungen kontrollieren Favoritenreihenfolge, Erhalt der Einstellungen, portalgetrennte Speicherung, Exportberechtigung und Zwischenzustand der Rechtegruppen; Screenshots werden im CI-Artefakt `role-design-preview` abgelegt. Der tatsächliche CI-Status ist im zugehörigen Pull Request sichtbar.

## Reservierungen: Liste und Woche

Der nächste Schritt übernimmt die Suche nach Gast/Tisch, sichtbare Notizen, Tagesnavigation und den Umschalter Liste/Woche aus der Vorlage (ab Zeile 1233). Die Suche berücksichtigt zusätzlich Kontakt und Notizen; ein Statusfilter lässt sich damit kombinieren und gemeinsam zurücksetzen. Der CSV-Export bleibt ausdrücklich ein Export des gewählten Tages ohne diese Anzeigefilter.

Die Wochenansicht zeigt Montag bis Sonntag mit echten Tagesabfragen, Uhrzeit in der Restaurant-Zeitzone, Gast, Personen, Tisch, Status und Notiz. Der Tageskopf öffnet die jeweilige Liste. Mit Schreibrecht öffnet eine Buchung den vorhandenen Bearbeitungsdialog samt Versionsprüfung; lesende Rollen erhalten keine Bearbeitungsaktion. Ladefehler werden pro Tag angezeigt, statt leere Tage vorzutäuschen. Kalenderarithmetik verwendet UTC-Kalendertage, unabhängig von der Sommerzeit des Browsers. Mobile Geräte zeigen die Tage untereinander.

Kalender-Popup und Tisch-Zeitstrahl wurden anschließend umgesetzt (siehe nächster Abschnitt); die vollständige Abnahme aller Buchungsaktionen bleibt offen. Die vorhandenen Modulpakete und API-Berechtigungen bleiben maßgeblich.

## Kalender und Tisch-Zeitstrahl

Der Monatskalender übernimmt das Raster Montag–Sonntag, Monatswechsel, Tagesauswahl und Heute aus der Vorlage. Er markiert den gewählten Tag und den heutigen Tag, schließt bei Escape/Außenklick und gibt nach Auswahl den Fokus zurück. Die direkte Datumseingabe bleibt verfügbar.

Der Tischplan bietet Kacheln/Zeitstrahl und einen gemeinsamen Raumfilter. Im Zeitstrahl stehen Tischname und Plätze links, die Buchungen auf einer 24-Stunden-Achse rechts. Zeiten folgen der Restaurant-Zeitzone; Tage der Zeitumstellung werden mit ihrer tatsächlichen Länge dargestellt. Buchungen vom Vortag werden mitgeladen und am Tagesrand abgeschnitten. Bestätigte, platzierte und abgeschlossene Reservierungen sind sichtbar, stornierte und nicht erschienene ausgeblendet. Überlagerungen erhalten getrennte Zeilen. Buchungen öffnen nur mit Schreibrecht den vorhandenen Bearbeitungsdialog. Auf schmalen Bildschirmen scrollt ausschließlich der Zeitstrahl horizontal.

Die neuen Browsertests prüfen Schaltjahr, Jahreswechsel, Fokus und Schließen, Raumfilter, über Mitternacht laufende Buchungen sowie Nur-Lese-Zugriff und Ladefehler. Der grafische Tischplan mit gespeicherten Positionen bleibt erhalten.

## Restaurantbetrieb und nachvollziehbare Einzelabnahme

`DESIGN-BUTTON-INVENTAR.csv` erfasst die tatsächlichen Button-Definitionen der Referenz mit Zeile, sichtbarer Beschriftung, Aktionsbindung und umgebenden Einblendbedingungen. `tools/design/inventory.py` erzeugt sie reproduzierbar. Eine erfasste Aktion ist keine bestandene Abnahme: die visuelle Abnahmespalte bleibt bis zur tatsächlichen Einzelprüfung ausdrücklich offen; der separate Codeabgleich enthält jetzt für alle 183 Buttons Umsetzungspfad und Restabweichung. Vorhandene Screenshot-Tests ersetzen nicht die Einzelprüfung aller Vorlagefunktionen.

Neu umgesetzt: Warteliste mit eigenem Lese-/Schreibrecht, Statusfilter, Kontaktdaten, Notizen und konfliktgeprüfter Übernahme; zusätzliche Tische pro Buchung im selben Raum; Öffnungszeiten über Mitternacht mit Sondertagsvorrang. Auch Kacheln berücksichtigen hineinreichende Reservierungen. XLSX und Drucken/PDF ergänzen CSV samt getrennten Anzeigeeinstellungen. Die PDF-Funktion verwendet den Browser-Druckdialog und ist kein serverseitiger PDF-Renderer.

Buchungsnachrichten besitzen eine eigene Konfigurations- und Statusansicht. Sie folgen dem vorhandenen Karten-/Formularstil; die Warteliste ergänzt die Vorlage um einen ausführbaren Restaurantablauf. Eine visuelle 1:1-Vollabnahme bleibt ausstehend.

## Vollständiger Codeabgleich der 183 Button-Definitionen

Alle 183 Definitionen wurden ihren aktuellen Ansichten oder einem konkreten Fehlpunkt zugeordnet. Die gepflegte Quelle ist `tools/design/assessment.json`; `inventory.py` übernimmt die Bewertung ausschließlich für den geprüften Referenz-Hash und lehnt andere Vorlagen ab. CSV-Spalten trennen Vorlagen-Bedingungen, Codebefund, Quelldatei, Restabweichung und weiterhin offene visuelle Einzelabnahme. „Funktion vorhanden“ ist keine Aussage über pixelgleiche Gestaltung oder alle Dialogzustände.

Die Ausbaustufe vom 9. September ergänzt Sitzungsanzeige, Profilmenü mit Akzentfarben und Bearbeitung, Raumsperren, interaktive Widget-Beispielbuchung und vollständige Mandantenseiten einschließlich abhängiger Auswahlfelder. Geprüfte Desktopzustände werden im Inventar einzeln markiert. Weiter offen bleiben unter anderem Release-/Changelog-Verwaltung, Odoo, Audit-Tabs, Health-Backupreiter und die vollständige visuelle Einzelabnahme aller 183 Definitionen. [Bedienung und Grenzen](DESIGN-UND-RESTAURANT-AUSBAU.md).

Buchungsdialog: Walk-in setzt die aktuelle Restaurantzeit und den Status Eingetroffen; der vorhandene Monatskalender und eine Viertelstunden-Auswahl ergänzen den frei eingebbaren Beginn. Zeitslots sind keine Verfügbarkeitsanzeige. Die Konflikt- und Öffnungszeitenprüfung bleibt serverseitig. Im Modul-Katalog wurden veraltete Behauptungen zu fehlendem Kauf/Modulpaketen korrigiert.

Auswertungen unterscheiden jetzt Buchungen, Gäste ohne Storno/No-show, Stornierungen, nicht erschienene Buchungen und eingetroffene/abgeschlossene Buchungen. Der Zeitraum richtet sich weiterhin nach dem lokalen Startdatum; eine Mitternachtsbuchung zählt einmal am Starttag. Es handelt sich um Betriebskennzahlen, nicht um Umsatz- oder Kassenauswertungen.
