import BatchMove from './BatchMove';
import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { allPages, api } from '@platzhirsch/ui-runtime/api';
export default function Placement() {
  const qc = useQueryClient();
  const tenants = useQuery({
    queryKey: ['placement-tenants'],
    queryFn: () => allPages('v1/admin/tenants'),
    refetchInterval: 10000,
  });
  const servers = useQuery({
    queryKey: ['placement-servers'],
    queryFn: () => api('v1/admin/database-servers'),
  });
  const operations = useQuery({
    queryKey: ['placement-operations'],
    queryFn: () => api('v1/admin/operations'),
    refetchInterval: 5000,
  });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  return (
    <>
      <p className="eyebrow">ADMINISTRATION · PROVISIONING</p>
      <h2>Serverzuordnung & Umzüge</h2>
      <p>
        Nur lokal freigegebene Server stehen als Ziel zur Verfügung. Der Worker kopiert die Daten, vergleicht
        Inhalte und schaltet anschließend um. Die Quelldatenbank bleibt zur manuellen Nachkontrolle erhalten.
      </p>
      {[tenants.error, servers.error, operations.error].filter(Boolean).map((e: any, i) => (
        <p role="alert" key={i}>
          {e.message}
        </p>
      ))}
      {error && <p role="alert">{error}</p>}
      {notice && <p role="status">{notice}</p>}
      <section className="panel padded">
        <h3>Restaurant umziehen</h3>
        <form
          className="fields"
          onSubmit={async (e) => {
            e.preventDefault();
            const form = e.currentTarget;
            const f = new FormData(form);
            const tenant = tenants.data?.find((t: any) => String(t.id) === f.get('tenant'));
            if (!tenant) return;
            setBusy(true);
            setError('');
            try {
              await api('v1/admin/tenants/' + tenant.id + '/move', 'POST', {
                target_server_id: f.get('target') ? Number(f.get('target')) : null,
                placement_version: tenant.placement_version,
                password: f.get('password'),
                code: f.get('code'),
                backup_confirmed: true,
                downtime_confirmed: true,
              });
              form.reset();
              setNotice('Umzug eingereiht. Den Fortschritt siehst du unter Aufträge.');
              await qc.invalidateQueries();
            } catch (e) {
              setError((e as Error).message);
            } finally {
              setBusy(false);
            }
          }}
        >
          <label>
            Restaurant
            <select name="tenant" required>
              <option value="">Auswählen</option>
              {tenants.data
                ?.filter((t: any) => t.status === 'active')
                .map((t: any) => (
                  <option key={t.id} value={t.id}>
                    {t.name} · {t.server_id ? 'Server #' + t.server_id : 'Lokaler Server'}
                  </option>
                ))}
            </select>
          </label>
          <label>
            Zielserver
            <select name="target">
              <option value="">Lokaler Server</option>
              {servers.data?.servers
                .filter((s: any) => s.provisioning_enabled)
                .map((s: any) => (
                  <option key={s.id} value={s.id}>
                    {s.name} · {s.host}:{s.port}
                  </option>
                ))}
            </select>
          </label>
          <label>
            Administratorkennwort
            <input name="password" type="password" autoComplete="current-password" required />
          </label>
          <label>
            Frischer Zwei-Faktor-Code
            <input name="code" inputMode="numeric" pattern="[0-9]{6}" autoComplete="one-time-code" required />
          </label>
          <label>
            <input type="checkbox" required /> Aktuelle Sicherung geprüft
          </label>
          <label>
            <input type="checkbox" required /> Ausfallzeit abgestimmt; externe SQL-Schreibzugriffe gestoppt
          </label>
          <button disabled={busy}>Geprüften Umzug starten</button>
        </form>
      </section>
      {tenants.data && servers.data && (
        <BatchMove
          tenants={tenants.data}
          servers={servers.data.servers || []}
          done={async () => {
            await qc.invalidateQueries();
          }}
        />
      )}
      <section className="panel padded">
        <h3>Aufträge</h3>
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Auftrag</th>
                <th>Restaurant</th>
                <th>Vorgang</th>
                <th>Status</th>
                <th>Hinweis</th>
              </tr>
            </thead>
            <tbody>
              {operations.data?.map((o: any) => (
                <tr key={o.id}>
                  <td>#{o.id}</td>
                  <td>#{o.tenant_id}</td>
                  <td>
                    {o.kind === 'move' ? 'Umzug' : 'Modulaktivierung'} {o.module_code}
                  </td>
                  <td>{o.status}</td>
                  <td>{o.error_code || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </>
  );
}
