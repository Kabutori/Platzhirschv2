import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
import {
  useData,
  Form,
  Modal,
  DataTable,
  ErrorBox,
  Loading,
  today,
  clock,
  type Row,
  type Field,
} from '@platzhirsch/ui-runtime/components';
import Calendar from './Calendar';
const names: Record<string, string> = {
  waiting: 'Wartend',
  contacted: 'Kontaktiert',
  booked: 'Übernommen',
  cancelled: 'Storniert',
};
export default function Waitlist({ user, tenant }: { user: Row; tenant?: string }) {
  const [date, setDate] = useState(today()),
    [search, setSearch] = useState(''),
    [status, setStatus] = useState('open'),
    [form, setForm] = useState<Row | null>(null),
    [booking, setBooking] = useState<Row | null>(null);
  const q = useData('v1/restaurant/waitlist?date=' + date, tenant),
    profile = useData('v1/restaurant/profile', tenant),
    tables = useData('v1/restaurant/tables', tenant),
    qc = useQueryClient();
  const can = (p: string) => user.permissions?.includes('*') || user.permissions?.includes(p),
    write = can('waitlist.write'),
    canBook = write && can('reservation.write') && can('reservation.read');
  const tz = profile.data?.timezone || 'Europe/Berlin';
  function local(utc: string) {
    const parts = new Intl.DateTimeFormat('sv-SE', {
      timeZone: tz,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    }).formatToParts(new Date(utc.replace(' ', 'T') + 'Z'));
    const p = Object.fromEntries(parts.map((x) => [x.type, x.value]));
    return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
  }
  const fields: Field[] = [
    { key: 'guest_name', label: 'Gast', required: true },
    { key: 'party_size', label: 'Personen', type: 'number', required: true, min: 1, max: 50, default: 2 },
    {
      key: 'requested_at',
      label: 'Wunschtermin (' + tz + ')',
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
    { key: 'email', label: 'E-Mail', type: 'email' },
    { key: 'phone', label: 'Telefon' },
    { key: 'notes', label: 'Notizen', type: 'textarea' },
    {
      key: 'status',
      label: 'Status',
      default: 'waiting',
      options: ['waiting', 'contacted', 'cancelled'].map((value) => ({ value, label: names[value] })),
    },
  ];
  const rows = (q.data || []).filter(
    (r: Row) =>
      (status === 'all' ||
        (status === 'open' ? ['waiting', 'contacted'].includes(r.status) : r.status === status)) &&
      [r.guest_name, r.email, r.phone, r.notes].some((v) =>
        String(v || '')
          .toLowerCase()
          .includes(search.toLowerCase()),
      ),
  );
  return (
    <>
      <div className="toolbar">
        <div className="date-picker">
          <Calendar value={date} onChange={setDate} today={today()} />
          <input
            type="date"
            aria-label="Wartelistendatum"
            value={date}
            onChange={(e) => {
              if (e.target.value) setDate(e.target.value);
            }}
          />
        </div>
        {write && (
          <button className="primary" onClick={() => setForm({ request_key: crypto.randomUUID() })}>
            Auf Warteliste setzen
          </button>
        )}
      </div>
      <p className="muted">
        Die Warteliste hält noch keinen Tisch frei. Bei der Übernahme werden Öffnungszeiten, Plätze und
        Konflikte erneut geprüft.
      </p>
      <div className="reservation-filters">
        <label>
          Gast oder Kontakt
          <input type="search" value={search} onChange={(e) => setSearch(e.target.value)} />
        </label>
        <label>
          Status
          <select aria-label="Wartelistenstatus" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="open">Offene Einträge</option>
            <option value="all">Alle</option>
            {Object.entries(names).map(([v, n]) => (
              <option key={v} value={v}>
                {n}
              </option>
            ))}
          </select>
        </label>
      </div>
      <section className="panel">
        {q.isPending ? (
          <Loading />
        ) : q.error ? (
          <ErrorBox error={q.error} />
        ) : (
          <DataTable
            rows={rows}
            columns={[
              { key: 'guest_name', label: 'Gast' },
              { key: 'party_size', label: 'Personen' },
              { key: 'requested_at', label: 'Wunschtermin', render: (r) => clock(r.requested_at, tz) },
              { key: 'phone', label: 'Telefon' },
              { key: 'notes', label: 'Notizen' },
              { key: 'status', label: 'Status', render: (r) => names[r.status] },
              { key: 'reservation_id', label: 'Buchungsnummer' },
            ]}
            actions={(r) => (
              <>
                {write && r.status !== 'booked' && (
                  <button onClick={() => setForm({ ...r, requested_at: local(r.requested_at) })}>
                    Bearbeiten
                  </button>
                )}
                {canBook && ['waiting', 'contacted'].includes(r.status) && (
                  <button onClick={() => setBooking(r)}>In Reservierung übernehmen</button>
                )}
              </>
            )}
          />
        )}
      </section>
      {form && (
        <Modal
          title={form.id ? 'Wartelisteneintrag bearbeiten' : 'Neuer Wartelisteneintrag'}
          close={() => setForm(null)}
        >
          <Form
            fields={fields}
            initial={form}
            onSave={async (data) => {
              await api(
                'v1/restaurant/waitlist' + (form.id ? '/' + form.id : ''),
                form.id ? 'PATCH' : 'POST',
                { ...data, version: form.version, request_key: form.request_key },
                tenant,
              );
              setForm(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
      {booking && (
        <Modal title={'Reservierung für ' + booking.guest_name} close={() => setBooking(null)}>
          <ErrorBox error={tables.error} />
          <Form
            fields={[
              {
                key: 'table_id',
                label: 'Tisch',
                required: true,
                options: (tables.data || [])
                  .filter((t: Row) => t.active && t.capacity >= booking.party_size)
                  .map((t: Row) => ({ value: t.id, label: t.name + ' · ' + t.capacity + ' Plätze' })),
              },
              { key: 'starts_at', label: 'Beginn (' + tz + ')', type: 'datetime-local', required: true },
              {
                key: 'duration_minutes',
                label: 'Dauer in Minuten',
                type: 'number',
                required: true,
                min: 15,
                max: 360,
              },
            ]}
            initial={{ starts_at: local(booking.requested_at), duration_minutes: booking.duration_minutes }}
            onSave={async (data) => {
              await api(
                'v1/restaurant/waitlist/' + booking.id + '/book',
                'POST',
                { ...data, version: booking.version },
                tenant,
              );
              setBooking(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
    </>
  );
}
