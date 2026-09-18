# Server-PDF und Übersichtsexporte

Die nachfolgenden Downloads sind implementierte Sitzungs-Endpunkte. Sie benötigen die Anmeldung im jeweiligen Portal; allgemeine API-Tokens/MCP sind im [API-Konzept](api/KONZEPT.md) beschrieben.

| Übersicht                         | Download                                                                                        | Rechte und Filter                                                                                       |
| --------------------------------- | ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Reservierungen                    | `/api/v1/restaurant/export?date=YYYY-MM-DD&format=pdf` (auch csv, xlsx, print)                  | `reservation.read` und `reservation.export`, Restaurantkontext, ausgewählter Tag und Restaurantzeitzone |
| Auswertungen                      | `/api/v1/restaurant/reporting/export?from=YYYY-MM-DD&to=YYYY-MM-DD&format=pdf` (auch csv, xlsx) | `reporting.read` plus aktives Reporting-Entitlement, höchstens 93 Tage                                  |
| Rechnungsübersicht                | `/api/v1/restaurant/billing/export?format=pdf` (auch csv, xlsx)                                 | Restaurantadmin, nur eigene ausgestellte Belege                                                         |
| Administrative Rechnungsübersicht | `/api/v1/admin/billing/export?format=pdf` (auch csv, xlsx)                                      | Systemadmin, einschließlich Entwürfen                                                                   |
| Einzelbeleg                       | `/api/v1/{restaurant                                                                            | admin}/billing/documents/{id}/pdf`                                                                      | identische Rechte und gespeicherter Belegstand wie Druckansicht |

Downloadschaltflächen stehen direkt in den Übersichten. PDF ist ein echter serverseitiger Download; Browserdruck bleibt getrennt verfügbar. Der PDF-Anhang im Rechnungs-/Mahnungsversand verwendet denselben Renderer und Belegstand.

## Betrieb

Dompdf wird über Composer im Modul `module-host` installiert. Erforderlich sind PHP DOM/MBString, für XLSX zusätzlich ZIP. Es wird kein Chrome-, Node- oder externer PDF-Dienst auf dem Server benötigt. `storage/framework/cache` muss für temporäre XLSX-Dateien beschreibbar sein. Dompdf verwendet seine eingebauten DejaVu-Schriften für Umlaute. PDF-Remotezugriff, eingebettetes PHP und JavaScript sind deaktiviert; HTML stammt ausschließlich aus serverseitig escaped Templates.

Antworten enthalten `Cache-Control: private, no-store`, feste MIME-Typen und bereinigte Downloadnamen. CSV-Felder mit möglichem Formelausdruck werden neutralisiert, XLSX verwendet Inline-Strings. Lange PDF-Zellen werden in Fortsetzungszeilen aufgeteilt. Tabellenköpfe wiederholen sich, Seiten tragen eine Seitennummer.

Synchrone Tabellenexporte sind auf 10.000 Datensätze, PDF-Übersichten auf 500 Datensätze begrenzt. Überschreitung ergibt 422 und keine still gekürzte Datei. Rechnungsübersichten exportieren sämtliche für den angemeldeten Nutzer sichtbaren Belege, nicht nur die gerade angezeigte Seite. Große Rechnungsbestände über dieser Grenze benötigen in einem folgenden Ausbau einen gefilterten oder asynchronen Export. Auswertungen und Reservierungen lassen sich über den Zeitraum eingrenzen.

Weitere Bereiche wie Support, Kundenstamm und Serverinventar haben in diesem Stand keine neuen Exportwege. Der gemeinsame Renderer kann in deren Fachmodulen wiederverwendet werden, ohne Tabellenrechte im Kern zu zentralisieren.
