import { useState } from 'react';
import { api } from '@platzhirsch/ui-runtime/api';
import { useData, Modal, Form, Loading, ErrorBox, type Row } from '@platzhirsch/ui-runtime/components';
const categories = {
  feature: 'Neue Funktion',
  improvement: 'Verbesserung',
  fix: 'Fehlerbehebung',
  security: 'Sicherheit',
};
export default function Releases({ manage = false }: { manage?: boolean }) {
  const [page, setPage] = useState(1),
    [editing, setEditing] = useState<Row | null>(null);
  const q = useData('v1/releases?page=' + page);
  return (
    <>
      {manage && (
        <div className="toolbar">
          <p className="muted">
            Versionshinweise für die Restaurantportale. Veröffentlichen verteilt den Hinweis; es baut oder
            installiert kein Softwarepaket.
          </p>
          <button className="primary" onClick={() => setEditing({})}>
            + Release
          </button>
        </div>
      )}
      {q.isPending ? (
        <Loading />
      ) : q.error ? (
        <ErrorBox error={q.error} />
      ) : (
        <div className="release-list">
          {q.data.data?.map((r: Row) => (
            <article className="panel padded" key={r.id}>
              <header className="toolbar">
                <strong>{r.module}</strong>
                <code>v{r.version}</code>
                <span className={'badge ' + r.category}>
                  {categories[r.category as keyof typeof categories]}
                </span>
                {manage && <span>{r.published_at ? 'Veröffentlicht' : 'Entwurf'}</span>}
              </header>
              <p style={{ whiteSpace: 'pre-wrap' }}>{r.notes}</p>
              {r.git_url?.startsWith('https://') && (
                <a href={r.git_url} target="_blank" rel="noopener noreferrer">
                  Quellcode / Release öffnen
                </a>
              )}
              {manage && (
                <button onClick={() => setEditing({ ...r, publish: !!r.published_at })}>Bearbeiten</button>
              )}
            </article>
          ))}
          {!q.data.data?.length && <p className="padded">Noch keine Versionshinweise veröffentlicht.</p>}
        </div>
      )}
      {(q.data?.last_page || 1) > 1 && (
        <div className="toolbar">
          <button disabled={page === 1} onClick={() => setPage(page - 1)}>
            Zurück
          </button>
          <span>Seite {page}</span>
          <button disabled={page >= q.data.last_page} onClick={() => setPage(page + 1)}>
            Weiter
          </button>
        </div>
      )}
      {editing && (
        <Modal title={editing.id ? 'Release bearbeiten' : 'Release anlegen'} close={() => setEditing(null)}>
          <Form
            initial={editing}
            fields={[
              { key: 'module', label: 'Modul', required: true },
              { key: 'version', label: 'Version', required: true },
              {
                key: 'category',
                label: 'Kategorie',
                required: true,
                options: Object.entries(categories).map(([value, label]) => ({ value, label })),
              },
              { key: 'notes', label: 'Änderungen', type: 'textarea', required: true },
              { key: 'git_url', label: 'HTTPS-Link zum Release', type: 'url' },
              { key: 'publish', label: 'Für Restaurantportale veröffentlichen', type: 'checkbox' },
            ]}
            onSave={async (data) => {
              await api('v1/releases' + (editing.id ? '/' + editing.id : ''), editing.id ? 'PATCH' : 'POST', {
                ...data,
                revision: editing.revision,
              });
              setEditing(null);
              await q.refetch();
            }}
          />
        </Modal>
      )}
    </>
  );
}
