# Landingpage und geprüfte Registrierung

Die Startseite `/` und `/registrierung` zeigen die neue Landingpage der **Windows-/Laravel-Anwendung im GitHub-Projekt**. Die getrennte Sites-Vorschau wird nicht verändert. Das Design nutzt gemeinsame Platzhirsch-Tokens, lokale Schriften und kantige Karten. Blade rendert Formulare und Fehler serverseitig; JavaScript ist für die Registrierung nicht erforderlich.

## Ablauf

1. Betriebsname, Ansprechpartner, Website, geschäftliche E-Mail und Bestätigung der Datenschutzhinweise eingeben.
2. Die Website-Adresse wird auf HTTPS normalisiert. Ohne Schema wird HTTPS ergänzt. Zugangsdaten, fremde Protokolle, Sonderports, URL-Parameter und IP-Adressen sind nicht zulässig.
3. Website-Domain und E-Mail-Domain müssen exakt übereinstimmen; ein führendes `www.` der Website wird ignoriert. Beispiel: `www.linde.de` und `kontakt@linde.de` passen, `linde.de` und `kontakt@gmail.com` nicht. Andere Subdomains, abweichende Konzern-Domains und Freemail-Ausnahmen werden nicht automatisch freigegeben.
4. Die angegebene HTML-Seite wird ohne JavaScript-Ausführung gelesen. Je Kategorie muss mindestens ein Branchenbegriff **und** ein Angebotsbegriff vorkommen: etwa „Restaurant“ + „Speisekarte“ oder „Hotel“ + „Zimmer“. Wörterbücher liegen in `app/config/registration.php`. Kommentare, Script-, Style-, Template- und Noscript-Inhalte werden nicht ausgewertet. Ein bloßes „Hotel“ reicht nicht.
5. Nach bestandener Prüfung geht ein einmaliger, 24 Stunden gültiger Bestätigungslink an die angegebene E-Mail. Bis dahin existiert nur eine Registrierungsanfrage, keine Mandantendatenbank und kein Benutzer.
6. Der Link öffnet eine Bestätigungsseite. Erst der POST mit einem mindestens zwölf Zeichen langen, bestätigten Passwort erstellt Konto und Mandant und reiht die Datenbankeinrichtung beim bestehenden Provisioning-Worker ein. Ein GET, etwa durch einen Mail-Sicherheitsscanner, aktiviert nichts.
7. Der Restaurantlogin bleibt `/restaurant/login`. Die Einrichtung läuft asynchron; die Erfolgsseite behauptet keinen bereits abgeschlossenen Datenbankaufbau.

## Betrieb freischalten

Für bestehende Installationen bleiben neue Registrierungen standardmäßig deaktiviert. Erforderlich sind eine HTTPS-`APP_URL`, funktionsfähiges SMTP (`MAIL_MAILER=smtp` und die vorhandenen `MAIL_*`-Werte), Links zu den tatsächlichen Betreiberinformationen und:

```dotenv
REGISTRATION_ENABLED=true
REGISTRATION_PRIVACY_URL=https://ihre-domain.de/datenschutz
REGISTRATION_IMPRINT_URL=https://ihre-domain.de/impressum
```

Die Beispieladressen müssen ersetzt werden. Es wurden keine Betreiber- oder Rechtstexte erfunden. Nach Konfigurationsänderungen den Laravel-Konfigurationscache erneuern (`php artisan config:cache`). Öffentliche HTTPS-Freigabe und SMTP-Zugänge sind separate Betriebseinstellungen; dieser Code aktiviert sie nicht. Der Windows-Paketbau enthält jetzt auch die Blade-Views unter `resources` sowie die Landingpage-Assets.

## Schutz und Nachvollziehbarkeit

- Pro IP höchstens zwei Anfragen pro Minute und fünf pro Tag, pro E-Mail-Domain drei pro Tag, installationsweit 100 pro Tag. Die Grenzwerte zählen Formularanfragen einschließlich fehlerhafter Eingaben. Zusätzlich CSRF und Honeypot; vorhandene unbestätigte Anfragen werden nicht durch erneutes Absenden überschrieben.
- DNS-Ziele werden auf öffentliche Adressen geprüft. Die geprüfte Adresse wird für den HTTPS-Abruf fest vorgegeben; interne, reservierte, lokale und IPv4-abgebildete IPv6-Ziele werden abgelehnt. Kein Proxy, keine automatische Weiterleitung, keine Zugangsdaten und keine Cookies.
- Maximal drei Weiterleitungen innerhalb derselben Domain einschließlich `www.`, jeweils mit neuer Adressprüfung. Keine Weiterleitung auf HTTP oder fremde Domains. Je Abruf maximal acht Sekunden, 512 KiB entpackter Inhalt und 32 KiB Header.
- Speicherung nur von normalisierter URL, Kontaktangaben, Kategorie und gefundenen Begriffen; kein vollständiger Websiteinhalt. In der Datenbank steht nur der Hash des Bestätigungstokens. Bestätigungsseiten sind nicht cachebar und senden keinen Referrer.
- Atomare Kontoanlage, einmaliger Tokenverbrauch und bestehende Rollen-/Provisioning-Schnittstellen. Abgelaufene unbestätigte Anfragen werden täglich durch `registration:prune` entfernt. Eine bestätigte Registrierung bleibt als Herkunftsnachweis erhalten.
- Mailfehler erzeugen keinen vorgetäuschten Erfolg. Nach fehlgeschlagenem Versand kann die Anfrage erneut gestellt werden. SMTP-Fehlerdetails werden nicht an den Besucher weitergegeben.

