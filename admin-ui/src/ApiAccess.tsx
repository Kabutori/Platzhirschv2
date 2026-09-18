import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
export default function ApiAccess({ system = false }: { system?: boolean }) {
  const qc = useQueryClient(),
    q = useQuery({ queryKey: ['api-access'], queryFn: () => api('v1/access') });
  const [error, setError] = useState(''),
    [secret, setSecret] = useState(''),
    [busy, setBusy] = useState(false),
    [scopes, setScopes] = useState<string[]>([]);
  async function send(path: string, method: string, data: unknown) {
    setBusy(true);
    setError('');
    try {
      const result = await api('v1/access' + path, method, data);
      await qc.invalidateQueries({ queryKey: ['api-access'] });
      return result;
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  const approvalFields = (
    <>
      <label>
        Aktuelles Kennwort
        <input name="password" type="password" autoComplete="current-password" required />
      </label>
      <label>
        <input type="checkbox" name="confirmed" required /> Aktion verbindlich bestätigen
      </label>
    </>
  );
  const formAuth = (f: FormData) => ({ password: String(f.get('password')), confirmed: f.has('confirmed') });
  return (
    <section className="panel padded">
      <h2>API-Zugänge & MCP</h2>
      <p>
        Zugänge gelten für Ihr Konto und dessen aktuellen Mandanten. Rechteentzug, Sperrung und Widerruf
        wirken sofort.
      </p>
      {(error || q.error) && <p role="alert">{error || q.error?.message}</p>}
      {secret && (
        <div role="status">
          <strong>Token jetzt sicher speichern – nur einmal sichtbar.</strong>
          <input aria-label="Neuer API-Token" readOnly value={secret} />
          <button onClick={() => setSecret('')}>Gespeichert, ausblenden</button>
        </div>
      )}
      <form
        onSubmit={async (e) => {
          e.preventDefault();
          const form = e.currentTarget,
            f = new FormData(form);
          const r = await send('/tokens', 'POST', {
            ...formAuth(f),
            name: f.get('name'),
            audience: f.get('audience'),
            days: Number(f.get('days')),
            cidrs: String(f.get('cidrs'))
              .split(/[,\s]+/)
              .filter(Boolean),
            scopes,
          });
          if (r) {
            setSecret(r.token);
            form.reset();
            setScopes([]);
          }
        }}
      >
        <h3>Neuen Zugang erstellen</h3>
        <label>
          Name
          <input name="name" maxLength={100} required />
        </label>
        <label>
          Zweck
          <select name="audience">
            <option value="api">API / Automatisierung</option>
            <option value="mcp">MCP / KI-Client</option>
          </select>
        </label>
        <label>
          Gültigkeit in Tagen
          <input name="days" type="number" min={1} max={90} defaultValue={30} required />
        </label>
        <label>
          Erlaubte IPs / CIDR (optional)
          <input name="cidrs" placeholder="192.0.2.10/32" />
        </label>
        <fieldset>
          <legend>Modulrechte (keine Vorauswahl)</legend>
          {q.data?.scopes.map((scope: string) => (
            <label key={scope}>
              <input
                type="checkbox"
                checked={scopes.includes(scope)}
                onChange={(e) =>
                  setScopes((old) => (e.target.checked ? [...old, scope] : old.filter((s) => s !== scope)))
                }
              />
              {scope}
            </label>
          ))}
        </fieldset>
        {approvalFields}
        <button disabled={busy || !scopes.length}>Token erstellen</button>
      </form>
      <h3>Bestehende Zugänge</h3>
      <p>
        Rotation: Ersatz mit gleichen oder weniger Rechten erstellen, Anwendung umstellen, bisherigen Zugang
        widerrufen.
      </p>
      {q.data?.tokens.map((t: any) => (
        <article className="panel padded" key={t.id}>
          <strong>{t.name}</strong>
          <p>
            {t.audience} · gültig bis {t.expires_at} ·{' '}
            {t.revoked_at
              ? 'widerrufen'
              : new Date(t.expires_at + 'Z').getTime() < Date.now()
                ? 'abgelaufen'
                : 'aktiv'}{' '}
            · letzte Nutzung {t.last_used_at || 'noch keine'}
          </p>
          <p>{JSON.parse(t.scopes).join(', ')}</p>
          {!t.revoked_at && (
            <form
              onSubmit={(e) => {
                e.preventDefault();
                void send('/tokens/' + t.id + '/revoke', 'POST', formAuth(new FormData(e.currentTarget)));
              }}
            >
              {approvalFields}
              <button disabled={busy}>Zugang widerrufen</button>
            </form>
          )}
        </article>
      ))}
      <h3>Angeforderte Freigaben</h3>
      <button onClick={() => void q.refetch()} disabled={busy}>
        Freigaben aktualisieren
      </button>
      {q.data?.confirmations.map((c: any) => (
        <article className="panel padded" key={c.id}>
          <strong>{c.operation}</strong>
          <pre style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>
            {JSON.stringify(JSON.parse(c.preview), null, 2)}
          </pre>
          <p>Gültig bis {c.expires_at}. Die Freigabe gilt einmalig nur für genau diese Daten.</p>
          {c.approved_at ? (
            <p>Freigegeben, wartet auf Ausführung.</p>
          ) : (
            <form
              onSubmit={(e) => {
                e.preventDefault();
                void send(
                  '/confirmations/' + c.id + '/approve',
                  'POST',
                  formAuth(new FormData(e.currentTarget)),
                );
              }}
            >
              {approvalFields}
              <button disabled={busy}>Diese Aktion freigeben</button>
            </form>
          )}
        </article>
      ))}
      {system && (
        <>
          <h3>Modulzugriffe steuern</h3>
          {['api', ...(q.data?.modules || [])]
            .filter((m, i, a) => a.indexOf(m) === i)
            .map((module: string) => {
              const s = q.data?.settings.find((x: any) => x.module === module);
              return (
                <form
                  key={module + ':' + (s?.revision || 0)}
                  onSubmit={(e) => {
                    e.preventDefault();
                    const f = new FormData(e.currentTarget);
                    void send('/modules/' + module, 'PUT', {
                      ...formAuth(f),
                      enabled: f.has('enabled'),
                      mcp_enabled: f.has('mcp_enabled'),
                      revision: s?.revision || 0,
                    });
                  }}
                >
                  <strong>{module === 'api' ? 'Gesamte externe API' : module}</strong>
                  <label>
                    <input name="enabled" type="checkbox" defaultChecked={s ? s.enabled : true} /> API aktiv
                  </label>
                  <label>
                    <input name="mcp_enabled" type="checkbox" defaultChecked={s ? s.mcp_enabled : true} /> MCP
                    erlaubt
                  </label>
                  {approvalFields}
                  <button disabled={busy}>Zugriff speichern</button>
                </form>
              );
            })}
        </>
      )}
      <h3>Zugriffsprotokoll</h3>
      <div className="table-scroll">
        <table>
          <thead>
            <tr>
              <th>Zeit</th>
              <th>Operation</th>
              <th>HTTP</th>
            </tr>
          </thead>
          <tbody>
            {q.data?.events.map((e: any) => (
              <tr key={e.id}>
                <td>{e.created_at}</td>
                <td>{e.operation}</td>
                <td>{e.http_status}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
