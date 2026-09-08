import { useState, useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, ChevronRight, Code2, Check } from 'lucide-react';
import { api, portal } from '@platzhirsch/ui-runtime/api';
import {
  Row,
  Field,
  today,
  labels,
  pick,
  clock,
  Badge,
  ErrorBox,
  Loading,
  Empty,
  useData,
  Modal,
  Form,
  DataTable,
} from '@platzhirsch/ui-runtime/components';
export function Widget({ tenant }: { tenant?: string }) {
  const q = useData('v1/restaurant/widget', tenant);
  const [open, setOpen] = useState(false);
  const [url, setUrl] = useState('');
  const [embed, setEmbed] = useState('');
  const [error, setError] = useState<unknown>();
  return (
    <>
      <div className="toolbar">
        <p className="muted">
          Buchungszugänge für deine Website. Gültigkeit und Buchungsdauer sind konfigurierbar.
        </p>
        <button className="primary" onClick={() => setOpen(true)}>
          <Plus size={16} />
          Buchungslink erstellen
        </button>
      </div>
      <ErrorBox error={error} />
      {url && (
        <section className="panel padded">
          <h2>Dein neuer Buchungslink</h2>
          <p>Jetzt kopieren — der vollständige Link wird nur einmal angezeigt.</p>
          <code className="secret">{url}</code>
          <button onClick={() => navigator.clipboard.writeText(url)}>Kopieren</button>
          <a className="button" href={url} target="_blank" rel="noreferrer">
            Buchungsseite öffnen
          </a>
          <p className="muted">
            Verlinke ihn auf deiner Restaurant-Website. Der Zugriff erlaubt ausschließlich öffentliche
            Buchungen, keinen Verwaltungszugang.
          </p>
          <h2>Direkt in die Website einbetten</h2>
          <p>
            Füge diesen Code an der gewünschten Stelle deiner Website ein. Das Widget zeigt freie Tische und
            übernimmt die Buchung direkt.
          </p>
          <code className="secret">{embed}</code>
          <button onClick={() => navigator.clipboard.writeText(embed)}>Einbettungscode kopieren</button>
          <p className="muted">
            Für externe Websites muss Platzhirsch über HTTPS erreichbar sein. Bei einer eigenen
            Content-Security-Policy die Platzhirsch-Adresse für script-src und connect-src erlauben.
          </p>
        </section>
      )}
      <section className="panel">
        {q.isPending ? (
          <Loading />
        ) : q.error ? (
          <ErrorBox error={q.error} />
        ) : (
          <DataTable
            rows={q.data}
            columns={[
              { key: 'id', label: 'ID' },
              {
                key: 'origins',
                label: 'Websites',
                render: (r) =>
                  typeof r.origins === 'string' ? JSON.parse(r.origins).join(', ') : r.origins.join(', '),
              },
              { key: 'expires_at', label: 'Gültig bis' },
              { key: 'duration_minutes', label: 'Dauer (Minuten)' },
            ]}
            actions={(r) => (
              <button
                className="danger-text"
                onClick={async () => {
                  if (!confirm('Buchungslink widerrufen? Bestehende Buchungen bleiben erhalten.')) return;
                  try {
                    await api('v1/restaurant/widget/' + r.id, 'DELETE', undefined, tenant);
                    await q.refetch();
                  } catch (e) {
                    setError(e);
                  }
                }}
              >
                Widerrufen
              </button>
            )}
          />
        )}
      </section>
      {open && (
        <Modal title="Buchungszugang erstellen" close={() => setOpen(false)}>
          <Form
            fields={[
              {
                key: 'origin',
                label: 'Website-Ursprung',
                type: 'url',
                required: true,
                help: 'Zum Beispiel https://mein-restaurant.de — ohne Unterseite.',
              },
              {
                key: 'months',
                label: 'Gültigkeit in Monaten',
                type: 'number',
                min: 1,
                max: 12,
                default: 12,
                required: true,
              },
              {
                key: 'duration_minutes',
                label: 'Reservierungsdauer',
                required: true,
                default: 90,
                options: [30, 45, 60, 75, 90, 120, 150, 180, 240].map((value) => ({
                  value,
                  label: `${value} Minuten`,
                })),
              },
              {
                key: 'accent',
                label: 'Akzentfarbe (optional)',
                help: 'Hex-Farbe wie #d0845b; leer lassen für das Platzhirsch-Design.',
              },
            ]}
            onSave={async (data) => {
              const result = await api(
                'v1/restaurant/widget',
                'POST',
                {
                  origins: [data.origin],
                  months: data.months,
                  duration_minutes: data.duration_minutes,
                  accent: data.accent || null,
                },
                tenant,
              );
              setUrl(result.url);
              setEmbed(result.embed);
              setOpen(false);
              await q.refetch();
            }}
          />
        </Modal>
      )}
    </>
  );
}
export function Booking() {
  const token = new URLSearchParams(location.hash.split('?')[1]).get('token') || '';
  const q = useData('widget/' + token);
  const [result, setResult] = useState<Row | null>(null);
  const [key] = useState(() => crypto.randomUUID());
  return (
    <div className="auth-page">
      <div className="booking-wrap">
        <div className="brand large">
          <b>P</b>
          <div>
            <h1>{q.data?.name || 'Tisch reservieren'}</h1>
            <small>RESERVIEREN MIT PLATZHIRSCH</small>
          </div>
        </div>
        <section className="auth-card">
          {q.isPending ? (
            <Loading />
          ) : q.error ? (
            <ErrorBox error={q.error} />
          ) : result ? (
            <div className="confirmation">
              <Check size={32} />
              <h2>Dein Tisch ist reserviert.</h2>
              <p>
                Buchungsnummer <strong>#{result.id}</strong>
              </p>
              <p>Bitte notiere diese Nummer. Bei Änderungen kontaktiere das Restaurant direkt.</p>
            </div>
          ) : (
            <>
              <h2>Ein Platz für dich.</h2>
              <p className="muted">
                Reservierungsdauer: {q.data.duration_minutes} Minuten · Uhrzeiten in {q.data.timezone}
              </p>
              <Form
                label="Verbindlich reservieren"
                fields={[
                  { key: 'guest_name', label: 'Dein Name', required: true },
                  { key: 'email', label: 'E-Mail', type: 'email', required: true },
                  { key: 'phone', label: 'Telefon' },
                  {
                    key: 'party_size',
                    label: 'Personen',
                    type: 'number',
                    required: true,
                    min: 1,
                    max: 50,
                    default: 2,
                  },
                  {
                    key: 'table_id',
                    label: 'Tisch',
                    required: true,
                    options: q.data.tables.map((t: Row) => ({
                      value: t.id,
                      label: `${t.name} · ${t.capacity} Plätze`,
                    })),
                  },
                  { key: 'starts_at', label: 'Datum und Uhrzeit', type: 'datetime-local', required: true },
                  { key: 'notes', label: 'Wünsche / Hinweise', type: 'textarea' },
                  {
                    key: 'consent',
                    label: 'Meine Angaben dürfen zur Bearbeitung dieser Reservierung verwendet werden.',
                    type: 'checkbox',
                    required: true,
                  },
                ]}
                onSave={async (data) => {
                  const response = await fetch('/api/widget/' + token, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                      ...data,
                      request_key: key,
                      duration_minutes: q.data.duration_minutes,
                    }),
                  });
                  const body = await response.json();
                  if (!response.ok)
                    throw new Error(
                      body.errors
                        ? Object.values(body.errors).flat().join(' ')
                        : body.message || 'Buchung fehlgeschlagen.',
                    );
                  setResult(body);
                }}
              />
            </>
          )}
        </section>
      </div>
    </div>
  );
}