## Einzelprüfung ohne Registrierung

```bash
php artisan registration:check-website https://www.linde.de kontakt@linde.de
```

Dieser Befehl prüft Website und Domain, erzeugt kein Konto und verschickt keine Mail. Exitcode 0 bedeutet passende Kategorie, Exitcode 1 einen Prüf-/Validierungsfehler. Er bestätigt nicht den Besitz der Mailadresse.

## Grenzen

Die Keyword-Prüfung ist eine einfache Eignungsprüfung, keine verlässliche Unternehmensverifikation. Sie kann passende Betriebe übersehen und gezielt präparierte Seiten akzeptieren. Reine JavaScript-Seiten, blockierte Abrufe oder ausschließlich als PDF vorhandene Angebote können nicht automatisch freigegeben werden. Besucher können eine passende Unterseite derselben Domain angeben; eine manuelle Ausnahmeverwaltung ist nicht enthalten.

Die Registrierung löst keine Zahlung aus und aktiviert keine kostenpflichtigen Zusatzmodule. Preis-/Vertragsabschluss und Testphasenpolitik bleiben separate offene Konzeptpunkte. Die Landingpage verwendet derzeit die bestehende IIS-Anwendung; eine separate öffentliche IIS-Site mit eigenem Anwendungspool aus dem Zielkonzept ist noch nicht automatisiert.

## Prüfungen

PHP-Tests decken Domain-/Keyword-Regeln, gesperrte Adressen, Bestätigung vor Kontoanlage, einmalige Links, Ablauf/Bereinigung, deaktivierte Registrierung, Wiederholungen und Limits ab. Browserprüfungen verwenden die tatsächlichen serverseitig gerenderten Blade-Views und erzeugen Desktop-/Mobilaufnahmen. Echte Anbieter-Mails oder Live-Registrierungen werden in CI nicht ausgelöst.

## SMTP in der Admin-Oberfläche

Unter **System-Einstellungen → E-Mail / SMTP** können Systemadministratoren Server, Port, STARTTLS/TLS, Benutzername, Passwort und Absender speichern. Die Daten werden verschlüsselt in der Plattformdatenbank gespeichert und überschreiben die Mail-Umgebungskonfiguration, sobald eine Konfiguration gespeichert wurde. Ein leeres Passwort behält das vorhandene bei; Entfernen erfordert die eigene Checkbox. API und Audit geben keine Zugangsdaten zurück. Ohne gespeicherte Konfiguration gelten weiterhin die bisherigen Umgebungswerte.

Die Einstellungen werden vor HTTP-Anfragen und Hintergrundjobs neu geladen; ein Worker-Neustart ist deshalb nicht erforderlich. Der Button „Gespeicherte Verbindung prüfen“ prüft TLS und SMTP-Anmeldung ohne eine Nachricht zu senden. Zustellbarkeit ist separat zu prüfen. Module und Support bleiben eigene Hauptbereiche, die Datenbankserververwaltung ist zusätzlich direkt unter **Serververwaltung** erreichbar.

### WPOven als Testvoreinstellung bei Neuinstallation

Neue Windows-Installationen und die `.env.example` enthalten `smtp.freesmtpservers.com`, Port `25`, ohne Benutzername/Passwort. Die Admin-Maske zeigt diese Voreinstellung auch an, solange kein eigener Server konfiguriert wurde. Bereits gespeicherte SMTP-Einstellungen werden beibehalten.

WPOven ist ein öffentliches Capture-Postfach: Es fängt Nachrichten ab, zeigt sie anhand der Empfänger-/Absenderadresse an und löscht sie nach 48 Stunden. Es ist kein Versanddienst für echte Kundenmails. Deshalb bleibt `MAIL_MAILER=log`; das Aktivieren von produktivem Versand über diesen Host ist gesperrt. Verbindungstests verschicken keine Nachricht. Für echte Registrierungen einen eigenen SMTP-Anbieter mit STARTTLS oder TLS in der Admin-Oberfläche eintragen und aktivieren. Der WPOven-Testmodus ohne TLS ist ausschließlich für diesen festen Host zulässig.

Quelle: https://www.wpoven.com/tools/free-smtp-server-for-testing
