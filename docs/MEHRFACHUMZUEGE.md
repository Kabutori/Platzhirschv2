# Bestätigter Mehrfachumzug

Administration → System-Einstellungen → Serverzuordnung & Umzüge → Mehrere Restaurants umziehen.

Bis zu 50 aktive Restaurants auswählen, optional nach Quellserver filtern, Zielserver festlegen und die konkrete Auswahl im Dialog prüfen. Administratorkennwort, frischer MFA-Code, aktuelle Sicherungen und abgestimmte Ausfallzeiten sind erforderlich. Externe SQL-Schreibzugriffe müssen gestoppt sein.

Vor dem Einreihen werden alle Zuordnungsstände unter Datenbanksperren geprüft. Ist ein Restaurant nicht mehr aktiv oder seine Zuordnung veraltet, wird die gesamte Beauftragung abgewiesen. Ein erfolgreicher Auftrag sperrt alle ausgewählten Restaurants sofort; die Sperre endet für jedes Restaurant nach dessen erfolgreichem Umzug. Große Auswahlen können deshalb längere Ausfallzeiten verursachen.

Die Ausführung verwendet die bestehenden einzelnen Umzugsaufträge. Unter Aufträge ist jedes Restaurant separat nachvollziehbar. Bereits erfolgreiche Umzüge werden nicht automatisch zurückgerollt, wenn ein anderer Auftrag scheitert. Quelldatenbanken bleiben zur Nachkontrolle erhalten. Eine Wiederherstellung oder erneute Freigabe nach einem fehlgeschlagenen Auftrag muss nach Prüfung des jeweiligen Zustands erfolgen.

Der Mehrfachumzug ist nach Vorschau 84 hinzugefügt. Die ältere Design-Inventarliste beschreibt für B063–B065 noch den früheren Stand ohne Mehrfachauswahl; für diese Erweiterung gilt die hier beschriebene Funktion. Die vollständige visuelle Abnahme aller Vorlagenzustände bleibt offen.
