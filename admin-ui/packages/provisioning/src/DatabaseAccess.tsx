import { useEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Database, Eye, EyeOff, Copy, X } from 'lucide-react';
import { api } from '@platzhirsch/ui-runtime/api';
type Connection = {
  id: string;
  name: string;
  scope: string;
  host: string;
  port: number;
  database: string;
  username: string;
  status: string;
};
function Credentials({ connection, close }: { connection: Connection; close: () => void }) {
  const [password, setPassword] = useState('');
  const [secret, setSecret] = useState<string | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState('');
  const controller = useRef<AbortController | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    dialog.current?.showModal();
    return () => dialog.current?.close();
  }, []);
  useEffect(() => () => controller.current?.abort(), []);
  useEffect(() => {
    if (secret === null) return;
    const hide = () => {
      controller.current?.abort();
      setSecret(null);
      setPassword('');
    };
    const timer = setTimeout(hide, 30000);
    const visibility = () => {
      if (document.hidden) hide();
    };
    document.addEventListener('visibilitychange', visibility);
    window.addEventListener('blur', hide);
    return () => {
      clearTimeout(timer);
      document.removeEventListener('visibilitychange', visibility);
      window.removeEventListener('blur', hide);
    };
  }, [secret]);
  return (
    <dialog ref={dialog} onCancel={close} aria-labelledby="sql-title">
      <section className="panel padded sql-dialog">
        <div className="toolbar">
          <h2 id="sql-title">{connection.name}</h2>
          <button aria-label="Schließen" onClick={close}>
            <X size={18} />
          </button>
        </div>
        <p>
          MySQL · {connection.scope} · {connection.status}
        </p>
        <dl className="sql-fields">
          {[
            ['Host', connection.host],
            ['Port', String(connection.port)],
            ['Datenbank', connection.database],
            ['Benutzer', connection.username],
          ].map(([label, value]) => (
            <div key={label}>
              <dt>{label}</dt>
              <dd>
                <code>{value}</code>
                <button
                  aria-label={label + ' kopieren'}
                  onClick={async () => {
                    try {
                      await navigator.clipboard.writeText(value);
                      setCopied(label + ' kopiert.');
                    } catch {
                      setError('Kopieren nicht möglich. Bitte den Wert markieren.');
                    }
                  }}
                >
                  <Copy size={14} />
                </button>
              </dd>
            </div>
          ))}
        </dl>
        <p className="muted">
          Anwendungszugang dieser Datenbank. Der Host bezeichnet den Server aus Sicht der Installation.
        </p>
        {secret === null ? (
          <form
            onSubmit={async (e) => {
              e.preventDefault();
              setError('');
              setBusy(true);
              controller.current = new AbortController();
              try {
                const result = await api<{ password: string }>(
                  'v1/admin/database-access/' + connection.id + '/reveal',
                  'POST',
                  { password },
                  undefined,
                  controller.current.signal,
                );
                setSecret(result.password);
              } catch (e) {
                if ((e as Error).name !== 'AbortError') setError((e as Error).message);
              } finally {
                setPassword('');
                setBusy(false);
              }
            }}
          >
            <label>
              Dein Administratorkennwort
              <input
                autoFocus
                type="password"
                autoComplete="current-password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </label>
            <button className="primary" disabled={busy || !password}>
              <Eye size={16} />
              SQL-Passwort anzeigen
            </button>
          </form>
        ) : (
          <div className="sql-secret">
            <label>
              SQL-Passwort
              <input readOnly value={secret} autoComplete="off" spellCheck={false} />
            </label>
            <button onClick={() => setSecret(null)}>
              <EyeOff size={16} />
              Ausblenden
            </button>
            <p>Wird nach 30 Sekunden oder beim Verlassen des Fensters ausgeblendet.</p>
          </div>
        )}
        <p className="muted">Jede Passwortanzeige wird im Audit Log erfasst.</p>
        {error && <p role="alert">{error}</p>}
        {copied && <p role="status">{copied}</p>}
      </section>
    </dialog>
  );
}
export default function DatabaseAccess() {
  const q = useQuery({
    queryKey: ['database-access'],
    queryFn: () => api<Connection[]>('v1/admin/database-access'),
  });
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<Connection>();
  return (
    <>
      <div className="toolbar">
        <h2>SQL-Zugangsdaten</h2>
        <input
          aria-label="Datenbank suchen"
          placeholder="Restaurant oder Datenbank suchen …"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>
      <p>Verbindungen der Plattform und fertig eingerichteter Restaurants. Nur für Systemadministratoren.</p>
      {q.isPending && <p role="status">Verbindungen werden geladen …</p>}
      {q.error && <p role="alert">{q.error.message}</p>}
      <div className="sql-grid">
        {q.data
          ?.filter((c) => (c.name + ' ' + c.database).toLowerCase().includes(search.toLowerCase()))
          .map((c) => (
            <section className="panel padded" key={c.id}>
              <Database size={22} />
              <h3>{c.name}</h3>
              <p className="muted">{c.scope} · MySQL</p>
              <code>{c.database}</code>
              <p>
                {c.host}:{c.port}
              </p>
              <button onClick={() => setSelected(c)}>Verbindungsdaten öffnen</button>
            </section>
          ))}
      </div>
      {selected && (
        <Credentials key={selected.id} connection={selected} close={() => setSelected(undefined)} />
      )}
    </>
  );
}
