import { Modal } from '@platzhirsch/ui-runtime/components';
import { useState, type FormEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
type Server = {
  id: number;
  name: string;
  host: string;
  port: number;
  region: string;
  purpose: string;
  database: string;
  username: string;
  tls_required: boolean;
  version: number;
  provisioning_enabled?: boolean;
};
const messages: Record<string, string> = {
  connected: 'Verbindung erfolgreich',
  temporary_table_checked: 'Temporäre Tabelle erfolgreich erstellt, gelesen, geändert und entfernt',
  authentication_failed: 'Anmeldung am Datenbankserver fehlgeschlagen',
  permission_denied: 'Datenbankrechte reichen für diese Prüfung nicht aus',
  database_missing: 'Datenbank nicht gefunden',
  connection_failed: 'Verbindung fehlgeschlagen. Prüfe Erreichbarkeit und TLS-Vertrauen auf dem Server.',
  tls_trust_missing:
    'TLS-Vertrauen fehlt. Der Betreiber muss DB_SERVER_CA_FILE auf das CA-Zertifikat des Datenbankservers setzen.',
  tls_required: 'Der Server hat keine verschlüsselte Verbindung ausgehandelt',
  permission_check_failed: 'Rechteprüfung fehlgeschlagen',
};
export default function Servers() {
  const me = useQuery({ queryKey: ['v1/admin/auth/me', undefined], queryFn: () => api('v1/admin/auth/me') });
  const permits = (permission: string) =>
    me.data?.permissions?.includes('*') || me.data?.permissions?.includes(permission);

  const q = useQuery({
    queryKey: ['database-servers'],
    queryFn: () => api<{ servers: Server[]; notice: string }>('v1/admin/database-servers'),
  });
  const qc = useQueryClient();
  const [editing, setEditing] = useState<Partial<Server> | null>(null);
  const [details, setDetails] = useState<Server | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [results, setResults] = useState<Record<string, string>>({});
  const [testing, setTesting] = useState<Record<string, boolean>>({});
  function edit(server: Partial<Server>) {
    setEditing(server);
    setError('');
  }
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(event.currentTarget));
    const body: Record<string, unknown> = {
      ...values,
      port: Number(values.port),
      tls_required: values.tls_required === 'on',
    };
    if (editing?.id) body.version = editing.version;
    setBusy(true);
    setError('');
    try {
      await api(
        'v1/admin/database-servers' + (editing?.id ? '/' + editing.id : ''),
        editing?.id ? 'PATCH' : 'POST',
        body,
      );
      setResults({});
      await qc.invalidateQueries({ queryKey: ['database-servers'] });
      setEditing(null);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function test(server: Server, check: string) {
    const key = server.id + ':' + check;
    setTesting((s) => ({ ...s, [key]: true }));
    setResults((s) => ({ ...s, [key]: '' }));
    try {
      const result = await api('v1/admin/database-servers/' + server.id + '/test', 'POST', { check });
      setResults((s) => ({
        ...s,
        [key]:
          (messages[result.code] || 'Prüfung abgeschlossen') +
          (result.latency_ms !== undefined ? ` (${result.latency_ms} ms)` : ''),
      }));
    } catch (e) {
      setResults((s) => ({ ...s, [key]: (e as Error).message }));
    } finally {
      setTesting((s) => ({ ...s, [key]: false }));
    }
  }
  return (
    <section className="panel padded">
      <div className="toolbar">
        <h2>Datenbankverbindungen</h2>
        <button
          className="primary"
          disabled={!permits('provisioning.servers.manage')}
          onClick={() => edit({ port: 3306, purpose: 'test', region: 'EU', tls_required: true })}
        >
          Server hinzufügen
        </button>
      </div>
      <p>Primäre Datenbanken, Testsysteme und Mandantenserver zentral erfassen und prüfen.</p>
      {q.isPending && <p role="status">Server werden geladen …</p>}
      {q.error && <p role="alert">{q.error.message}</p>}
      {q.data && <p className="notice">{q.data.notice}</p>}
      <p className="muted">
        Die Rechteprüfung verwendet eine temporäre Tabelle. Sie bestätigt keine Rechte zur Benutzeranlage,
        Migration oder Mandanten-Provisionierung.
      </p>
      {q.data?.servers.length === 0 && <p>Noch keine zusätzlichen Datenbankverbindungen erfasst.</p>}
      {q.data?.servers.map((server) => (
        <article key={server.id} className="panel padded">
          <h3>
            {server.name} · {server.region}
          </h3>
          <p>
            {server.host}:{server.port} · {server.database} ·{' '}
            {server.tls_required ? 'TLS erforderlich' : 'TLS nicht erzwungen'}
          </p>
          <p className="notice">
            {server.provisioning_enabled
              ? 'Für Mandanten freigegeben · Verbindung gegen Änderungen gesperrt'
              : 'Noch keine Provisionierungsfreigabe. Der Windows-Administrator kann diesen Server lokal autorisieren.'}
          </p>
          <div className="toolbar">
            <button onClick={() => setDetails(server)}>Details</button>
            <button
              disabled={server.provisioning_enabled || !permits('provisioning.servers.manage')}
              onClick={() => edit(server)}
            >
              Bearbeiten
            </button>
            {['connection', 'permissions'].map((check) => (
              <button
                key={check}
                disabled={!permits('provisioning.servers.test') || !!testing[server.id + ':' + check]}
                onClick={() => test(server, check)}
              >
                {testing[server.id + ':' + check]
                  ? 'Wird geprüft …'
                  : check === 'connection'
                    ? 'Verbindung testen'
                    : 'Berechtigungen testen'}
              </button>
            ))}
          </div>
          {['connection', 'permissions'].map((check) => (
            <p role="status" key={check}>
              {results[server.id + ':' + check]}
            </p>
          ))}
        </article>
      ))}
      {details && (
        <Modal title="Serverdetails" close={() => setDetails(null)}>
          <dl className="details">
            {Object.entries({
              Name: details.name,
              Adresse: details.host,
              Port: details.port,
              Region: details.region,
              Zweck: details.purpose,
              Prüfdatenbank: details.database,
              Prüfbenutzer: details.username,
              TLS: details.tls_required ? 'Erforderlich' : 'Nicht erzwungen',
              Provisionierung: details.provisioning_enabled ? 'Freigegeben' : 'Nicht freigegeben',
              Version: details.version,
            }).map(([label, value]) => (
              <div key={label}>
                <dt>{label}</dt>
                <dd>{String(value ?? '—')}</dd>
              </div>
            ))}
          </dl>
          <div className="dialog-actions">
            <button onClick={() => setDetails(null)}>Schließen</button>
            <button
              disabled={details.provisioning_enabled || !permits('provisioning.servers.manage')}
              onClick={() => {
                edit(details);
                setDetails(null);
              }}
            >
              Bearbeiten
            </button>
          </div>
        </Modal>
      )}
      {editing && (
        <section className="panel padded" aria-label={editing.id ? 'Server bearbeiten' : 'Server hinzufügen'}>
          <h3>{editing.id ? 'Server bearbeiten' : 'Server hinzufügen'}</h3>
          <form onSubmit={save} key={editing.id || 'new'}>
            <fieldset disabled={busy} className="fields">
              {(
                [
                  ['name', 'Name'],
                  ['host', 'Host'],
                  ['port', 'Port'],
                  ['region', 'Region'],
                  ['database', 'Datenbank'],
                  ['username', 'Benutzer'],
                ] as const
              ).map(([key, label]) => (
                <label key={key}>
                  {label}
                  <input
                    name={key}
                    defaultValue={editing[key] || ''}
                    required
                    type={key === 'port' ? 'number' : 'text'}
                    min={key === 'port' ? 1 : undefined}
                    max={key === 'port' ? 65535 : undefined}
                  />
                </label>
              ))}
              <label>
                Verwendungszweck
                <select name="purpose" defaultValue={editing.purpose}>
                  <option value="test">Testdatenbank</option>
                  <option value="primary">Primäre Datenbank</option>
                  <option value="tenant">Mandantenserver</option>
                </select>
              </label>
              <label>
                Passwort
                <input name="password" type="password" autoComplete="new-password" required={!editing.id} />
                {editing.id && <small>Leer lassen, um das gespeicherte Passwort beizubehalten.</small>}
              </label>
              <label>
                <input type="checkbox" name="tls_required" defaultChecked={editing.tls_required} />
                TLS erforderlich
              </label>
              {error && <p role="alert">{error}</p>}
              <div className="toolbar">
                <button type="button" onClick={() => setEditing(null)}>
                  Abbrechen
                </button>
                <button className="primary" type="submit">
                  {busy ? 'Wird gespeichert …' : 'Speichern'}
                </button>
              </div>
            </fieldset>
          </form>
        </section>
      )}
    </section>
  );
}
