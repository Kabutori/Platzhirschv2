import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
import { useData, Form, DataTable, ErrorBox, Loading } from '@platzhirsch/ui-runtime/components';
const labels: Record<string, string> = {
  delivered: 'Zustellung bestätigt',
  sent: 'SMS versendet',
  queued: 'Beim Versanddienst geplant',
  failed: 'Versand fehlgeschlagen',
  undelivered: 'Nicht zugestellt',
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
    [saved, setSaved] = useState(false),
    [retry, setRetry] = useState<any>(null);
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
            {
              key: 'retry',
              label: 'Aktion',
              render: (r) =>
                ['rejected', 'unknown', 'failed', 'undelivered', 'sending'].includes(r.status) ? (
                  <button onClick={() => setRetry(r)}>Wiederholung prüfen</button>
                ) : (
                  '–'
                ),
            },
          ]}
        />
        {retry && (
          <div className="panel padded">
            <h3>Versand für Buchung {retry.reservation_id} wiederholen</h3>
            <p>
              Prüfen Sie zuerst den Versanddienst. Bei unklarem Status kann eine Wiederholung doppelte
              Nachrichten und zusätzliche SMS-Kosten verursachen. Laufende Versuche bleiben mindestens 15
              Minuten gesperrt; höchstens fünf Versuche pro Ereignis.
            </p>
            <Form
              key={retry.id}
              initial={{}}
              fields={[
                { key: 'password', label: 'Ihr Kennwort', type: 'password', required: true },
                { key: 'confirmed', label: 'Erneuten Versand freigeben', type: 'checkbox', required: true },
                {
                  key: 'acknowledge_duplicate',
                  label: 'Versand geprüft; Risiko doppelter Nachrichten akzeptiert',
                  type: 'checkbox',
                  required: true,
                },
              ]}
              onSave={async (data) => {
                await api(
                  'v1/restaurant/notifications/' + retry.id + '/retry',
                  'POST',
                  {
                    ...data,
                    expected_status: retry.status,
                    expected_attempt_id:
                      q.data.attempts?.find((a: any) => a.event_id === retry.id)?.id ?? null,
                  },
                  tenant,
                );
                setRetry(null);
                await qc.invalidateQueries();
              }}
            />
            <button onClick={() => setRetry(null)}>Abbrechen</button>
          </div>
        )}
        <h3>Letzte Versandversuche</h3>
        <DataTable
          rows={q.data.attempts || []}
          columns={[
            { key: 'event_id', label: 'Ereignis' },
            { key: 'channel', label: 'Kanal' },
            { key: 'status', label: 'Status', render: (r) => labels[r.status] || r.status },
            { key: 'created_at', label: 'Beginn (UTC)' },
            { key: 'updated_at', label: 'Letzter Status (UTC)' },
          ]}
        />
      </section>
    </>
  );
}
