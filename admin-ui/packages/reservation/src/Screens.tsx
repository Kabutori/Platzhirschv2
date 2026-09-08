import { useState, useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Search, Armchair, Download, CalendarDays } from 'lucide-react';
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
function allowed(user: Row, permission: string) {
  return user.permissions?.includes('*') || user.permissions?.includes(permission);
}
export function RestaurantResource({ resource, tenant }: { resource: string; tenant?: string }) {
  const path = 'v1/restaurant/' + resource;
  const q = useData(path, tenant);
  const rooms = useQuery({
    queryKey: ['v1/restaurant/rooms', tenant],
    queryFn: () => api('v1/restaurant/rooms', 'GET', undefined, tenant),
    enabled: resource === 'tables',
  });
  const [form, setForm] = useState<Row | null>(null);
  const [error, setError] = useState<unknown>();
  const qc = useQueryClient();
  const weekdays = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
  const fields: Field[] =
    resource === 'rooms'
      ? [
          { key: 'name', label: 'Raumname', required: true },
          {
            key: 'color',
            label: 'Kennfarbe',
            required: true,
            default: 'terracotta',
            options: pick(['terracotta', 'sage', 'sky', 'mustard', 'plum', 'slate']),
          },
          { key: 'outdoor', label: 'Außenbereich', type: 'checkbox' },
        ]
      : resource === 'tables'
        ? [
            { key: 'name', label: 'Tischname', required: true },
            {
              key: 'room_id',
              label: 'Raum',
              required: true,
              options: rooms.data?.map((r: Row) => ({ value: r.id, label: r.name })) || [],
            },
            {
              key: 'capacity',
              label: 'Sitzplätze',
              type: 'number',
              required: true,
              min: 1,
              max: 50,
              default: 4,
            },
            { key: 'active', label: 'Für Buchungen verfügbar', type: 'checkbox', default: true },
          ]
        : resource === 'hours'
          ? [
              {
                key: 'weekday',
                label: 'Wochentag',
                required: true,
                options: weekdays.map((label, i) => ({ value: i + 1, label })),
              },
              { key: 'opens', label: 'Öffnet um', type: 'time', required: true },
              { key: 'closes', label: 'Schließt um', type: 'time', required: true },
            ]
          : [
              { key: 'date', label: 'Datum', type: 'date', required: true },
              { key: 'closed', label: 'Ganztägig geschlossen', type: 'checkbox', default: true },
              { key: 'opens', label: 'Öffnet um (wenn geöffnet)', type: 'time' },
              { key: 'closes', label: 'Schließt um (wenn geöffnet)', type: 'time' },
              { key: 'note', label: 'Anlass / Hinweis' },
            ];
  const columns = fields.map((f) => ({
    key: f.key,
    label: f.label,
    render: (r: Row) =>
      f.options ? (
        f.options.find((o) => String(o.value) === String(r[f.key]))?.label || r[f.key]
      ) : f.type === 'checkbox' ? (
        <Badge value={Boolean(r[f.key])} />
      ) : (
        r[f.key] || '—'
      ),
  }));
  return (
    <>
      <div className="toolbar">
        <p className="muted">
          {resource === 'hours'
            ? 'Mehrere Zeitfenster pro Tag möglich. Buchungen müssen vollständig in ein Zeitfenster passen.'
            : resource === 'special-days'
              ? 'Sondertage ersetzen die regulären Öffnungszeiten.'
              : 'Deine Restaurant-Konfiguration'}
        </p>
        <button className="primary" onClick={() => setForm({})}>
          <Plus size={16} />
          Hinzufügen
        </button>
      </div>
      <ErrorBox error={error} />
      <section className="panel">
        {q.isPending ? (
          <Loading />
        ) : q.error ? (
          <ErrorBox error={q.error} />
        ) : (
          <DataTable
            rows={q.data}
            columns={columns}
            actions={(r) => (
              <>
                <button
                  onClick={() =>
                    setForm({ ...r, opens: r.opens?.slice(0, 5), closes: r.closes?.slice(0, 5) })
                  }
                >
                  Bearbeiten
                </button>
                <button
                  className="danger-text"
                  onClick={async () => {
                    if (!confirm('Diesen Eintrag löschen?')) return;
                    try {
                      await api(path + '/' + r.id, 'DELETE', undefined, tenant);
                      await qc.invalidateQueries();
                    } catch (e) {
                      setError(e);
                    }
                  }}
                >
                  Löschen
                </button>
              </>
            )}
          />
        )}
      </section>
      {form && (
        <Modal title={form.id ? 'Eintrag bearbeiten' : 'Neuer Eintrag'} close={() => setForm(null)}>
          <Form
            fields={fields}
            initial={form}
            onSave={async (data) => {
              await api(path + (form.id ? '/' + form.id : ''), form.id ? 'PATCH' : 'POST', data, tenant);
              setForm(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
    </>
  );
}
function localInput(utc: string, tz: string) {
  const parts = new Intl.DateTimeFormat('sv-SE', {
    timeZone: tz,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(utc.replace(' ', 'T') + 'Z'));
  const p = Object.fromEntries(parts.map((v) => [v.type, v.value]));
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
}
export function Reservations({
  tenant,
  mode,
  go,
  user,
}: {
  tenant?: string;
  mode: string;
  go: (s: string) => void;
  user: Row;
}) {
  const [date, setDate] = useState(today());
  const q = useData('v1/restaurant/reservations?date=' + date, tenant);
  const tables = useData('v1/restaurant/tables', tenant);
  const profile = useData('v1/restaurant/profile', tenant);
  const qc = useQueryClient();
  const [form, setForm] = useState<Row | null>(null);
  const [error, setError] = useState<unknown>();
  const tz = profile.data?.timezone || 'Europe/Berlin';
  const rows: Row[] = q.data || [];
  const live = rows.filter((r) => !['cancelled', 'no_show'].includes(r.status));
  const fields: Field[] = [
    { key: 'guest_name', label: 'Name des Gastes', required: true },
    { key: 'email', label: 'E-Mail', type: 'email' },
    { key: 'phone', label: 'Telefon' },
    {
      key: 'table_id',
      label: 'Tisch',
      required: true,
      options:
        tables.data
          ?.filter((r: Row) => r.active)
          .map((r: Row) => ({ value: r.id, label: `${r.name} · ${r.capacity} Plätze` })) || [],
    },
    { key: 'party_size', label: 'Personen', type: 'number', required: true, min: 1, max: 50, default: 2 },
    {
      key: 'starts_at',
      label: `Beginn (${tz})`,
      type: 'datetime-local',
      required: true,
      default: date + 'T18:00',
    },
    {
      key: 'duration_minutes',
      label: 'Dauer in Minuten',
      type: 'number',
      required: true,
      min: 15,
      max: 360,
      default: 90,
    },
    {
      key: 'status',
      label: 'Status',
      required: true,
      default: 'confirmed',
      options: pick([
        'confirmed',
        'seated',
        'completed',
        'no_show',
        ...(allowed(user, 'reservation.cancel') ? ['cancelled'] : []),
      ]),
    },
    { key: 'notes', label: 'Notizen', type: 'textarea' },
  ];
  function edit(r: Row) {
    setForm({
      ...r,
      starts_at: localInput(r.starts_at, tz),
      duration_minutes:
        (Date.parse(r.ends_at.replace(' ', 'T') + 'Z') - Date.parse(r.starts_at.replace(' ', 'T') + 'Z')) /
        60000,
    });
  }
  async function cancel(r: Row) {
    if (!confirm('Reservierung wirklich stornieren?')) return;
    try {
      await api('v1/restaurant/reservations/' + r.id + '/cancel', 'POST', {}, tenant);
      await qc.invalidateQueries();
    } catch (e) {
      setError(e);
    }
  }
  async function download() {
    try {
      const response = await fetch('/api/v1/restaurant/export?date=' + date, {
        credentials: 'same-origin',
        headers: { 'X-Platzhirsch-Portal': portal, ...(tenant ? { 'X-Tenant-ID': tenant } : {}) },
      });
      if (!response.ok) throw new Error('Export fehlgeschlagen.');
      const url = URL.createObjectURL(await response.blob());
      const a = document.createElement('a');
      a.href = url;
      a.download = 'reservierungen-' + date + '.csv';
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      setError(e);
    }
  }
  return (
    <>
      <div className="toolbar">
        <div className="date-picker">
          <CalendarDays size={17} />
          <input
            type="date"
            aria-label="Reservierungsdatum"
            value={date}
            onChange={(e) => setDate(e.target.value)}
          />
          <button onClick={() => setDate(today())}>Heute</button>
        </div>
        <div className="button-row">
          <button disabled={!allowed(user, 'reservation.export')} onClick={download}>
            <Download size={16} />
            CSV
          </button>
          <button
            disabled={!allowed(user, 'reservation.write')}
            className="primary"
            onClick={() => setForm({ request_key: crypto.randomUUID() })}
          >
            <Plus size={16} />
            Reservierung
          </button>
        </div>
      </div>
      <ErrorBox error={error} />
      <ErrorBox error={tables.error} />
      {mode === 'overview' && (
        <div className="stats">
          {[
            ['Reservierungen', live.length, 'Für den gewählten Tag'],
            ['Gäste', live.reduce((n, r) => n + r.party_size, 0), 'Erwartete Personen'],
            ['Tische', tables.data?.filter((r: Row) => r.active).length || 0, 'Für Buchungen verfügbar'],
            ['Stornierungen', rows.filter((r) => r.status === 'cancelled').length, 'Für den gewählten Tag'],
          ].map(([label, value, sub]) => (
            <section className="stat" key={label}>
              <span>{label}</span>
              <strong>{value}</strong>
              <small>{sub}</small>
            </section>
          ))}
        </div>
      )}
      <section className="panel">
        <div className="panel-head">
          <h2>{mode === 'table-plan' ? 'Belegung nach Tisch' : 'Reservierungen'}</h2>
          <small>
            {new Date(date + 'T12:00:00').toLocaleDateString('de-DE', {
              weekday: 'long',
              day: 'numeric',
              month: 'long',
            })}
          </small>
        </div>
        {q.isPending ? (
          <Loading />
        ) : q.error ? (
          <ErrorBox error={q.error} />
        ) : mode === 'table-plan' ? (
          <div className="table-plan">
            {tables.data?.map((table: Row) => (
              <section key={table.id} className="table-lane">
                <div>
                  <Armchair size={20} />
                  <strong>{table.name}</strong>
                  <small>{table.capacity} Plätze</small>
                </div>
                <div>
                  {live.filter((r) => r.table_id === table.id).length ? (
                    live
                      .filter((r) => r.table_id === table.id)
                      .map((r) => (
                        <button
                          disabled={!allowed(user, 'reservation.write')}
                          className="booking-block"
                          key={r.id}
                          onClick={() => edit(r)}
                        >
                          <strong>
                            {clock(r.starts_at, tz)}–{clock(r.ends_at, tz)}
                          </strong>
                          <span>
                            {r.guest_name} · {r.party_size} Personen
                          </span>
                          <Badge value={r.status} />
                        </button>
                      ))
                  ) : (
                    <span className="muted">Keine Reservierungen</span>
                  )}
                </div>
              </section>
            ))}
          </div>
        ) : (
          <DataTable
            rows={rows}
            columns={[
              {
                key: 'starts_at',
                label: 'Zeit',
                render: (r) => (
                  <code>
                    {clock(r.starts_at, tz)}–{clock(r.ends_at, tz)}
                  </code>
                ),
              },
              {
                key: 'guest_name',
                label: 'Gast',
                render: (r) => (
                  <div className="cell-title">
                    <strong>{r.guest_name}</strong>
                    <small>{r.phone || r.email || 'Keine Kontaktdaten'}</small>
                  </div>
                ),
              },
              { key: 'party_size', label: 'Personen' },
              { key: 'table_name', label: 'Tisch' },
              { key: 'status', label: 'Status', render: (r) => <Badge value={r.status} /> },
            ]}
            actions={(r) => (
              <>
                {allowed(user, 'reservation.write') && <button onClick={() => edit(r)}>Bearbeiten</button>}
                {allowed(user, 'reservation.cancel') && r.status !== 'cancelled' && (
                  <button className="danger-text" onClick={() => cancel(r)}>
                    Stornieren
                  </button>
                )}
              </>
            )}
          />
        )}
      </section>
      {!tables.isPending && !tables.data?.length && (
        <div className="notice">
          Lege zuerst Räume und Tische an und hinterlege die Öffnungszeiten.{' '}
          <button onClick={() => go('rooms')}>Räume öffnen</button>
        </div>
      )}
      {form && (
        <Modal title={form.id ? 'Reservierung bearbeiten' : 'Neue Reservierung'} close={() => setForm(null)}>
          <Form
            fields={fields}
            initial={form}
            onSave={async (data) => {
              await api(
                'v1/restaurant/reservations' + (form.id ? '/' + form.id : ''),
                form.id ? 'PATCH' : 'POST',
                { ...data, request_key: form.request_key, version: form.version },
                tenant,
              );
              setForm(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
    </>
  );
}
