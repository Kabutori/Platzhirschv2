# Prüfstand: Exporte, SMTP, Registrierung und Abrechnung

Dieser Stand ergänzt die GitHub-Anwendung. Er ist über Draft-PRs zur Prüfung veröffentlicht, nicht automatisch auf `main` gemergt oder in Sites/auf einem Produktivserver ausgerollt. Die 15 zugehörigen Modul-PRs und Commit-Pins stehen in [MODULE-RELEASE-0.1.1.json](MODULE-RELEASE-0.1.1.json).

## Implementiert

- Admin-Oberfläche: SMTP-Verbindung prüfen, bewusster Testversand an das eigene Administratorkonto, Registrierung aktivieren und Datenschutz-/Impressumslinks konfigurieren. Die Registrierungsbereitschaft wird nach SMTP-Änderungen neu geladen.
- Registrierung mit Firmenwebsite, passender E-Mail-Domain, vorhandener Website-/Keywordprüfung und einmaligem Bestätigungslink. Kein Konto vor Bestätigung; SMTP-Fehler hinterlassen keinen unbrauchbaren Registrierungsantrag.
- Abrechnungsautomatik: Stripe-Checkout für monatliche Abonnements, signierte Webhooks, Abgleich von Rechnungen und Zahlungen, Kündigung zum Laufzeitende, Rechnungsversand und bis zu drei Zahlungserinnerungen. Anbieter, Queue und Scheduler müssen eingerichtet werden. Automatik ist zunächst deaktiviert.
- Testzahlungen verbrauchen einen getrennten TEST-Nummernkreis und gewähren keine echten Modulberechtigungen. Unklare SMTP-Zustellung wird zur manuellen Prüfung markiert; kein blindes automatisches Doppelversenden.
- [Server-PDFs und Übersichtsexporte](EXPORTE.md) für Reservierungen, Reporting und Rechnungen; Rechnungs-/Mahnungs-E-Mails mit PDF-Anhang. Mandanten- und Berechtigungsprüfung bleiben im jeweiligen Fachmodul.
- [API-/Token-/MCP-Konzept](api/KONZEPT.md), Modulmanifest- und OpenAPI-Beispiel unter Berücksichtigung der offiziellen Odoo-JSON-2-Dokumentation. Diese externe Token-/MCP-Laufzeit ist noch nicht implementiert.

## Durchgeführte Prüfungen

| Prüfung | Ergebnis |
| --- | --- |
| PHP-Syntax der Module und Tests | ohne Syntaxfehler |
| TypeScript, Vite, Widget- und Landing-Build | erfolgreich |
| Gezielt: Abrechnungsautomatik, Rechnungen, Restaurant/Exporte, Registrierung, Websiteprüfung, SMTP-Einstellungen, Architektur | 70 Tests, 780 Assertions bestanden |
| Echte lokale STARTTLS-Zustellung | 3 Tests, 32 Assertions bestanden: Registrierungslink, Ablehnung, PDF-Rechnung und Mahnung, doppelte Ausführung |
| Browser | 48 bestehende Tests bestanden; die 2 an den direkten PDF-Download angepassten Abrechnungstests anschließend ebenfalls bestanden |
| PDF-Sichtprüfung | A4-Rechnung, achtseitige Reservierungstabelle mit Umlauten und langer Notiz; erste, Fortsetzungs- und letzte Seite geprüft; Tabellenkopf auf allen acht Seiten und letzter Datensatz im extrahierten Text bestätigt |
| Modulwerkzeuge | 11 Tests bestanden |
| Repository-/Paketprüfung | 18 Pins/Quellkopien geprüft; 32 Paketversionen und Abhängigkeiten validiert; unabhängige Archive erzeugt |

Vollständiger lokaler PHPUnit-Lauf: 153 Tests, 1186 Assertions; 147 bestanden, 3 SMTP-Tests im normalen Lauf übersprungen (oben separat erfolgreich), 2 Tests fehlgeschlagen und 1 Testfehler wegen der eingeschränkten lokalen PHP-Laufzeit. Konkret: Sodium fehlt für den Pipeline-Schlüsseltest; Entwicklungsloader und HTTP-Einstieg starten PHP-Unterprozesse ohne die lokale Session-Interface-Ergänzung. Die Änderungen an diesen drei Bereichen waren nicht Bestandteil dieses Ausbaus. Das ist **keine vollständig grüne Gesamtabnahme**. Die CI mit regulärem PHP muss vor dem Merge vollständig bestehen. Lokale Shim-/Runtime-Dateien wurden nicht ins Repository aufgenommen.

## Noch offen / Betriebsbedingungen

- Vollständige visuelle Einzelabnahme aller 183 Vorlagen-Buttons ist weiterhin offen. Browser-Funktionstests ersetzen diesen Vergleich nicht; die vorher genannte Anzahl offener Einzelprüfungen wird hier nicht als erledigt umdeklariert.
- Produktive SMTP-Zustellung mit den echten Zugangsdaten, öffentlich erreichbarer HTTPS-Adresse und tatsächlichem Mailanbieter ist vor Ort zu prüfen. WPOven bleibt eine deaktivierte Testvoreinstellung; darüber werden keine echten Registrierungs- oder Passwortmails versendet.
- Produktive Stripe-Ende-zu-Ende-Prüfung mit konfiguriertem Webhook, Anbieterzugang und laufendem Queue-/Scheduler-Dienst. Keine echten Zahlungen wurden bei diesen Tests ausgelöst.
- Externe API-Tokens, API-Admin-UI, OpenAPI-Laufzeitregistrierung sowie MCP-stdio/Remote-OAuth sind die im Konzept beschriebenen nächsten Implementierungsschritte. Das Beispielmanifest ist nicht als Laufzeitmodul registriert.
- Support-/Kunden-/Serverübersichten und asynchrone Großexporte sind nicht Bestandteil der neuen Exportwege. Synchrone Grenzen und unterstützte Endpunkte sind in EXPORTE.md dokumentiert.
