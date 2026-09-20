# Verbindlicher Arbeitsstand und Restpunkte

Stand: 20. September 2026. Diese Datei ersetzt ältere offene Listen in Konzepten und datierten Prüfprotokollen. Ein implementierter Arbeitsstand ist erst nach grüner CI und Merge ein freigegebener Stand; maßgeblich bleiben Commit und Prüfprotokoll des konkreten Pakets.

## In dieser Ausbaustufe umgesetzt

- API-Feldverträge je Operation mit Eingaben, Pflichtfeldern, Antwortformen, Beispielen und Fehlern; OpenAPI und MCP lesen denselben Modulvertrag. Kompatibilitätsprüfung gegen den vorherigen Git-Stand, Schema-/Beispielprüfung und Erkennung geänderter Controller.
- Technische Dienstidentitäten ohne interaktiven Login, einem verantwortlichen Administrator und Mandanten zugeordnet. Rechte sind die Schnittmenge aus aktuellen Fachrechten, Modulscope, Konto-Aktionen und Token-Aktionen. Sperren gelten sofort.
- Tokenrotation in der Oberfläche: gleiche Begrenzungen, einmalige Ausgabe des Ersatzes, sofortige Sperre oder maximal 24 Stunden Übergangszeit; höchstens 90 Tage Gültigkeit.
- Nachrichten: Historie pro Versandversuch, signierte Twilio-Zustellrückmeldungen und begrenzte, ausdrücklich bestätigte Wiederholung. SMTP-Annahme bleibt von bestätigter Zustellung getrennt.
- Kunden-, Support- und Serverübersichten als CSV/XLSX/Server-PDF. Große CSV-Exporte dieser Übersichten laufen über die dauerhafte Queue, mit erneuter Berechtigungsprüfung und privatem, 24 Stunden gültigem Download.

Bereits vorher umgesetzt: SMTP-Verwaltung, Registrierungs-Landingpage mit Domain-/Branchenprüfung und E-Mail-Verifikation; manuelle und automatische Abrechnung mit Stripe, Rechnungs-/Mahnungsversand; Wetterautomatik; getrennte Modul-Repositories und private Paketquelle; Windows-Update, Sicherung und Wiederherstellung. Siehe die unten verlinkten Betriebsanleitungen.

## Weiterhin offen / außerhalb dieser Ausbaustufe

| Priorität | Punkt | Konkrete Grenze |
| --- | --- | --- |
| Vor produktiver Nutzung | Betreiber-Abnahme externer Dienste | Echte Absenderdomain/SMTP-Zustellung, Stripe-Livekonto, SMS-Statuscallback über öffentliches HTTPS sowie eigener OIDC-Anbieter müssen mit Betreiberzugängen geprüft werden. CI ersetzt diese Abnahme nicht. |
| Vor produktiver Nutzung | Betriebsabnahme | Reale Serverneustarts, eigene Zertifikatskette/Netzwerkregeln, Sicherungsziele und Wiederherstellungsprobe sowie Last-/Mengentests auf der Zielumgebung. |
| Nächster Integrationsausbau | MCP über HTTP/OAuth | Vorhanden ist stdio-MCP. Ein zentraler Remote-MCP-Dienst mit OAuth und Clientverwaltung ist noch nicht implementiert. |
| Paketstruktur | Eigene API-/MCP-Repositories | Die Pakete sind eigenständig, werden derzeit aber aus dem Module-Host-Repository veröffentlicht. Eine zusätzliche Repository-Aufteilung ist noch offen. |
| Abrechnung | Erweiterte Fachfälle | Rückerstattungen als Zahlungsworkflow, strukturierte E-Rechnungen, grenzüberschreitende Steuerfälle, eigene korrigierte Neuausstellung und Testphasenpolitik. Ein Stornobeleg erstattet kein Geld. |
| Exporte | Weitere große Auswertungen | Hintergrundexport derzeit für Kunden, Support und Server als CSV. Große XLSX/PDF-Aufträge sowie asynchrone Rechnungsbestände sind noch nicht enthalten. Die bisherigen festen Grenzen verhindern stille Kürzung. |
| Nachrichten | E-Mail-Zustellereignisse | Generisches SMTP liefert eine Annahmebestätigung. Provider-spezifische Bounce-/Delivery-Webhooks für E-Mail und Lesebestätigungen sind nicht implementiert. |
| Weitere Abnahme | Barrierefreiheit und Browser | Durchgängige Tastatur-/Screenreader-Abnahme und weitere Browser bleiben eigene Prüfpunkte. |

Odoo bleibt ausdrücklich ein Platzhalter. Die 183 Vorlagen-Buttons sind vom Auftraggeber vorläufig abgenommen und kein offener Arbeitsblock.

## Nachweise und Anleitungen

- [API-Betrieb](API-BETRIEB.md), [API-Abdeckung](API-ABDECKUNG.md), [API-Verträge](api/VERTRAEGE.md)
- [Nachrichten](NACHRICHTEN.md), [Exporte](EXPORTE.md), [Abrechnung](ABRECHNUNG.md)
- [Registrierung](REGISTRIERUNG.md), [Modul-Updates](MODUL-UPDATES.md), [Wiederherstellung](UPDATES-UND-WIEDERHERSTELLUNG.md)
- [Letzter zuvor freigegebener Stand: PR #4](https://github.com/Kabutori/Platzhirschv2/pull/4), [Anwendungsprüfung](https://github.com/Kabutori/Platzhirschv2/actions/runs/35312586997), [Windows-Prüfung](https://github.com/Kabutori/Platzhirschv2/actions/runs/35312586960).
