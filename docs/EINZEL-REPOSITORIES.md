# Einzel-Repositories und feste Anwendungszusammenstellung

Alle 18 Repositories unter `Kabutori` sind eingerichtet und mit Quellen, Paketmetadaten sowie einem eigenen GitHub-Workflow befüllt. Die tatsächlichen übernommenen Commits, Paketpfade und Dateiprüfsummen stehen in `modules.lock.json`.

## Aufteilung

| Repository | Inhalt |
|---|---|
| platzhirsch-module-identity | Benutzer, Rollen, Rechte, Anmeldung und UI |
| platzhirsch-module-customer | Restaurant-/Mandantenverwaltung und UI |
| platzhirsch-module-provisioning | Datenbank-/Serververwaltung und UI |
| platzhirsch-module-reservation | Reservierungen, Räume, Tische, Öffnungszeiten und UI |
| platzhirsch-module-widget | Widget-Konfiguration und API samt UI |
| platzhirsch-module-billing | Angebote, Zahlungen, Laufzeiten und Rechnungen samt UI |
| platzhirsch-module-support | Support-Tickets und UI |
| platzhirsch-module-reporting | Berichte und UI |
| platzhirsch-module-weather | Wetterabruf und UI |
| platzhirsch-module-audit | Audit-Schreibdienst, Listen-API und UI |
| platzhirsch-module-notification | Buchungsnachrichten, Einstellungen, Versand und UI |
| platzhirsch-module-release | Versionshinweise und UI |
| platzhirsch-module-integration-odoo | Inaktiver Odoo-Platzhalter |
| platzhirsch-contracts | Gemeinsame PHP-Schnittstellen |
| platzhirsch-module-host | PHP-Modulregistrierung, UI-Modulkatalog und Systemguide |
| platzhirsch-design-tokens | Gemeinsame Designtokens |
| platzhirsch-ui | Gemeinsames UI-Runtime-Paket; Paketname bleibt @platzhirsch/ui-runtime |
| platzhirsch-widget-embed | Einbettbares Widget und eigener Build |

Fachmodule enthalten PHP an der Repository-Wurzel und die zugehörige Oberfläche in `ui/`. Gemeinsame technische Pakete haben eigene Paketpfade gemäß `module.json`. Paketnamen und bisherige API-Routen bleiben erhalten.

## Entwicklung und Übernahme

1. Gewünschtes privates Repository mit dem eigenen GitHub-Zugang klonen, z.B. `git clone https://github.com/Kabutori/platzhirsch-module-billing.git`.
2. Feature-Branch anlegen, Änderungen und gegebenenfalls Paketversionen bearbeiten. Die Metadaten in `module.json` müssen den Manifestversionen entsprechen.
3. Änderungen im Modul-Repository prüfen und committen. Der Push prüft PHP-Syntax und baut die einzelnen Archive. Ein Tag `vX.Y.Z` veröffentlicht nach erfolgreicher Prüfung einen eigenen Modul-Release.
4. Im Modul-Checkout den gewünschten vollständigen Commit auschecken. Im Checkout von Platzhirschv2 ausführen:

```powershell
python tools/modules/sources.py import platzhirsch-module-billing --checkout C:\src\platzhirsch-module-billing --commit VOLLSTAENDIGE_40_STELLIGE_COMMIT_SHA
```

5. Bei einer Versionsänderung die exakten Anforderungen in der Anwendung und ihren abhängigen Paketen anpassen; Composer-/npm-Lockdateien mit den Paketmanagern aktualisieren. Abhängige Fachmodule in ihren eigenen Repositories ändern und ebenfalls synchronisieren.
6. `python tools/modules/sources.py verify` und `python tools/modules/packages.py --check` ausführen. Danach Anwendungsbuild, Backend-/Browsertests und Windows-Prüflauf. Produktiv weiterhin das gemeinsam geprüfte Windows-Paket über den vorhandenen Updateablauf installieren.

Der Import verweigert unbekannte Zuordnungen, falsche Commits, unsaubere Modul-Checkouts und lokal veränderte Paketkopien. Der lokale Widget-Testserver gehört weiterhin zur Hauptanwendung und bleibt beim Import erhalten. Quellen werden für Prüfsummen auf LF-Zeilenenden normalisiert, damit Windows-Checkouts funktionieren.

## Bewusster Integrationsweg

Die privaten Einzel-Repositories sind die Bearbeitungsquelle. Platzhirschv2 führt überprüfte Quellkopien der benötigten Pakete mit. Das erlaubt Builds ohne privaten Repository-Token und bindet jedes Paket an einen festen Commit. Der öffentliche Status des Haupt-Repositories bedeutet, dass diese übernommenen Quellen dort ebenfalls sichtbar sind; es werden keine Zugangsdaten übernommen.

CI kontrolliert die Quellkopien gegen `modules.lock.json`, installiert die PHP-Module aus den gebauten Einzelarchiven und baut die UI erneut aus den npm-Archiven. Das Windows-Paket enthält den Nachweis als `module-sources.json`.

Die historische Notification-Migration bleibt im Reservierungs-Basisschema; Audit-Basistabellen bleiben im Plattform-Basisschema. Ihre Namen werden bei bestehenden Installationen nicht verändert. Neue Laufzeitlogik liegt in den getrennten Modulen. Der ReservationNotifier-/ReservationReadModel-Vertrag vermeidet eine direkte gegenseitige Abhängigkeit zwischen Reservierung und Versand.

Die integrierte private Composer-/npm-Quelle und die Modulauswahl unter Administration → Module → Versionen & Updates sind in [MODUL-UPDATES.md](MODUL-UPDATES.md) beschrieben. Version 0.1.0 bleibt als bestehender Release unverändert.
