# Design-Abgleich – 8. September 2026

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

- Die Vorlage bietet weitere Exportformate. Tatsächlich implementiert ist CSV; PDF/XLSX werden nicht als funktionsfähige Optionen ausgegeben.
- Rechte dürfen nur aus registrierten Modulfunktionen stammen. Freie technische Berechtigungscodes und ein bloß simulierter mandantenübergreifender Rollout werden nicht übernommen.
- Vollständige Abnahme sämtlicher 183 Button-Definitionen, aller Detaildialoge, Buchungs-/Tischansichten, Loginoptionen und Systemeinstellungsreiter steht weiter aus.
- Diese Änderungen betreffen die Windows-/Laravel-Anwendung im GitHub-Projekt. Die separat veröffentlichte Sites-Designvorschau ist eine Referenz und wird hier nicht ersetzt.

## Prüfung

Frontend-Build und TypeScript lokal erfolgreich. Browserprüfungen kontrollieren Favoritenreihenfolge, Erhalt der Einstellungen, portalgetrennte Speicherung, Exportberechtigung und Zwischenzustand der Rechtegruppen; Screenshots werden im CI-Artefakt `role-design-preview` abgelegt. Der tatsächliche CI-Status ist im zugehörigen Pull Request sichtbar.

## Reservierungen: Liste und Woche

Der nächste Schritt übernimmt die Suche nach Gast/Tisch, sichtbare Notizen, Tagesnavigation und den Umschalter Liste/Woche aus der Vorlage (ab Zeile 1233). Die Suche berücksichtigt zusätzlich Kontakt und Notizen; ein Statusfilter lässt sich damit kombinieren und gemeinsam zurücksetzen. Der CSV-Export bleibt ausdrücklich ein Export des gewählten Tages ohne diese Anzeigefilter.

Die Wochenansicht zeigt Montag bis Sonntag mit echten Tagesabfragen, Uhrzeit in der Restaurant-Zeitzone, Gast, Personen, Tisch, Status und Notiz. Der Tageskopf öffnet die jeweilige Liste. Mit Schreibrecht öffnet eine Buchung den vorhandenen Bearbeitungsdialog samt Versionsprüfung; lesende Rollen erhalten keine Bearbeitungsaktion. Ladefehler werden pro Tag angezeigt, statt leere Tage vorzutäuschen. Kalenderarithmetik verwendet UTC-Kalendertage, unabhängig von der Sommerzeit des Browsers. Mobile Geräte zeigen die Tage untereinander.

Noch offen bleiben unter anderem der eigene Kalender-Popup-Entwurf, der Tisch-Zeitstrahl und die vollständige Abnahme aller Buchungsaktionen. Die vorhandenen Modulpakete und API-Berechtigungen bleiben maßgeblich.
