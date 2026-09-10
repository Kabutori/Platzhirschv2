import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
import { useData, Form, DataTable, ErrorBox, Loading } from '@platzhirsch/ui-runtime/components';
const labels: Record<string, string> = {
  pending: 'Geplant',
  sending: 'Versand begonnen – bei Abbruch prüfen',
  accepted: 'An Versanddienst übergeben',
  superseded: 'Überholt / deaktiviert',
  invalid_recipient: 'Empfänger fehlt oder ist ungültig',
  rejected: 'Vom Versanddienst abgelehnt',
  unknown: 'Zustellung unklar – manuell prüfen',
};
export default function Notifications({ tenant }: { tenant?: string }) {
  const q = useData('v1/restaurant/notifications', tenant),
    qc = useQueryClient(),
    [saved, setSaved] = useState(false);
  if (q.isPending) return <Loading />;
  if (q.error) return <ErrorBox error={q.error} />;
  return (
    <>
      <section className="panel padded">
        <h2>Buchungsnachrichten</h2>
        <p>
          Bestätigungen, Änderungen und Stornierungen werden automatisch vorgemerkt. Erinnerungen gelten für
          noch bestätigte, bevorstehende Buchungen. Änderungen gelten für neue Buchungsereignisse;
          deaktivierte Kanäle senden auch vorgemerkte Nachrichten nicht mehr.
        </p>
        <p>
          E-Mail: {q.data.ready.email ? 'SMTP eingerichtet' : 'SMTP fehlt'} · SMS:{' '}
          {q.data.ready.sms ? 'Twilio eingerichtet' : 'Twilio fehlt'}
        </p>
        <Form
          initial={q.data.settings}
          fields={[
            { key: 'email_enabled', label: 'E-Mail-Benachrichtigungen aktivieren', type: 'checkbox' },
            {
              key: 'sms_enabled',
              label: 'Kostenpflichtigen SMS-Versand aktivieren',
              type: 'checkbox',
              help: 'Versand über das eingerichtete Twilio-Konto. Telefonnummern im internationalen Format, beispielsweise +491701234567.',
            },
            {
              key: 'reminder_minutes',
              label: 'Erinnerung vor Beginn (Minuten)',
              type: 'number',
              required: true,
              min: 0,
              max: 10080,
              help: '0 deaktiviert Erinnerungen. Beispiel: 120 für zwei Stunden.',
            },
          ]}
          onSave={async (data) => {
            await api('v1/restaurant/notifications', 'PATCH', data, tenant);
            setSaved(true);
            await qc.invalidateQueries();
          }}
        />
        {saved && <p role="status">Benachrichtigungseinstellungen gespeichert.</p>}
      </section>
      <section className="panel padded">
        <h2>Letzte 50 Versandereignisse</h2>
        <p>
          „Übergeben“ bestätigt die Annahme durch SMTP/Twilio, nicht das Lesen oder die endgültige Zustellung.
          Unklare Zustellungen werden nicht automatisch erneut gesendet.
        </p>
        <DataTable
          rows={q.data.recent}
          columns={[
            { key: 'reservation_id', label: 'Buchung' },
            { key: 'channel', label: 'Kanal' },
            {
              key: 'kind',
              label: 'Art',
              render: (r) => (r.kind === 'reminder' ? 'Erinnerung' : 'Buchungsänderung / Bestätigung'),
            },
            { key: 'due_at', label: 'Geplant (UTC)' },
            { key: 'status', label: 'Status', render: (r) => labels[r.status] || r.status },
          ]}
        />
      </section>
    </>
  );
}
