import { useState, useEffect, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../api';
type Role = {
  id: number;
  name: string;
  locked: boolean;
  permissions: string[];
  draft_permissions: string[];
  version: number;
  tested_version: number | null;
  activated_at: string | null;
};
type Family = { module: string; code: string; label: string; permissions: { code: string; label: string }[] };
function GroupToggle({
  family,
  checked,
  disabled,
  toggle,
}: {
  family: Family;
  checked: string[];
  disabled: boolean;
  toggle: () => void;
}) {
  const ref = useRef<HTMLInputElement>(null);
  const count = family.permissions.filter((p) => checked.includes(p.code)).length;
  useEffect(() => {
    if (ref.current) ref.current.indeterminate = count > 0 && count < family.permissions.length;
  }, [count, family.permissions.length]);
  return (
    <label>
      <input
        ref={ref}
        type="checkbox"
        checked={count === family.permissions.length}
        disabled={disabled}
        onChange={toggle}
      />
      Alle Rechte dieser Gruppe
    </label>
  );
}
function Editor({
  role,
  families,
  refresh,
}: {
  role: Role;
  families: Family[];
  refresh: () => Promise<unknown>;
}) {
  const [name, setName] = useState(role.name);
  const [checked, setChecked] = useState(role.draft_permissions);
  const [familyKey, setFamilyKey] = useState(families[0]?.code || '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const family = families.find((f) => f.code === familyKey) || families[0];
  const dirty =
    name !== role.name ||
    JSON.stringify([...checked].sort()) !== JSON.stringify([...role.draft_permissions].sort());
  const pending =
    JSON.stringify([...role.permissions].sort()) !== JSON.stringify([...role.draft_permissions].sort()) ||
    !role.activated_at;
  async function action(kind: string) {
    setBusy(true);
    setError('');
    try {
      await api(
        'v1/admin/platform-roles/' + role.id + (kind === 'save' ? '' : '/' + kind),
        kind === 'save' ? 'PATCH' : 'POST',
        kind === 'save' ? { name, permissions: checked, version: role.version } : { version: role.version },
      );
      await refresh();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <section className="panel padded">
      <h2>
        {role.locked ? '🔒 ' : ''}
        {role.name}
      </h2>
      <p>
        {role.locked
          ? 'Geschützte Systemrolle mit vollständigem Plattformzugriff.'
          : dirty
            ? 'Nicht gespeicherte Änderungen'
            : role.tested_version === role.version
              ? pending
                ? 'Entwurf geprüft – noch nicht aktiviert'
                : 'Aktiv'
              : 'Entwurf gespeichert – Prüfung ausstehend'}
      </p>
      <p className="muted">
        Die Prüfung kontrolliert registrierte Berechtigungscodes. Aktivieren übernimmt die Rechte für diese
        Installation; es ist kein verteilter Rollout auf andere Server.
      </p>
      <label>
        Rollenname
        <input value={name} disabled={role.locked || busy} onChange={(e) => setName(e.target.value)} />
      </label>
      <div className="toolbar" role="tablist" aria-label="Berechtigungsgruppen">
        {families.map((f) => (
          <button
            role="tab"
            key={f.module + f.code}
            aria-selected={f.code === familyKey}
            onClick={() => setFamilyKey(f.code)}
          >
            {f.label}
          </button>
        ))}
      </div>
      {family && (
        <fieldset disabled={role.locked || busy}>
          <legend>{family.label}</legend>
          <GroupToggle
            family={family}
            checked={role.locked ? family.permissions.map((p) => p.code) : checked}
            disabled={role.locked || busy}
            toggle={() => {
              const codes = family.permissions.map((p) => p.code);
              setChecked(
                codes.every((c) => checked.includes(c))
                  ? checked.filter((c) => !codes.includes(c))
                  : [...new Set([...checked, ...codes])],
              );
            }}
          />
          {family.permissions.map((p) => (
            <label key={p.code} className="permission-option">
              <input
                type="checkbox"
                checked={role.locked || checked.includes(p.code)}
                onChange={(e) =>
                  setChecked(e.target.checked ? [...checked, p.code] : checked.filter((c) => c !== p.code))
                }
              />
              {p.label} <code>{p.code}</code>
            </label>
          ))}
        </fieldset>
      )}
      {error && <p role="alert">{error}</p>}
      <div className="toolbar">
        <button disabled={role.locked || busy || !dirty || !name.trim()} onClick={() => action('save')}>
          Lokal speichern
        </button>
        <button
          disabled={role.locked || busy || dirty || role.tested_version === role.version}
          onClick={() => action('check')}
        >
          Entwurf prüfen
        </button>
        <button
          className="primary"
          disabled={role.locked || busy || dirty || role.tested_version !== role.version || !pending}
          onClick={() => action('activate')}
        >
          Rechte aktivieren
        </button>
        <button
          disabled={role.locked || busy}
          onClick={async () => {
            if (!confirm('Diese unbenutzte Plattformrolle löschen?')) return;
            setBusy(true);
            setError('');
            try {
              await api('v1/admin/platform-roles/' + role.id, 'DELETE', { version: role.version });
              await refresh();
            } catch (e) {
              setError((e as Error).message);
            } finally {
              setBusy(false);
            }
          }}
        >
          Rolle löschen
        </button>
      </div>
    </section>
  );
}
export default function Roles() {
  const q = useQuery({
    queryKey: ['platform-roles'],
    queryFn: () => api<{ roles: Role[]; families: Family[] }>('v1/admin/platform-roles'),
  });
  const [selected, setSelected] = useState<number>();
  const [creating, setCreating] = useState(false);
  const [name, setName] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const role = q.data?.roles.find((r) => r.id === selected) || q.data?.roles[0];
  return (
    <>
      <div className="toolbar">
        <h2>Plattformrollen</h2>
        <button onClick={() => setCreating(true)}>Rolle anlegen</button>
      </div>
      <p>
        Diese Rollen gelten ausschließlich für Administration. Restaurantrollen werden im jeweiligen
        Restaurant verwaltet. Berechtigungscodes stammen aus installierten Modulen.
      </p>
      {q.isPending && <p role="status">Rollen werden geladen …</p>}
      {q.error && <p role="alert">{q.error.message}</p>}
      <div className="toolbar">
        {q.data?.roles.map((r) => (
          <button key={r.id} aria-pressed={r.id === role?.id} onClick={() => setSelected(r.id)}>
            {r.locked ? '🔒 ' : ''}
            {r.name}
          </button>
        ))}
      </div>
      {role && q.data && (
        <Editor
          key={role.id + ':' + role.version + ':' + role.tested_version + ':' + role.activated_at}
          role={role}
          families={q.data.families}
          refresh={() => q.refetch()}
        />
      )}
      {creating && (
        <form
          className="panel padded"
          onSubmit={async (e) => {
            e.preventDefault();
            setBusy(true);
            setError('');
            try {
              const r = await api('v1/admin/platform-roles', 'POST', { name, permissions: [] });
              setSelected(r.id);
              setCreating(false);
              setName('');
              await q.refetch();
            } catch (e) {
              setError((e as Error).message);
            } finally {
              setBusy(false);
            }
          }}
        >
          <label>
            Neue Rolle
            <input required value={name} onChange={(e) => setName(e.target.value)} />
          </label>
          {error && <p role="alert">{error}</p>}
          <button type="button" disabled={busy} onClick={() => setCreating(false)}>
            Abbrechen
          </button>
          <button disabled={busy}>Anlegen</button>
        </form>
      )}
    </>
  );
}
