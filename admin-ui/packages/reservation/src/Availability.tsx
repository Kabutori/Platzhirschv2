import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
import {
  useData,
  Loading,
  ErrorBox,
  DataTable,
  Modal,
  Form,
  type Row,
} from '@platzhirsch/ui-runtime/components';
export default function Availability({
  tenant,
  kind,
}: {
  tenant?: string;
  kind: 'room-closures' | 'table-combinations';
}) {
  const q = useData('v1/restaurant/' + kind, tenant),
    rooms = useData('v1/restaurant/rooms', tenant),
    tables = useData('v1/restaurant/tables', tenant),
    profile = useData('v1/restaurant/profile', tenant),
    qc = useQueryClient();
  const [form, setForm] = useState<Row | null>(null),
    [error, setError] = useState<unknown>(),
    [remove, setRemove] = useState<Row | null>(null);
  if (q.isPending || rooms.isPending || tables.isPending || profile.isPending) return <Loading />;
  if (q.error || rooms.error || tables.error || profile.error)
    return <ErrorBox error={q.error || rooms.error || tables.error || profile.error} />;
  const closure = kind === 'room-closures',
    title = closure ? 'Raumsperren' : 'Tischkombinationen';
  const local = (value: string) =>
    new Intl.DateTimeFormat('sv-SE', {
      timeZone: profile.data.timezone || 'Europe/Berlin',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    })
      .format(new Date(value.replace(' ', 'T') + 'Z'))
      .replace(' ', 'T');
  return (
    <section className="panel padded">
      <div className="section-heading">
        <h2>{title}</h2>
        <button className="primary" onClick={() => setForm({})}>
          {closure ? 'Raumsperre anlegen' : 'Tischkombination anlegen'}
        </button>
      </div>
      <p className="muted">
        {closure
          ? 'Sperrt alle Tische des Raums für neue Buchungen. Bestehende Reservierungen müssen zuvor umgebucht oder storniert werden. Zeiten gelten in der Restaurant-Zeitzone.'
          : 'Aktive Kombinationen werden Gästen automatisch angeboten, wenn alle enthaltenen Tische frei sind und genügend Plätze bieten. Nur Tische desselben Raums kombinieren.'}
      </p>
      <ErrorBox error={error} />
      <DataTable
        rows={q.data}
        columns={
          closure
            ? [
                {
                  key: 'room_id',
                  label: 'Raum',
                  render: (r) => rooms.data.find((x: Row) => x.id === r.room_id)?.name || r.room_id,
                },
                { key: 'starts_at', label: 'Von', render: (r) => local(r.starts_at).replace('T', ' ') },
                { key: 'ends_at', label: 'Bis', render: (r) => local(r.ends_at).replace('T', ' ') },
                { key: 'reason', label: 'Grund' },
              ]
            : [
                { key: 'name', label: 'Name' },
                {
                  key: 'table_ids',
                  label: 'Tische',
                  render: (r) =>
                    r.table_ids
                      .map((id: number) => tables.data.find((t: Row) => t.id === id)?.name || id)
                      .join(' + '),
                },
                { key: 'active', label: 'Im Widget', render: (r) => (r.active ? 'Aktiv' : 'Inaktiv') },
              ]
        }
        actions={(r) => (
          <>
            <button
              onClick={() =>
                setForm(closure ? { ...r, starts_at: local(r.starts_at), ends_at: local(r.ends_at) } : r)
              }
            >
              Bearbeiten
            </button>
            <button onClick={() => setRemove(r)}>Löschen</button>
          </>
        )}
      />
      {form && (
        <Modal title={title + ' bearbeiten'} close={() => setForm(null)}>
          <Form
            initial={form}
            fields={
              closure
                ? [
                    {
                      key: 'room_id',
                      label: 'Raum',
                      required: true,
                      options: rooms.data.map((r: Row) => ({ value: r.id, label: r.name })),
                    },
                    { key: 'starts_at', label: 'Beginn', type: 'datetime-local', required: true },
                    { key: 'ends_at', label: 'Ende', type: 'datetime-local', required: true },
                    { key: 'reason', label: 'Grund', required: true },
                  ]
                : [
                    { key: 'name', label: 'Name', required: true },
                    {
                      key: 'table_ids',
                      label: 'Tische kombinieren',
                      multiple: true,
                      required: true,
                      options: tables.data
                        .filter((t: Row) => t.active)
                        .map((t: Row) => ({ value: t.id, label: t.name + ' · ' + t.capacity + ' Plätze' })),
                    },
                    { key: 'active', label: 'Im Widget anbieten', type: 'checkbox', default: true },
                  ]
            }
            onSave={async (data) => {
              await api(
                'v1/restaurant/' + kind + (form.id ? '/' + form.id : ''),
                form.id ? 'PATCH' : 'POST',
                data,
                tenant,
              );
              setForm(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
      {remove && (
        <Modal title="Eintrag löschen?" close={() => setRemove(null)}>
          <p>Die bisherigen Buchungen bleiben erhalten.</p>
          <Form
            fields={[]}
            label="Löschen bestätigen"
            onSave={async () => {
              await api('v1/restaurant/' + kind + '/' + remove.id, 'DELETE', undefined, tenant);
              setRemove(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
    </section>
  );
}
