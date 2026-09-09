import Releases from './Releases';
import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Plus, ChevronRight } from 'lucide-react';
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
export default function Support({ tenant, user }: { tenant?: string; user?: Row }) {
  const q = useData('v1/support');
  const [selected, setSelected] = useState<number | null>(null);
  const detail = useQuery({
    queryKey: ['support', selected],
    queryFn: () => api('v1/support/' + selected),
    enabled: selected !== null,
  });
  const [open, setOpen] = useState(false);
  const [changelog, setChangelog] = useState(false);
  const qc = useQueryClient();
  return (
    <>
      {changelog && (
        <Modal title="Changelog" close={() => setChangelog(false)}>
          <div className="changelog-dialog">
            <Releases />
          </div>
        </Modal>
      )}
      <div className="toolbar">
        <div>
          {selected ? (
            <button onClick={() => setSelected(null)}>
              <ArrowLeft size={16} />
              Alle Tickets
            </button>
          ) : (
            <p className="muted">Fragen, Fehler und Rückmeldungen</p>
          )}
        </div>
        <button onClick={() => setChangelog(true)}>Changelog</button>
        <button className="primary" onClick={() => setOpen(true)}>
          <Plus size={16} />
          Ticket erstellen
        </button>
      </div>
      {selected ? (
        <Modal title="Ticketdetails" close={() => setSelected(null)}>
          <section className="panel padded">
            {detail.isPending ? (
              <Loading />
            ) : detail.error ? (
              <ErrorBox error={detail.error} />
            ) : (
              <>
                <div className="panel-head">
                  <h2>
                    #{detail.data.ticket.id} · {detail.data.ticket.subject}
                  </h2>
                  <Badge value={detail.data.ticket.status} />
                </div>
                <div className="messages">
                  {detail.data.messages.map((m: Row) => (
                    <article key={m.id} className={m.internal ? 'internal' : ''}>
                      <header>
                        <strong>{m.author}</strong>
                        <small>
                          {m.created_at}
                          {m.internal ? ' · Interne Notiz' : ''}
                        </small>
                      </header>
                      <p>{m.body}</p>
                    </article>
                  ))}
                </div>
                <h3>Antwort schreiben</h3>
                <Form
                  fields={[
                    { key: 'body', label: 'Nachricht', type: 'textarea', required: true },
                    {
                      key: 'status',
                      label: 'Status',
                      default: 'open',
                      options: pick(['open', 'in_progress', 'closed']),
                    },
                    ...(portal === 'administration'
                      ? [
                          {
                            key: 'internal',
                            label: 'Interne Notiz (nicht für Restaurant sichtbar)',
                            type: 'checkbox',
                          },
                        ]
                      : []),
                  ]}
                  onSave={async (data) => {
                    await api('v1/support/' + selected + '/messages', 'POST', data);
                    await qc.invalidateQueries();
                  }}
                />
              </>
            )}
          </section>
        </Modal>
      ) : (
        <section className="panel">
          {q.isPending ? (
            <Loading />
          ) : q.error ? (
            <ErrorBox error={q.error} />
          ) : (
            <DataTable
              rows={q.data.data}
              columns={[
                { key: 'id', label: 'Ticket' },
                { key: 'subject', label: 'Betreff' },
                { key: 'status', label: 'Status', render: (r) => <Badge value={r.status} /> },
                { key: 'priority', label: 'Priorität', render: (r) => <Badge value={r.priority} /> },
              ]}
              actions={(r) => (
                <button onClick={() => setSelected(r.id)}>
                  Öffnen
                  <ChevronRight size={14} />
                </button>
              )}
            />
          )}
        </section>
      )}
      {open && (
        <Modal title="Neues Support-Ticket" close={() => setOpen(false)}>
          <Form
            fields={[
              { key: 'subject', label: 'Betreff', required: true },
              {
                key: 'priority',
                label: 'Priorität',
                default: 'normal',
                options: pick(['low', 'normal', 'high']),
                required: true,
              },
              ...(portal === 'administration'
                ? [
                    {
                      key: 'tenant_id',
                      label: 'Restaurant-ID',
                      type: 'number',
                      required: true,
                      default: tenant,
                    },
                  ]
                : []),
              { key: 'body', label: 'Beschreibung', type: 'textarea', required: true },
            ]}
            onSave={async (data) => {
              const result = await api('v1/support', 'POST', data);
              setOpen(false);
              setSelected(result.id);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
    </>
  );
}
