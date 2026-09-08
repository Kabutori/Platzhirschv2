# Platzhirsch – tatsächlicher Umsetzungsstand

Stand: 2026-09-08. Entwicklungszweig `codex/windows-application`, [Entwurfs-PR #1](https://github.com/Kabutori/Platzhirschv2/pull/1). Keine Produktionsfreigabe sämtlicher Konzeptphasen. Maßgeblich für ein Installationspaket sind der Commit und die erfolgreichen Prüfungen seines Releases.

Die älteren Dateien KONZEPT.md, ADMIN-UI.md und BACKEND-BASIS.md enthalten auch Planungen und Testberichte anderer Implementierungen. Dieser Stand beschreibt den tatsächlich vorhandenen Code.

## Anwendung und Gestaltung

- Getrennte Administration und Restaurantportal mit eigenen Anmeldeseiten und Sitzungen; Erstadministrator nur mit einmaligem Einrichtungsschlüssel.
- Anmeldung, Kontosperren, CSRF, Kennwort-Reset bei konfiguriertem SMTP, TOTP mit Replay-Schutz.
- Plattformrollen mit gespeichertem Entwurf, Prüfung und expliziter Aktivierung; Rollenzuordnung für Plattformmitarbeiter. Eigene Restaurantrollen mit sofortiger serverseitiger Berechtigungsprüfung. Rechtefamilien kommen aus den installierten Modulen und werden nach Portal getrennt.
- Rollenoberfläche mit horizontaler Rollenauswahl, Gruppenschaltern und Rechtezeilen nach der Vorlage. Modul-Shop mit Basismodulen, Erweiterungskacheln, Kaufstatus und Aktivierungsschaltern. Dunkle kantige Oberfläche mit den lokal gelieferten Schriften und zentralen Designtokens.
- Mandanten, Konten, Restaurantprofile, Audit, Systemstatus, Räume, Tische, Öffnungszeiten und Sondertage.
- Testrestaurant mit gewähltem Besitzerzugang, eigenem Schema, Raum, drei Tischen und sieben Öffnungstagen.
- Reservierungen mit Zeitzone, Kapazitäts-/Öffnungsprüfung, Konfliktprüfung unter Datenbanksperren, Storno, Tageszahlen, Tischbelegung sowie CSV-/XLSX-Export und Browser-Drucken/PDF.
- Öffentliche Buchungsseite und Shadow-DOM-Widget mit Verfügbarkeit, Ablauf/Widerruf, zulässigen Website-Ursprüngen und serverseitiger Buchungsdauer.
- Support-Tickets, Antworten und interne Notizen mit Mandantentrennung.
- Systemadministrator kann eingeschränkte SQL-Anwendungszugänge sehen und nach Kennwortprüfung kurzzeitig anzeigen. Worker- und MySQL-Root-Zugänge werden nicht im Web ausgegeben.

## Weitere Angleichung an die Designvorlage

- System-Einstellungen mit berechtigungsgesteuerten Reitern, Modul-Katalog und Angebote/Bestellungen; getrennte browserlokale Einstellungen und Favoriten je Portal/Konto.
- Restaurantrollen mit Rollenreitern, Berechtigungsgruppen und Schaltern. Restaurantprofil mit Küche, Preisklasse, Sitzplatzangabe, Beschreibung, Website und HTTPS-Logo-Adresse.
- Grafischer Tischplan mit Raumwahl, Tischformen, zeitabhängiger Belegung und gespeicherten Positionen per Ziehen/Pfeiltasten. Layoutbearbeitung setzt Konfigurationsrechte voraus.
- Widget-Designer mit Vorschau, Sprache, Position, Akzentfarbe, Gruppengröße und Markenanzeige; bestehende Zugänge können ohne neuen Token bearbeitet werden. Gruppengrenzen gelten auch serverseitig.
- Grafischer Guide unter „System verstehen“ in beiden Portalen; echte Verbindungsmetadaten weiterhin nur für Systemadministratoren im Administrationsportal.
- Acht Installer-Schritte mit Uhrzeiten, Laufzeitmeldungen, Dateiprüfungsfortschritt und geschütztem Statusprotokoll ohne erzeugte Geheimnisse.

Diese Änderungen durchlaufen eine neue Anwendungs- und Windows-Prüfung. Die weiter unten aufgeführten älteren Prüfläufe bestätigen sie noch nicht. Die jeweils erfolgreich veröffentlichte Release-Version ist maßgeblich.

## Restaurantbetrieb, Konten und Designabgleich

- Öffnungsfenster und Buchungen über Mitternacht mit Sondertagsvorrang, UTC-Dauer und Ablehnung mehrdeutiger/nicht existierender Startzeiten bei Zeitumstellung.
- Warteliste mit eigenen Rechten, Kontaktdaten, Status und transaktionaler Übernahme. Konflikte erhalten den offenen Eintrag.
- Haupttisch plus weitere aktive Tische im selben Raum; summierte Plätze und Konfliktprüfung aller beteiligten Tische. Das Widget berücksichtigt kombinierte Belegungen, bietet selbst aber einzelne Tische an.
- Optionaler SMTP-/Twilio-Versand für Bestätigungen, Änderungen, Storno und Erinnerungen; Konfiguration und Status je Restaurant. Standardmäßig deaktiviert. Kein Live-Anbietertest oder Zustellnachweis.
- OIDC mit expliziter Kontoverknüpfung, PKCE/Nonce/Signaturprüfung und weiterhin lokaler MFA. Ein fest konfigurierter Anbieter pro Installation; Details in [SSO.md](SSO.md).
- Neue Restaurantrollen nach Einmalvorschau und Kennwort/TOTP in bis zu 50 ausgewählten Mandanten erstellen. Kein Überschreiben bestehender Rollen oder automatisches Ändern von Benutzerzuordnungen.
- Buchungsdialog mit Kalender, Viertelstunden-Auswahl und Walk-in. Der Kalender bleibt auf schmalen Bildschirmen innerhalb des sichtbaren Bereichs.
- Reporting trennt Storno, No-show und eingetroffene/abgeschlossene Buchungen. Gästezahl ohne Storno und No-show; Gruppierung nach lokalem Starttag.
- Alle 183 Buttons der konkreten Designreferenz sind mit Codebefund, Umsetzungspfad und Restabweichung erfasst. Eine vollständige visuelle Abnahme ist damit ausdrücklich nicht erreicht. Siehe [DESIGN-FORTSCHRITT.md](DESIGN-FORTSCHRITT.md) und [DESIGN-BUTTON-INVENTAR.csv](DESIGN-BUTTON-INVENTAR.csv).

Bedienung, Konfiguration und Grenzen: [RESTAURANT-ABLAEUFE.md](RESTAURANT-ABLAEUFE.md).

## Module, Kauf und Serverbetrieb

- Composer-Pakete für Identity, Customer, Reservation, Widget, Support, Billing, Reporting und Provisioning sowie Contracts und Module Host. Entsprechende npm-Pakete und gemeinsame UI-/Token-Pakete. Feste Paketversionen, Lockdateien und Composer-Autodiscovery; modulare PHP-Migrationen und getrennte UI-Dateien.
- Die Anwendung enthält die Integrationsadapter, Queue-Orchestrierung, Middleware und Betriebskonfiguration. Historische Tabellen und Migrationsnamen werden zur Datenkompatibilität nicht pauschal umbenannt. Separate Modul-Repositories und private Satis-/npm-Registries sind noch nicht eingerichtet.
- Modulangebote mit festgelegtem Monatspreis; Bestellungen mit Preisprüfung und Wiederholungsschutz. Freigabe erst nach manueller Bestätigung des externen Zahlungseingangs. Eindeutige Zahlungsreferenzen verhindern erneute Freigabe derselben Zahlung.
- Mandantenaktivierung über privilegierten Hintergrundauftrag: temporäre Migrationsrechte, mitgelieferte Tenant-Migration, anschließender Rechteentzug. Deaktivierung und abgelaufene Nutzungszeiträume sperren Zugriff ohne Datenlöschung.
- Reporting als erste kostenpflichtig freischaltbare Erweiterung: Tagesauswertung, gespeicherte Zeiträume und eigene Lese-/Schreibrechte.
- Zusätzliche Datenbankserver mit Prüfzugang und separater lokaler Autorisierung des Workers. Neue Restaurants können direkt auf einem freigegebenen Server angelegt werden. Externe Verbindungen verlangen TLS mit Zertifikatsprüfung.
- Mandantenumzug mit Kennwort, frischem TOTP, Sicherungs-/Ausfallbestätigung, exklusiver Mandantensperre, Kopie in ein neues Schema, geordnetem Inhaltsvergleich und anschließender Zuordnungsänderung. Quelle bleibt erhalten; API-Zugriffe verwenden nach Erfolg den Zielserver.
- Reparaturpfad für hart unterbrochene Modulaufträge: lokale Prüfung, Entzug verbleibender DDL-Rechte und erneute Aktivierung. Keine automatische Löschung partieller Migrationen.

Die Bedienung und konkreten Grenzen stehen in [MODULE-UND-SERVER.md](MODULE-UND-SERVER.md).

## Windows-Auslieferung

`Install.bat` startet PowerShell mit einer nur für diesen Prozess geltenden Ausführungsrichtlinie. Das gebaute Paket enthält IIS-Zusatzkomponenten, PHP NTS, MySQL, PHP-Abhängigkeiten und fertige UI. Git, Composer und Node.js sind auf dem Zielserver nicht erforderlich.

Installer: eigene lokale MySQL-Instanz, IIS/FastCGI, geschützte Konfiguration, geplante Aufgaben für Worker und Scheduler, Gesundheitsprüfung und wiederholbarer Lauf derselben Version. Standardmäßig nur lokaler HTTP-Zugang; separate HTTPS-Freigabe.

Offline-Snapshot und Wiederherstellung sind für dieselbe lokale Maschine, Version und Konfiguration vorhanden. Zusätzliche autorisierte Datenbankserver sperren diese lokale Komplettsicherung: eine koordinierte Sicherung mehrerer Server ist noch nicht automatisiert.

## Prüfstand

- [Anwendungsprüfung 34255350221](https://github.com/Kabutori/Platzhirschv2/actions/runs/34255350221): 89 PHP-Tests mit 687 Assertions, 29 Chromium-Browsertests, Frontend-Build, Abhängigkeitsprüfungen und PowerShell-Prüfung erfolgreich. Sieben bereits vorhandene PHPUnit-Notices bleiben. Dieser Lauf enthält alle neuen Fachfunktionen; die anschließende mobile Kalenderkorrektur und der vollständige Windows-Paketbau werden separat erneut geprüft.
- Nachrichten- und SSO-Tests verwenden simulierte Anbieter; echte Anbieterzugänge sind nicht Teil der CI. Die Windows-Integrationskette ersetzt keine Live-Abnahme dieser externen Dienste.
- Die folgenden älteren Läufe dokumentieren frühere Meilensteine:

- [Anwendungsprüfung 34192895027](https://github.com/Kabutori/Platzhirschv2/actions/runs/34192895027): 71 PHP-Tests, 484 Assertions; Frontend-Build, Abhängigkeitsprüfung, zwölf Chromium-Browsertests und PowerShell-5.1-Syntax erfolgreich. PHPUnit meldet zusätzlich sieben Notices, keine fehlgeschlagenen Tests.
- Browserprüfungen arbeiten mit API-Mocks und erzeugen Desktop-/Mobilaufnahmen. Zusätzlich prüfen Windows-Jobs die tatsächliche Installation hinter IIS mit echten MySQL-Datenbanken.
- [Windows-Prüfung 34190594946](https://github.com/Kabutori/Platzhirschv2/actions/runs/34190594946) hat auf Server 2022 und 2025 die erste vollständige Modul-/Serverkette bestanden: lokale Sicherung/Wiederherstellung, Bestellung, Freigabe, Aktivierung, zweite MySQL-Instanz, direkte Serverzuordnung und Umzug samt Reporting-Daten und erhaltener Quelle.
- Nachfolgende Paket-/Rechteänderungen und der Reparaturpfad durchlaufen denselben erweiterten Windows-Test erneut. Ein Download wird erst veröffentlicht, wenn beide Server erfolgreich sind. Die Release-Seite nennt den konkreten Prüflauf dieses Pakets; ein älterer grüner Lauf ersetzt diese Prüfung nicht.
- PHP und PowerShell stehen in der lokalen Bearbeitungsumgebung nicht zur Verfügung; ihre Laufzeitprüfungen erfolgen in GitHub Actions.

## Noch nicht vollständig umgesetzt oder abgenommen

- Automatischer Zahlungsanbieter, automatische Abbuchungen/Verlängerungen, Rechnungen, Rückerstattungen und Testphasenpolitik. Die vorhandene Freigabe ist eine manuelle externe Zahlungsbestätigung.
- Separate Paket-Repositories, private Paketregistries und unabhängige Modul-Releases. Erweiterte Clusterplanung, Massenumzüge und automatische Bereinigung alter/abgebrochener Kopien.
- Versionsübergreifende Updates und Rollback, Wiederherstellung auf neuer Maschine, koordinierte Wiederherstellung verteilter Datenbanken, echte Neustartabnahme, externe CA-/Netzwerkabnahme, große Datenmengen und Lasttests.
- Live-Abnahme des OIDC-Anbieters, weitere SSO-Verfahren, Organisationshierarchie, fortlaufende Synchronisierung bestehender Rollen und vollständige Listenpaginierung. Die Rollout-Auswahl liest bisher nur die erste Mandantenseite.
- Marketing-Website, Selbstregistrierung, E-Mail-Verifikation, Odoo-/Wetterintegrationen, Umsatz-/Kassenberichte und direkter serverseitiger PDF-Download. Nachrichten-Zustellwebhooks und ein kontrollierter Wiederholungsassistent fehlen.
- Raumbezogene Sperrzeiträume, vordefinierte kombinierbare Tischgruppen, automatische Kombinationssuche im Widget und interaktive Buchungsvorschau im Designer.
- Vollständige visuelle 1:1-Abnahme aller Designansichten sowie durchgängige Tastatur-, Bildschirmleser- und Browser-Ende-zu-Ende-Abnahme.

Ein bestehendes System anderer Version darf nicht durch Löschen seiner Installation oder Daten an der Versionsprüfung vorbeigeführt werden. Für neue Vorschauen bis zur Update-Implementierung eine getrennte Installation verwenden.
