# Detailansichten und vorbereitete Odoo-Ansicht

Odoo bleibt auf ausdrücklichen Wunsch ein Platzhalter unter Administration → System-Einstellungen → Odoo. Eingaben, Verbindungsprüfung und Synchronisation sind deaktiviert. Es werden weder Zugangsdaten gespeichert noch Requests an Odoo gesendet. Die spätere Integration bleibt als eigenes Composer-/npm-Modul vorgesehen und wird nicht als bereits kauf- oder aktivierbares Modul registriert.

Die Mandantenliste bietet eine schreibgeschützte Detailansicht für bestehende Leseberechtigungen. Sie zeigt Kontakt, Status, Zeitzone, Organisation und Serverzuordnung; die Bearbeitung bleibt Systemadministratoren vorbehalten. Serverdetails zeigen Host, Port, Region, Zweck, Prüfdatenbank/-benutzer, TLS und Freigabestatus. Passwörter werden nicht in die Detailansicht übernommen.

Im Bearbeitungsdialog eines vorhandenen Raums steht „Tische zuordnen“ zur Verfügung. Ausgewählte Tische werden diesem Raum zugeordnet; nicht ausgewählte Tische behalten ihren bisherigen Raum. Die gesamte Anfrage prüft die ursprünglichen Zuordnungen unter Datenbanksperren, bevor Änderungen gespeichert werden. Tische mit laufenden oder zukünftigen Buchungen oder Mitgliedschaft in einer gespeicherten Tischkombination werden nicht verschoben. Raumdetails bleiben beim Wechsel des Dialogreiters als ungespeicherter Entwurf erhalten.

Der vorherige Mehrfachumzug ist in Windows-Vorschau 87 enthalten. Die neuen Ansichten und die Raumzuordnung werden im folgenden Paket geprüft. Wetterautomatik und die vollständige visuelle Einzelabnahme aller 183 Vorlagenzustände bleiben offen. Odoo-Synchronisation ist gemäß Wunsch zurückgestellt.
