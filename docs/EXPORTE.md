# Server-PDF und Übersichtsexporte

Die Downloads sind über angemeldete Portale und über die gesicherte externe Modul-API verfügbar. Externe Pfade beginnen mit `/api/external/v1/{modul}/` statt `/api/v1/`; siehe [API-Betrieb](API-BETRIEB.md).

| Übersicht                         | Download                                                                                        | Rechte und Filter                                                                                       |
| --------------------------------- | ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Reservierungen                    | `/api/v1/restaurant/export?date=YYYY-MM-DD&format=pdf` (auch csv, xlsx, print)                  | `reservation.read` und `reservation.export`, Restaurantkontext, ausgewählter Tag und Restaurantzeitzone |
| Auswertungen                      | `/api/v1/restaurant/reporting/export?from=YYYY-MM-DD&to=YYYY-MM-DD&format=pdf` (auch csv, xlsx) | `reporting.read` plus aktives Reporting-Entitlement, höchstens 93 Tage                                  |
| Rechnungsübersicht                | `/api/v1/restaurant/billing/export?format=pdf` (auch csv, xlsx)                                 | Restaurantadmin, nur eigene ausgestellte Belege                                                         |
| Administrative Rechnungsübersicht | `/api/v1/admin/billing/export?format=pdf` (auch csv, xlsx)                                      | Systemadmin, einschließlich Entwürfen                                                                   |
| Einzelbeleg | `/api/v1/admin/billing/documents/{id}/pdf` bzw. `/api/v1/restaurant/billing/documents/{id}/pdf` | Identische Rechte und gespeicherter Belegstand wie Druckansicht |
| Kundenstamm | `/api/v1/admin/customer-exports?format=pdf` (auch csv, xlsx) | Plattformzugang; alle Restaurants, keine Datenbankzugangsdaten |
| Supportübersicht | `/api/v1/support/exports?format=pdf` (auch csv, xlsx) | `support.access`; Restaurant sieht nur eigene Tickets, keine internen Nachrichtentexte |
| Serverinventar | `/api/v1/admin/server-exports?format=pdf` (auch csv, xlsx) | `provisioning.servers.read`; explizite Metadatenspalten ohne SQL-Benutzer/Kennwörter |

Downloadschaltflächen stehen direkt in den Übersichten. PDF ist ein echter serverseitiger Download; Browserdruck bleibt getrennt verfügbar. Der PDF-Anhang im Rechnungs-/Mahnungsversand verwendet denselben Renderer und Belegstand.

## Betrieb

Dompdf wird über Composer im Modul `module-host` installiert. Erforderlich sind PHP DOM/MBString, für XLSX zusätzlich ZIP. Es wird kein Chrome-, Node- oder externer PDF-Dienst auf dem Server benötigt. `storage/framework/cache` muss für temporäre XLSX-Dateien beschreibbar sein. Dompdf verwendet seine eingebauten DejaVu-Schriften für Umlaute. PDF-Remotezugriff, eingebettetes PHP und JavaScript sind deaktiviert; HTML stammt ausschließlich aus serverseitig escaped Templates.

Antworten enthalten `Cache-Control: private, no-store`, feste MIME-Typen und bereinigte Downloadnamen. CSV-Felder mit möglichem Formelausdruck werden neutralisiert, XLSX verwendet Inline-Strings. Lange PDF-Zellen werden in Fortsetzungszeilen aufgeteilt. Tabellenköpfe wiederholen sich, Seiten tragen eine Seitennummer.

Synchrone Tabellenexporte sind auf 10.000 Datensätze, PDF-Übersichten auf 500 Datensätze begrenzt. Überschreitung ergibt 422 und keine still gekürzte Datei. Rechnungsübersichten exportieren sämtliche für den angemeldeten Nutzer sichtbaren Belege, nicht nur die gerade angezeigte Seite. Große Rechnungsbestände über dieser Grenze benötigen in einem folgenden Ausbau einen gefilterten oder asynchronen Export. Auswertungen und Reservierungen lassen sich über den Zeitraum eingrenzen.

## Große CSV-Exporte

In Kunden-, Support- und Serverübersichten startet „Großen CSV-Export im Hintergrund erstellen“ einen dauerhaften Datenbank-Queueauftrag. `POST` auf denselben Übersichtsexportpfad mit `{"format":"csv"}` liefert 202 mit Auftrag-ID. `GET /{id}` liefert Status; `GET /{id}/download` die fertige Datei. Jede Operation besitzt einen eigenen API-Vertrag und Modulscope; MCP-Schreibaktionen benötigen wie üblich eine Portalbestätigung.

Jedes Fachmodul liefert eine feste Spaltenauswahl und seine Berechtigungs-/Mandantenprüfung. Auftraggeber, Mandant und gegebenenfalls Ursprungstoken werden vor Ausführung und Download erneut geprüft. Widerrufene Zugänge erzeugen keine zugängliche Datei. Zwischendateien liegen privat und werden erst nach vollständigem Schreiben veröffentlicht. Fehler erzeugen keinen Teildownload. Es gibt höchstens 20 Aufträge je Nutzer in 24 Stunden und eine Million Zeilen pro CSV. Eingefügte neue IDs nach Beginn werden ausgeschlossen; parallele Änderungen vorhandener Zeilen sind kein transaktionaler Gesamtsnapshot.

Downloads laufen nach 24 Stunden ab. Der stündliche Scheduler entfernt abgelaufene Dateien; Metadaten werden sieben Tage nach Ablauf gelöscht. Worker und Scheduler müssen laufen. Große XLSX/PDF-Aufträge und asynchrone Rechnungsbestände bleiben ein weiterer Ausbau; aktuelle Grenzen stehen in [RESTPUNKTE.md](RESTPUNKTE.md).
