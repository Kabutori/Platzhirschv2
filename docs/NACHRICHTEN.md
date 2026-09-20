# Buchungsnachrichten: Status und Wiederholung

Restaurant → Buchungsnachrichten aktiviert E-Mail und/oder SMS ausdrücklich. SMTP wird zentral in der Administration eingerichtet; SMS verwendet das lokal konfigurierte Twilio-Konto. Der Scheduler bearbeitet höchstens 20 fällige Ereignisse je Restaurant und Lauf. Änderungen an Buchungen ersetzen ältere geplante Ereignisse.

Die Oberfläche zeigt Ereignisse und die letzten 100 Versandversuche des eigenen Restaurants. `accepted` bedeutet Annahme durch SMTP/Twilio. Nur ein gültiger SMS-Callback kann `delivered` setzen. Es werden keine Empfänger, Texte oder Provider-Geheimnisse in der Versuchshistorie gespeichert.

## Twilio-Rückmeldungen

`APP_URL` muss die öffentliche HTTPS-Adresse ohne zusätzlichen Pfad enthalten. Beim Versand wird eine zufällige Versuch-ID im `StatusCallback` mitgesendet. `POST /api/notification/status/{id}` prüft `X-Twilio-Signature` gegen den vollständigen konfigurierten URL und sämtliche unveränderten Formularparameter, außerdem AccountSid, MessageSid und die gespeicherte Korrelation. Unsignierte, manipulierte oder mehrdeutige Formulare werden abgewiesen. Terminale Zustände werden durch verspätete Meldungen nicht zurückgesetzt; alte Versuche überschreiben keine neue Wiederholung.

Grundlage: [Twilio Request Validation](https://www.twilio.com/docs/usage/security), [Outbound Message Status](https://www.twilio.com/docs/messaging/guides/track-outbound-message-status).

## Kontrollierte Wiederholung

Nur Benutzer mit `restaurant.configure` können fehlgeschlagene/unklare Ereignisse erneut vormerken. Erforderlich sind aktuelles Kennwort, Freigabe, Bestätigung des Doppelversandrisikos sowie der noch aktuelle Status und die Versuch-ID. `sending` bleibt mindestens 15 Minuten gesperrt. Höchstens fünf Versuche; überholte Buchungsversionen und vergangene Erinnerungen sind nicht wiederholbar. Bereits angenommene oder zugestellte Ereignisse können nicht erneut beauftragt werden. Nach Freigabe versendet der Scheduler; die HTTP-Anfrage sendet selbst keine Nachricht.

Externe API: `POST /api/external/v1/notification/restaurant/notifications/{id}/retry`, zusätzlich Tokenrechte, Idempotenzschlüssel und einmalige Portalbestätigung. SMTP-Bounces und Lesebestätigungen benötigen einen späteren anbieterspezifischen Ausbau. Ein Live-Test mit Betreiberzugängen ist weiterhin offen.
