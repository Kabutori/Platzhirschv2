import './ModuleUpdates.css';
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from './api';
import SystemOperations from './SystemOperations';
type Release = { commit: string; packages: { name: string; version: string }[] };
type Repo = { installed: string; versions: Record<string, Release> };
type Build = { id: string; status: string; run_id: number | null; release_tag: string | null };
type State = {
  repositories: Record<string, Repo>;
  github_configured: boolean;
  reader_configured: boolean;
  pipeline_configured: boolean;
  registry_url: string;
  builds: Build[];
};
type Preview = { compatible: boolean; errors: string[] };
const statuses: Record<string, string> = {
  queued: 'Wartet auf Build',
  dispatching: 'Wird übergeben',
  dispatch_failed: 'Übergabe fehlgeschlagen',
  in_progress: 'Prüfungen laufen',
  ready: 'Geprüft und bereit',
  failure: 'Prüfung fehlgeschlagen',
  cancelled: 'Abgebrochen',
  timed_out: 'Zeitüberschreitung',
};
export default function ModuleUpdates() {
  const q = useQuery({
    queryKey: ['module-updates'],
    queryFn: () => api<State>('v1/admin/module-updates'),
    retry: false,
  });
  const [selection, setSelection] = useState<Record<string, string>>({});
  const [preview, setPreview] = useState<Preview>();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [token, setToken] = useState('');
  const [reader, setReader] = useState('');
  const [rotate, setRotate] = useState(false);
  const [configure, setConfigure] = useState(true);
  const [clear, setClear] = useState(false);
  const [confirmation, setConfirmation] = useState(false);
  const [password, setPassword] = useState('');
  const [action, setAction] = useState<{
    type: 'settings' | 'build' | 'stage';
    id?: string;
    requestId: string;
  }>();
  const choices = Object.fromEntries(
    Object.entries(q.data?.repositories ?? {}).map(([repo, row]) => [repo, selection[repo] ?? row.installed]),
  );
  async function run(task: () => Promise<void>) {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      await task();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  function confirm(type: 'settings' | 'build' | 'stage', id?: string) {
    setAction({ type, id, requestId: crypto.randomUUID() });
    setPassword('');
    setConfirmation(false);
    setError('');
  }
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!action) return;
    await run(async () => {
      const auth = { password, confirmation };
      if (action.type === 'settings') {
        const result = await api<{ reader_token: string | null }>('v1/admin/module-updates/settings', 'PUT', {
          ...auth,
          github_token: token,
          clear_token: clear,
          configure_pipeline: configure,
          rotate_reader: rotate,
        });
        setReader(result.reader_token ?? '');
        setToken('');
        setNotice('Paketquelle gespeichert.');
      }
      if (action.type === 'build') {
        await api('v1/admin/module-updates/builds', 'POST', {
          ...auth,
          selection: choices,
          request_id: action.requestId,
        });
        setNotice('Build gestartet. Der Status kann unten aktualisiert werden.');
      }
      if (action.type === 'stage') {
        await api(`v1/admin/module-updates/builds/${action.id}/stage`, 'POST', auth);
        setNotice(
          'Download beauftragt. Nach erfolgreicher Bereitstellung erscheint das Paket unten unter „Freigegebene Updates“.',
        );
      }
      setAction(undefined);
      setPassword('');
      await q.refetch();
    });
  }
  return (
    <section className="panel padded module-update-panel">
      <h2>Modulversionen & Updates</h2>
      <p>
        Wählen Sie die Modulversionen für die gesamte Plattform. Vor der Installation werden Abhängigkeiten,
        Anwendung und Windows-Installation geprüft.
      </p>
      {q.isPending && <p role="status">Versionsstände werden geladen …</p>}
      {q.error && <p role="alert">{q.error.message}</p>}
      {error && <p role="alert">{error}</p>}
      {notice && <p role="status">{notice}</p>}
      <details>
        <summary>Private Paketquelle einrichten</summary>
        <p>
          GitHub-Zugang: {q.data?.github_configured ? 'gespeichert' : 'fehlt'} · Build-Pipeline:{' '}
          {q.data?.pipeline_configured ? 'eingerichtet' : 'nicht eingerichtet'}
        </p>
        <p>
          Ein Fine-grained GitHub-Token benötigt Zugriff auf die 18 Modul-Repositories (Contents: Lesen) und
          Platzhirschv2 (Actions und Secrets: Lesen/Schreiben, Contents: Lesen). Der Zugang wird verschlüsselt
          gespeichert und bei der Einrichtung verschlüsselt als GitHub-Actions-Secret hinterlegt.
        </p>
        <label>
          Neuer GitHub-Token
          <input
            type="password"
            autoComplete="off"
            value={token}
            onChange={(e) => setToken(e.target.value)}
          />
        </label>
        <label>
          <input
            type="checkbox"
            checked={clear}
            onChange={(e) => {
              setClear(e.target.checked);
              if (e.target.checked) setConfigure(false);
            }}
          />{' '}
          Gespeicherten lokalen GitHub-Zugang entfernen (das CI-Secret bleibt auf GitHub)
        </label>
        <label>
          <input type="checkbox" checked={configure} onChange={(e) => setConfigure(e.target.checked)} />{' '}
          Build-Pipeline auf GitHub einrichten
        </label>
        <label>
          <input type="checkbox" checked={rotate} onChange={(e) => setRotate(e.target.checked)} /> Neues
          Lesetoken für Composer/npm erzeugen; bisheriges Token ungültig machen
        </label>
        <button disabled={busy} onClick={() => confirm('settings')}>
          Einstellungen speichern
        </button>
        {reader && (
          <div role="status">
            <p>Lesetoken – wird nur jetzt angezeigt:</p>
            <code style={{ overflowWrap: 'anywhere' }}>{reader}</code>
            <button onClick={() => setReader('')}>Ausblenden</button>
          </div>
        )}
        <p>
          Composer-Repository: <code>{q.data?.registry_url}/composer/packages.json</code>
        </p>
        <p>
          npm-Scope @platzhirsch: <code>{q.data?.registry_url}/npm/</code>
        </p>
        <p>
          Beide verwenden ein Bearer-Lesetoken. Zugangsdaten gehören in die lokale Composer-auth.json bzw.
          npm-Konfiguration, niemals ins Repository. Für entfernte Clients HTTPS verwenden.
        </p>
      </details>
      <h3>Zusammenstellung</h3>
      <div style={{ overflowX: 'auto' }}>
        <table className="module-version-table">
          <thead>
            <tr>
              <th>Repository</th>
              <th>Installiert</th>
              <th>Zielversion</th>
              <th>Paketquelle</th>
            </tr>
          </thead>
          <tbody>
            {Object.entries(q.data?.repositories ?? {}).map(([repo, row]) => (
              <tr key={repo}>
                <td>{repo.replace('platzhirsch-', '')}</td>
                <td data-label="Installiert">{row.installed}</td>
                <td data-label="Zielversion">
                  <select
                    aria-label={`Zielversion ${repo}`}
                    disabled={busy || !!action}
                    value={choices[repo]}
                    onChange={(e) => {
                      setSelection({ ...choices, [repo]: e.target.value });
                      setPreview(undefined);
                    }}
                  >
                    {Object.keys(row.versions)
                      .sort((a, b) => b.localeCompare(a, undefined, { numeric: true }))
                      .map((v) => (
                        <option key={v}>{v}</option>
                      ))}
                  </select>
                </td>
                <td data-label="Paketquelle">
                  <button
                    disabled={busy || !q.data?.github_configured}
                    onClick={() =>
                      run(async () => {
                        const r = await api<{ added: number }>('v1/admin/module-updates/sync', 'POST', {
                          repository: repo,
                        });
                        await q.refetch();
                        setPreview(undefined);
                        setNotice(`${r.added} neue Versionen geladen.`);
                      })
                    }
                  >
                    Versionen laden
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!Object.keys(choices).length && q.data && (
        <p>Kein Paketkatalog vorhanden. Ein aktuelles gebautes Windows-Paket installieren.</p>
      )}
      <div className="toolbar">
        <button
          disabled={busy || !Object.keys(choices).length}
          onClick={() =>
            run(async () =>
              setPreview(
                await api<Preview>('v1/admin/module-updates/preview', 'POST', { selection: choices }),
              ),
            )
          }
        >
          Zusammenstellung prüfen
        </button>
        <button
          disabled={busy || !preview?.compatible || !q.data?.pipeline_configured}
          onClick={() => confirm('build')}
        >
          Geprüften Build starten
        </button>
      </div>
      {preview && (
        <div role={preview.compatible ? 'status' : 'alert'}>
          <p>
            {preview.compatible
              ? 'Die Paketabhängigkeiten passen zusammen. Der Build prüft anschließend die tatsächliche Funktion.'
              : 'Diese Zusammenstellung ist nicht kompatibel.'}
          </p>
          {preview.errors.map((x, i) => (
            <p key={i}>{x}</p>
          ))}
        </div>
      )}
      <h3>Buildaufträge</h3>
      {!q.data?.builds.length && <p>Noch kein Build angefordert.</p>}
      {q.data?.builds.map((b) => (
        <div className="panel padded" key={b.id}>
          <strong>{statuses[b.status] ?? b.status}</strong>
          <p>Auftrag {b.id}</p>
          <div className="toolbar">
            {b.run_id && (
              <a
                href={`https://github.com/Kabutori/Platzhirschv2/actions/runs/${b.run_id}`}
                target="_blank"
                rel="noreferrer"
              >
                Prüfprotokoll öffnen
              </a>
            )}
            <button
              disabled={busy}
              onClick={() =>
                run(async () => {
                  await api(`v1/admin/module-updates/builds/${b.id}/refresh`, 'POST', {});
                  await q.refetch();
                })
              }
            >
              Status aktualisieren
            </button>
            {b.status === 'ready' && (
              <button disabled={busy} onClick={() => confirm('stage', b.id)}>
                Update bereitstellen
              </button>
            )}
          </div>
        </div>
      ))}
      {action && (
        <form onSubmit={submit} className="panel padded">
          <h3>
            {action.type === 'settings'
              ? 'Paketquelle speichern'
              : action.type === 'build'
                ? 'Build freigeben'
                : 'Update herunterladen'}
          </h3>
          <p>
            {action.type === 'build'
              ? 'Der Build benötigt GitHub-Actions-Laufzeit. Die laufende Installation wird erst durch eine separate Installationsfreigabe geändert.'
              : action.type === 'stage'
                ? 'Das geprüfte Windows-Paket wird auf den Server geladen. Die Installation bestätigen Sie anschließend separat.'
                : 'Die gespeicherten Zugangsdaten und gegebenenfalls der CI-Zugang werden geändert.'}
          </p>
          <label>
            Administrator-Passwort
            <input
              type="password"
              required
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </label>
          <label>
            <input
              type="checkbox"
              checked={confirmation}
              onChange={(e) => setConfirmation(e.target.checked)}
            />{' '}
            Ich möchte diese Aktion ausführen.
          </label>
          <button disabled={busy || !confirmation || !password}>Bestätigen</button>
          <button
            type="button"
            disabled={busy}
            onClick={() => {
              setAction(undefined);
              setPassword('');
            }}
          >
            Abbrechen
          </button>
        </form>
      )}
      <SystemOperations />
    </section>
  );
}
