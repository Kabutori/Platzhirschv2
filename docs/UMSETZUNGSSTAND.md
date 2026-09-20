# Platzhirsch – tatsächlicher Umsetzungsstand

Stand: 20. September 2026. Die verbindliche aktuelle Liste steht in [RESTPUNKTE.md](RESTPUNKTE.md). Datierte Prüfprotokolle dokumentieren ihren jeweiligen Commit und sind keine aktuelle Aufgabenliste.

Die Anwendung enthält getrennte Administrations- und Restaurantportale, Mandantendatenbanken, Rollen und Rechte, TOTP/OIDC, Reservierungen, Räume/Tische, Öffnungs- und Sonderzeiten, Warteliste, Widget, Support und Audit. Wetterabruf und Buchungsnachrichten laufen über den Scheduler.

Registrierung erfolgt über eine Landingpage mit Website-Prüfung, passender E-Mail-Domain und Verifikationslink. SMTP ist in der Administration konfigurierbar. WPOven bleibt ein ausdrücklich gekennzeichnetes Testprofil; echter Versand benötigt einen geeigneten SMTP-Zugang.

Abrechnung umfasst manuelle Bestellungen und Belege sowie Stripe-Abonnements, wiederkehrende Rechnungen und den Rechnungs-/Mahnungsversand. PDF wird serverseitig erzeugt. Details und verbleibende Fachgrenzen: [Abrechnung](ABRECHNUNG.md), [Exporte](EXPORTE.md).

Module besitzen eigene Composer-/npm-Pakete, getrennte Repositories, feste Versionen und eine private Paketquelle. Die Administrationsoberfläche unterstützt Versionsauswahl, Abhängigkeitsvorschau und geprüfte Windows-Builds. API und MCP verwenden dieselben Fachrechte und Modulverträge; technische Konten und Rotation ergänzen den Tokenbetrieb. Odoo bleibt ein Platzhalter.

Windows-Pakete enthalten die benötigten Laufzeiten. Installation, Updates, Sicherung, Rollback und Wiederherstellung sind als Betriebsabläufe vorhanden; ein Release benennt die zugehörigen Anwendungs- und Windows-Prüfungen. Zielumgebungs- und Anbieterabnahmen bleiben gesondert erforderlich.

Die 183 Vorlagen-Buttons sind vorläufig abgenommen. Historische visuelle Einzelprüfungen werden nicht als neuer offener Block geführt.
