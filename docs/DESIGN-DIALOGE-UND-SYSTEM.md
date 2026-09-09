# Designabgleich: Dialoge und Systemansichten

## Enthaltene Änderungen

- **Audit Log:** Plattform und Mandanten getrennt, Suche nach Aktion/Objekt, Mandanten-ID, Seitennavigation, Aktualisierung. Filter werden vor der Paginierung auf dem Server angewendet; vorhandene Leserechte bleiben erforderlich.
- **Releases:** Systemadministratoren erstellen/bearbeiten Versionshinweise mit Modul, Version, Kategorie, Text und optionalem HTTPS-Link. Entwürfe bleiben für andere Konten unsichtbar. Veröffentlicht wird der Hinweis im Changelog der Restaurantportale. Ein Hinweis baut oder installiert kein Softwarepaket. Gleichzeitige Bearbeitung ist durch Revisionsprüfung geschützt.
- **Support:** Changelog und Ticketdetails als Dialoge. Öffentliche Antworten und interne Notizen behalten ihre serverseitigen Zugriffsgrenzen.
- **Exportieren:** Formatdialog gemäß Vorlage. Nur in den Einstellungen aktivierte CSV/XLSX/PDF-Optionen erscheinen. Der Export umfasst alle Reservierungen des gewählten Tages; Suchfilter gelten dafür nicht. PDF verwendet den Druckdialog des Browsers.
- **Rollen:** Anlegen, Umbenennen und Löschen als Dialoge. Umbenennung wird in den Rechteentwurf übernommen und anschließend bewusst gespeichert. Systemrollen und zugewiesene Rollen bleiben geschützt.
- **Räume:** Farb- und Symbolraster mit sichtbarer Auswahl, einschließlich tastaturbedienbarer Schaltflächen. Wetterautomatik ist nicht enthalten.
- **Widget:** Terrakotta, Schiefer und Wald als wählbare Farbvorlagen; freie Farbe bleibt möglich.
- **System:** Übersicht, Backups und Migrationen. Migrationen zeigen die tatsächlich angewendete Plattformhistorie. Backup/Wiederherstellung bleiben erhöhte Windows-Operationen; der Reiter verweist auf die Betriebsanleitung und behauptet keine automatische Sicherungsüberwachung.
- **Dialogtechnik:** Dialoge werden außerhalb übergeordneter Formulare dargestellt, besitzen eindeutige Titelzuordnungen und reichen Abbrechen/Absenden nicht an darunterliegende Dialoge weiter.

## Nachweis und Grenzen

`DESIGN-BUTTON-INVENTAR.csv` enthält weiterhin genau 183 aus der unveränderten Vorlage extrahierte Button-Definitionen. Der Codeabgleich wurde berichtigt: inzwischen implementierte Raumsperren, interaktive Widget-Vorschau, Rollensynchronisierung und Mandantenpaginierung stehen nicht mehr als fehlend darin. „Funktion vorhanden“ ist keine pauschale pixelgenaue Freigabe aller Zustände.

Automatische Tests prüfen serverseitige Auditfilter, Sichtbarkeit von Release-Entwürfen, Rechte, veraltete Release-Änderungen, sichere Linkprotokolle, Exportoptionen und Dialogabläufe. Desktop-/Mobilaufnahmen liegen beim Prüflauf im Artefakt `role-design-preview`.

**Weiterhin keine vollständige visuelle Einzelabnahme aller 183 Definitionen:** unter anderem gemeinsamer Öffnungszeiten-Wochendialog, Detailumfang von Mandanten/Clustern, raumweise Tischmehrfachzuordnung, Wetterautomatik sowie Odoo-Einstellungen und Synchronisation weichen ab oder fehlen. Redis aus dem älteren Entwurf gehört nicht zum festgelegten MySQL-Stack. Diese Punkte werden nicht durch simulierte Erfolgsbuttons ersetzt.
