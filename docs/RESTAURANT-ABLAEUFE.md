# Restaurantabläufe und Exporte

## Nachtbetrieb

Schließzeit vor Öffnungszeit bedeutet Folgetag, beispielsweise 18:00–02:00. Gleiche Zeiten sind ungültig, nicht „24 Stunden“. Eine Buchung muss vollständig in ein Fenster passen. Ein Sondertag ersetzt den gesamten Kalendertag und unterbindet hineinreichende Fenster des Vortags; eigene Sondertagszeiten können einen neuen Zeitraum öffnen. Reguläre Fenster werden nicht über Lücken hinweg zusammengefügt. Die Dauer zählt tatsächlich vergangene Minuten, auch bei Zeitumstellung. Nicht existierende und doppeldeutige Startzeiten während der Sommer-/Winterzeitumstellung werden abgewiesen; dafür ist eine eindeutige Startzeit zu wählen.

## Tischkombinationen

Im Buchungsdialog Haupttisch plus optionale weitere Tische auswählen. Alle müssen aktiv und im selben Raum sein. Kapazitäten werden addiert, jeder Tisch wird bis zum Buchungsende blockiert. Die API sperrt alte und neue Tische in stabiler Reihenfolge. Unter Tischkombinationen können feste Gruppen angelegt werden. Das Widget bietet aktive, vollständig freie Gruppen automatisch nach passender Kapazität an; Mitglieder werden serverseitig aufgelöst. Unter Raumsperren lassen sich Zeiträume pro Raum sperren. Sie gelten auch im Widget und erscheinen im Tischplan/Zeitstrahl.

## Warteliste

Die Rechte `waitlist.read` und `waitlist.write` kommen aus dem Reservierungsmodul. Bestehende Mitarbeiterrollen erhalten sie nicht automatisch. Übernahme benötigt zusätzlich Reservierungs-Lese- und Schreibrechte. Status: wartend, kontaktiert, storniert oder übernommen. Eine Wartelistenposition reserviert keine Kapazität. Die Übernahme erzeugt Buchung und Statuswechsel gemeinsam; bei Konflikt bleibt der Eintrag offen. Erneute Übernahme derselben Position liefert dieselbe Buchungsnummer.

## Export

CSV, Excel-Arbeitsmappe und eine druckbare Tagesliste stehen separat in den persönlichen Einstellungen. Alle exportieren den ausgewählten Tag; Suchfilter sind keine Exportfilter. Excel und Druckansicht verwenden die Restaurant-Zeitzone; der vorhandene CSV-Vertrag benennt UTC explizit. PDF: im Browser-Druckdialog „Als PDF speichern“ wählen. XLSX benötigt die im Windows-Paket enthaltene PHP-ZIP-Erweiterung. Gasttexte werden als Inline-Strings statt Formeln geschrieben und für HTML/XML maskiert.

Formatgrundlage: [Microsoft SpreadsheetML](https://learn.microsoft.com/en-us/office/open-xml/spreadsheet/structure-of-a-spreadsheetml-document) und [PHP ZipArchive](https://www.php.net/manual/en/ziparchive.addfromstring.php).

## Nachrichten

Unter Restaurant → Buchungsnachrichten werden E-Mail, SMS und der Erinnerungsvorlauf konfiguriert. Standardmäßig sind beide Kanäle aus. SMTP wird in der vorhandenen Serverkonfiguration eingerichtet. Für SMS werden zusätzlich in der geschützten Anwendungskonfiguration benötigt:

```dotenv
TWILIO_ACCOUNT_SID=AC...
TWILIO_AUTH_TOKEN=...
TWILIO_FROM=+49...
```

Nach lokaler Änderung Konfigurationscache erneuern. Zugangsdaten gehören niemals in Git. SMS verwendet die offizielle [Twilio Messages API](https://www.twilio.com/docs/messaging/api/message-resource). Die Aktivierung erlaubt kostenpflichtigen Versand über dieses Konto. Telefonnummern müssen E.164 entsprechen; fehlende/ungültige Empfänger erscheinen im Status.

Der vorhandene Scheduler führt jede Minute `reservation:notifications` aus. Pro Restaurant und Lauf werden höchstens 20 fällige Ereignisse verarbeitet. Bestätigung/Änderung/Storno und Erinnerungen werden bei Buchungsereignissen in derselben Mandantendatenbank vorgemerkt. Veraltete Versionen, stornierte Erinnerungen und deaktivierte Kanäle werden verworfen. Aktivierung erzeugt keinen rückwirkenden Versand für Altbuchungen. Status „An Versanddienst übergeben“ ist kein Zustellnachweis.

Ein Versandauftrag wird vor dem Netzwerkaufruf beansprucht. Nach Absturz oder unklarem Netzwerkfehler wird er nicht automatisch erneut gesendet; im Status bleibt „sending“ beziehungsweise „unknown“ zur manuellen Prüfung. Ein Zustell-Webhook, Wiederholungsassistent und ein Live-Test mit Kundenzugangsdaten stehen aus. Automatische Tests verwenden simulierte Versanddienste, keine echten Empfänger.

## Restaurantrollen über mehrere Mandanten verteilen

Administration → Rollen & Rechte → Restaurantrollen verteilen erstellt eine neue Rolle mit denselben registrierten Restaurantrechten in bis zu 50 ausdrücklich ausgewählten Restaurants. Zunächst wird eine fünf Minuten gültige Einmal-Vorschau erzeugt. Die Erstellung verlangt Systemadministrator, Kennwort und frischen TOTP-Code. Alle Rollen entstehen gemeinsam in einer Plattform-Datenbanktransaktion. Gleichnamige Rollen führen zum Abbruch; bestehende Rechte und Benutzerzuordnungen werden nicht überschrieben. Die Restaurantleitung weist die neue Rolle anschließend ihrem Team zu. Der separate Modus „Bestehende Rollen synchronisieren“ ersetzt die Rechte gleichnamiger Rollen nach Vorschau der hinzugefügten/entfernten Rechte und betroffenen Benutzer. Versionskonflikte brechen die gesamte Anwendung ab. Organisationen und Unterorganisationen verwalten die Restaurantzuordnung getrennt von den Zugriffsrechten. Details: [Ausbaustufe](DESIGN-UND-RESTAURANT-AUSBAU.md).
