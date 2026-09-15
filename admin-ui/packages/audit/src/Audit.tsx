import { useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { useData, DataTable, Loading, ErrorBox, type Row } from '@platzhirsch/ui-runtime/components';
export function AuditRows({ rows }: { rows: Row[] }) {
  return (
    <DataTable
      rows={rows}
      columns={[
        { key: 'created_at', label: 'Zeitpunkt' },
        { key: 'action', label: 'Aktion', render: (r) => <code>{r.action}</code> },
        { key: 'actor_id', label: 'Benutzer-ID' },
        { key: 'tenant_id', label: 'Mandant' },
        { key: 'resource', label: 'Objekt' },
      ]}
    />
  );
}
export default function AuditPage() {
  const [scope, setScope] = useState('platform');
  const [tenantId, setTenantId] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const params = new URLSearchParams({ scope, page: String(page), search });
  if (scope === 'tenant' && tenantId) params.set('tenant_id', tenantId);
  const q = useData('v1/admin/audit-log?' + params);
  return (
    <>
      <nav className="settings-tabs" aria-label="Audit-Bereich">
        {[
          ['platform', 'Plattform'],
          ['tenant', 'Mandanten'],
        ].map(([key, label]) => (
          <button
            key={key}
            aria-pressed={scope === key}
            onClick={() => {
              setScope(key);
              setPage(1);
            }}
          >
            {label}
          </button>
        ))}
      </nav>
      <div className="toolbar">
        <label>
          Aktion oder Objekt suchen
          <input
            value={search}
            maxLength={120}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
        </label>
        {scope === 'tenant' && (
          <label>
            Mandanten-ID
            <input
              type="number"
              min="1"
              value={tenantId}
              onChange={(e) => {
                setTenantId(e.target.value);
                setPage(1);
              }}
            />
          </label>
        )}
        <button onClick={() => q.refetch()} disabled={q.isFetching}>
          <RefreshCw size={15} />
          Aktualisieren
        </button>
      </div>
      <section className="panel">
        <div className="panel-head">
          <h2>Protokollierte Änderungen</h2>
          <small>{q.data?.total ?? '–'} Einträge</small>
        </div>
        {q.isPending ? (
          <Loading />
        ) : q.error ? (
          <ErrorBox error={q.error} />
        ) : (
          <AuditRows rows={q.data.data} />
        )}
      </section>
      <div className="toolbar">
        <button disabled={page === 1 || q.isFetching} onClick={() => setPage(page - 1)}>
          Zurück
        </button>
        <span>
          Seite {page} / {q.data?.last_page || 1}
        </span>
        <button
          disabled={!q.data || page >= q.data.last_page || q.isFetching}
          onClick={() => setPage(page + 1)}
        >
          Weiter
        </button>
      </div>
    </>
  );
}
