# System verstehen

Administration → **System verstehen** öffnet den eingebauten Guide. Gestaltung und lokal gelieferte Schriften stammen aus dem Platzhirsch-Design. Das eigenständige npm-Paket `@platzhirsch/system-guide-ui` wird mit der Oberfläche gebaut; es benötigt keinen zusätzlichen Dienst.

- **Systemkarte:** anklickbare Komponenten mit grafischen Verbindungen, Aufgaben, Standardpfaden und Erklärungen. Die Linien passen sich der Bildschirmgröße an.
- **Buchung verfolgen:** fünf Schritte vom Browser über IIS und die Anwendungsprüfung bis zur Restaurant-Datenbank; alternativ frei Komponenten erkunden.
- **Dateien & Daten:** PHP, MySQL-Programme, MySQL-Daten, Webwurzel, Konfiguration und Protokolle unterscheiden.
- **Module:** Aufgaben und Datenbereiche der ausgelieferten Fachmodule erklären.
- **Windows verstehen:** Dienste, Aufgabenplanung, Dateirechte, Ports, Firewall, DNS, HTTPS, SMTP, Protokolle und Sicherungen.
- **Meine Datenbanken:** nur für Systemadministratoren. Verwendet den bestehenden geschützten Lese-Endpunkt für konfigurierte Plattform-/Restaurantverbindungen und zeigt den Abrufzeitpunkt. Kein Passwortabruf und keine Ausgabe geschützter Worker-Dateien.

Die Lernansichten zeigen gekennzeichnete Standardwerte, keine Messwerte. Die Datenbankansicht zeigt Konfiguration und Zuordnungen, keine Erreichbarkeitsprüfung. Installationspfade, Dienstzustände, Firewallregeln und Zertifikate des tatsächlichen Windows-Rechners werden noch nicht automatisch inventarisiert. Auch zusätzliche Server werden nicht durch einen lokalen Pfad im Guide beschrieben.

Der Guide verändert keine Konfiguration. Die statischen Lerninhalte funktionieren ohne Internet; angemeldeter Zugriff auf die Anwendung bleibt erforderlich. Ein eigenständiger Hilfezugang bei ausgefallenem IIS, gespeicherter Lernfortschritt und geführte Schreibaktionen sind noch nicht enthalten.
