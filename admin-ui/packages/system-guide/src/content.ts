export const nodes = {
  browser: {
    name: 'Browser & Portale',
    kind: 'Zugang',
    meaning:
      'Hier bedienst du Platzhirsch. Administration und Restaurant haben eigene Anmeldeseiten und getrennte Sitzungen.',
    where: 'http://localhost:8378/administration/login · /restaurant/login',
    connection: 'Der Browser spricht mit IIS. Er erhält keinen direkten MySQL-Zugang.',
    tip: 'localhost ist immer der Rechner, auf dem der Browser läuft. Von einem anderen PC funktioniert dieser lokale Zugang nicht.',
  },
  iis: {
    name: 'IIS nimmt Anfragen entgegen',
    kind: 'Windows-Komponente',
    meaning:
      'Der Webserver liefert die gebaute Oberfläche aus. Anfragen an die Anwendung reicht er an PHP weiter.',
    where: 'Windows-Rolle IIS · Webwurzel: C:\\Platzhirsch\\app\\public',
    connection: 'Browser → IIS → PHP über FastCGI. Lokal lauscht die Website auf 127.0.0.1:8378.',
    tip: 'IIS ist eine Windows-Komponente, kein Ordner im Platzhirsch-Release. Netzwerkbetrieb wird separat mit HTTPS und Zertifikat freigeschaltet.',
  },
  php: {
    name: 'PHP führt Laravel aus',
    kind: 'Laufzeit & Anwendung',
    meaning:
      'PHP führt den Programmcode aus. Laravel organisiert Anmeldung, Rechte, Module, Datenbankzugriffe und Hintergrundaufträge.',
    where: 'C:\\Platzhirsch\\runtime\\php · Anwendung: C:\\Platzhirsch\\app',
    connection:
      'IIS startet PHP über FastCGI. PHP verbindet sich mit MySQL; Hintergrundaufgaben verwenden php.exe direkt.',
    tip: 'React ist die Oberfläche im Browser. PHP verarbeitet Anfragen auf dem Server. Die Module laufen innerhalb dieser Anwendung.',
  },
  platform: {
    name: 'Die gemeinsame Plattform',
    kind: 'MySQL-Datenbank',
    meaning:
      'Hier liegen Benutzerkonten, Restaurantzuordnungen, Rollen, Modulbestellungen, Freigaben, Servereinträge sowie Sessions, Cache und die Warteschlange.',
    where: 'Schema: platzhirsch_platform · lokale MySQL-Daten: C:\\Platzhirsch\\mysql-data',
    connection:
      'Die Webanwendung nutzt den eingeschränkten Plattformzugang. Auch Worker lesen ihre Aufträge aus dieser Datenbank.',
    tip: 'Ein Schema ist ein benannter Bereich in MySQL. Die Plattform-Datenbank enthält nicht die eigentlichen Reservierungstabellen aller Restaurants.',
  },
  tenant: {
    name: 'Ein eigener Datenbereich je Restaurant',
    kind: 'Mandanten-Datenbank',
    meaning:
      'Jedes Restaurant besitzt ein eigenes Schema für Räume, Tische, Öffnungszeiten und Reservierungen. Aktive Zusatzmodule können eigene Tabellen ergänzen.',
    where: 'Schema: ph_t_<24-stellige Kennung> · lokal ebenfalls in C:\\Platzhirsch\\mysql-data',
    connection:
      'PHP wählt die zum Restaurant gehörende Verbindung. Nach einem Serverumzug verweist diese Zuordnung auf den Zielserver.',
    tip: 'Datenbankdateien werden von MySQL verwaltet. Eine Datensicherung ist mehr als das Kopieren eines einzelnen Restaurantordners.',
  },
  worker: {
    name: 'Arbeiten ohne geöffneten Browser',
    kind: 'Windows-Aufgabenplanung',
    meaning:
      'Worker bearbeiten Aufträge, etwa Restaurantanlage, Modulmigration und Umzug. Der Scheduler stößt regelmäßig fällige Anwendungsaufgaben an.',
    where:
      'Aufgaben: Platzhirsch-default, Platzhirsch-provisioning, Platzhirsch-Scheduler · C:\\Platzhirsch\\tasks',
    connection: 'Aufgabenplanung → PHP → Plattform-Warteschlange und zugeordnete Datenbankserver.',
    tip: 'Das Schließen des Browsers beendet diese Arbeiten nicht. Der Provisionierungsworker besitzt gesonderte Zugänge, die der Webprozess nicht lesen darf.',
  },
} as const;
export const files = [
  [
    'Programmcode',
    'C:\\Platzhirsch\\app',
    'Laravel-Anwendung und installierte PHP-Modulpakete unter vendor\\platzhirsch.',
  ],
  [
    'Öffentliche Dateien',
    'C:\\Platzhirsch\\app\\public',
    'Webwurzel von IIS: Einstiegspunkt, gebaute Oberfläche und öffentliche Dateien.',
  ],
  [
    'PHP-Laufzeit',
    'C:\\Platzhirsch\\runtime\\php',
    'php.exe für die Kommandozeile; php-cgi.exe für IIS; php.ini für Laufzeiteinstellungen.',
  ],
  [
    'MySQL-Programm',
    'C:\\Platzhirsch\\runtime\\mysql',
    'Der Windows-Dienst PlatzhirschMySQL startet den Datenbankserver.',
  ],
  [
    'MySQL-Daten',
    'C:\\Platzhirsch\\mysql-data',
    'Physische Daten der lokalen MySQL-Instanz, einschließlich Plattform-, Restaurant- und Systemdaten.',
  ],
  [
    'Anwendungseinstellungen',
    'C:\\Platzhirsch\\app\\.env',
    'Unter anderem Anwendungsadresse, Datenbank- und Mailkonfiguration. Enthält Geheimnisse; im Guide nur freigegebene Werte anzeigen.',
  ],
  [
    'MySQL-Einstellungen',
    'C:\\Platzhirsch\\my.ini',
    'Unter anderem Datenverzeichnis, lokaler Port 3308 und Bindung an 127.0.0.1.',
  ],
  [
    'Protokolle',
    'C:\\Platzhirsch\\app\\storage\\logs',
    'Anwendung und PHP. MySQL schreibt nach C:\\Platzhirsch\\logs\\mysql.log.',
  ],
  [
    'Geschützte Worker-Zugänge',
    'C:\\Platzhirsch\\app\\storage\\app\\private',
    'provision.json und autorisierte server-*.json. Nicht für die Webanwendung lesbar.',
  ],
] as const;
export const modules = [
  ['Identity', 'Anmeldung, Benutzer, MFA und Rollen.', 'Gemeinsame Plattform · getrennte Portalrechte'],
  ['Customer', 'Restaurantanlage, Besitzerzuordnung und Profil.', 'Gemeinsame Plattform'],
  [
    'Reservation',
    'Räume, Tische, Öffnung und konfliktgeprüfte Buchungen.',
    'Eigene Datenbank des Restaurants',
  ],
  [
    'Widget',
    'Öffentliche Buchungsoberfläche und Einbettung auf Websites.',
    'Widget-Konfiguration zentral; Buchung über Reservation',
  ],
  ['Support', 'Tickets, Antworten und interne Notizen.', 'Plattformdaten mit Mandantentrennung'],
  [
    'Billing',
    'Angebote, Bestellungen und bestätigte Nutzungszeiträume.',
    'Gemeinsame Plattform · Zahlung derzeit manuell bestätigt',
  ],
  [
    'Reporting',
    'Tagesauswertung und gespeicherte Zeiträume.',
    'Restaurant-Datenbank · benötigt Freigabe und passende Rechte',
  ],
  [
    'Provisioning',
    'Serververbindungen und Datenbankzugänge.',
    'Arbeitet mit den geschützten Hintergrundaufträgen des Anwendungshosts',
  ],
  [
    'Module Host & Contracts',
    'Registrierung, Abhängigkeiten und gemeinsame Schnittstellen.',
    'Technische Basis für das Zusammenspiel der Module',
  ],
] as const;
