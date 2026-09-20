# Abrechnung und Automatisierung

Administration → Abrechnung: Aussteller, Steuerangaben und Rechnungsadressen erfassen. Manuelle Bestellungen erlauben bestätigte externe Zahlungen und Rechnungsentwürfe. Ausgestellte Belege speichern Aussteller, Empfänger, Betrag, Steuer und Leistungszeitraum unveränderlich; Nummernvergabe und Wiederholungen sind transaktional abgesichert. Entwürfe können verworfen werden, ausgestellte Rechnungen nur durch einen Stornobeleg aufgehoben werden. Dieser erstattet kein Geld und ändert keinen Modulzugang.

Unter Abrechnungsautomatik werden Stripe-Test-/Livezugang, Webhook-Geheimnis, Rechnungsversand, Erinnerungen und Fristen eingerichtet. Stripe Checkout und Kundenportal ermöglichen Abonnements; wiederkehrende Zahlungen werden über geprüfte Providerereignisse verarbeitet. Der Hintergrundlauf gleicht Zustände ab und bearbeitet Rechnungs-/Mahnungsversand über das konfigurierte SMTP. Unklare Versandversuche werden nicht blind wiederholt. Providerrechnungen lassen sich nicht unabhängig lokal stornieren.

Restaurants sehen nur eigene Belege und Abonnements. Rechnungen stehen als serverseitiges PDF und als Druckansicht bereit; der Versand hängt dasselbe PDF an. [Exportdetails](EXPORTE.md).

Offen bleiben Zahlungs-Rückerstattungen als eigener Workflow, strukturierte E-Rechnungen, grenzüberschreitende Steuerfälle, korrigierte Neuausstellung und Testphasenpolitik. Odoo bleibt vereinbarungsgemäß Platzhalter. Echte Anbieterzugänge und Firmen-/Absenderkonfiguration erfordern Betreiber-Abnahme. Die maßgebliche aktuelle Liste steht in [RESTPUNKTE.md](RESTPUNKTE.md).
