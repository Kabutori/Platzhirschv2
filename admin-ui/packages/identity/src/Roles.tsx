import { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { LockKeyhole, Plus, Pencil, Trash2, Search } from 'lucide-react';
import { api } from '@platzhirsch/ui-runtime/api';
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
const same = (a: string[], b: string[]) => JSON.stringify([...a].sort()) === JSON.stringify([...b].sort());
function Switch({
  label,
  checked,
  disabled,
  onChange,
}: {
  label: string;
  checked: boolean;
  disabled: boolean;
  onChange: () => void;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-label={label}
      aria-checked={checked}
      disabled={disabled}
      className="rights-switch"
      onClick={onChange}
    >
      <span />
    </button>
  );
}
function Editor({
  role,
  roles,
  families,
  refresh,
  select,
  create,
}: {
  role: Role;
  roles: Role[];
  families: Family[];
  refresh: () => Promise<unknown>;
  select: (id: number) => void;
  create: () => void;
}) {
  const [name, setName] = useState(role.name);
  const [renaming, setRenaming] = useState(false);
  const [checked, setChecked] = useState(role.draft_permissions);
  const [familyKey, setFamilyKey] = useState(families[0]?.module + ':' + families[0]?.code);
  const [search, setSearch] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const dirty = name !== role.name || !same(checked, role.draft_permissions);
  const pending = !same(role.permissions, role.draft_permissions) || !role.activated_at;
  const family = families.find((f) => f.module + ':' + f.code === familyKey) || families[0];
  const status = role.locked
    ? 'Systemrolle geschützt'
    : dirty
      ? 'Ungespeicherte Änderungen'
      : !pending
        ? 'Aktiv'
        : role.tested_version === role.version
          ? 'Geprüft · Aktivierung ausstehend'
          : 'Lokal gespeichert · Prüfung ausstehend';
  useEffect(() => {
    const warn = (e: BeforeUnloadEvent) => {
      if (dirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);
  function leave(action: () => void) {
    if (!dirty || confirm('Ungespeicherte Änderungen verwerfen?')) action();
  }
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
  const toggle = (code: string) =>
    setChecked(checked.includes(code) ? checked.filter((c) => c !== code) : [...checked, code]);
  return (
    <div className="rights-workspace">
      <div className="rights-savebar panel">
        <span className={'rights-status' + (dirty ? ' changed' : '')} role="status">
          {status}
        </span>
        <div className="rights-actions">
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
        </div>
      </div>
      <div className="rights-rolebar">
        <div className="rights-roletabs" aria-label="Plattformrollen">
          {roles.map((r) => (
            <button
              disabled={busy}
              key={r.id}
              aria-pressed={r.id === role.id}
              onClick={() => leave(() => select(r.id))}
            >
              {r.name}
              {r.locked && <LockKeyhole size={14} aria-label="Geschützte Systemrolle" />}
            </button>
          ))}
        </div>
        <button className="rights-add" disabled={busy} onClick={() => leave(create)}>
          <Plus size={15} />
          Rolle anlegen
        </button>
      </div>
      <div className="rights-actions">
        <button disabled={role.locked || busy} onClick={() => setRenaming(!renaming)}>
          <Pencil size={14} />
          Umbenennen
        </button>
        <button
          className="danger"
          disabled={role.locked || busy}
          onClick={async () => {
            if (!confirm('Diese unbenutzte Plattformrolle löschen?')) return;
            setBusy(true);
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
          <Trash2 size={14} />
          Rolle löschen
        </button>
        <span className="muted">
          {role.locked ? 'Alle registrierten Rechte' : checked.length + ' Rechte im Entwurf'}
        </span>
      </div>
      {renaming && (
        <label className="rights-rename">
          Rollenname
          <input autoFocus value={name} disabled={busy} onChange={(e) => setName(e.target.value)} />
        </label>
      )}
      <div className="rights-groups" aria-label="Berechtigungsgruppen">
        {families.map((f) => {
          const codes = f.permissions.map((p) => p.code);
          const count = role.locked ? codes.length : codes.filter((c) => checked.includes(c)).length;
          return (
            <div key={f.module + ':' + f.code} className={'rights-group' + (f === family ? ' selected' : '')}>
              <button
                className="rights-group-select"
                aria-pressed={f === family}
                onClick={() => setFamilyKey(f.module + ':' + f.code)}
              >
                {f.label}
                <small>
                  {count}/{codes.length}
                </small>
              </button>
              <Switch
                label={'Alle Rechte: ' + f.label}
                checked={count === codes.length}
                disabled={role.locked || busy}
                onChange={() =>
                  setChecked(
                    count === codes.length
                      ? checked.filter((c) => !codes.includes(c))
                      : [...new Set([...checked, ...codes])],
                  )
                }
              />
            </div>
          );
        })}
      </div>
      <div className="rights-search">
        <Search size={16} />
        <input
          aria-label="Berechtigungen suchen"
          placeholder="Berechtigung oder Code suchen …"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <span className="muted">{family?.module}</span>
      </div>
      <section className="panel rights-list" aria-label={family?.label}>
        {family?.permissions
          .filter((p) => (p.label + ' ' + p.code).toLowerCase().includes(search.toLowerCase()))
          .map((p) => (
            <div className="rights-row" key={p.code}>
              <div>
                <strong>{p.label}</strong>
                <code>{p.code}</code>
              </div>
              <Switch
                label={p.label}
                checked={role.locked || checked.includes(p.code)}
                disabled={role.locked || busy}
                onChange={() => toggle(p.code)}
              />
            </div>
          ))}
        {family &&
          !family.permissions.some((p) =>
            (p.label + ' ' + p.code).toLowerCase().includes(search.toLowerCase()),
          ) && <p className="padded">Keine passenden Berechtigungen.</p>}
      </section>
      <p className="muted rights-note">
        Berechtigungen werden von installierten Modulen bereitgestellt. Die Prüfung gilt für diese
        Installation; eine Verteilung auf weitere Server ist noch nicht verfügbar.
      </p>
      {error && <p role="alert">{error}</p>}
    </div>
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
  const [template, setTemplate] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const role = q.data?.roles.find((r) => r.id === selected) || q.data?.roles[0];
  return (
    <>
      <p className="muted">Rolle → Berechtigungsgruppe → Berechtigung · Administration</p>
      {q.isPending && <p role="status">Rollen werden geladen …</p>}
      {q.error && <p role="alert">{q.error.message}</p>}
      {role && q.data && (
        <Editor
          key={role.id + ':' + role.version + ':' + role.tested_version + ':' + role.activated_at}
          role={role}
          roles={q.data.roles}
          families={q.data.families}
          refresh={() => q.refetch()}
          select={setSelected}
          create={() => setCreating(true)}
        />
      )}
      {creating && (
        <div className="rights-create panel padded">
          <form
            onSubmit={async (e) => {
              e.preventDefault();
              setBusy(true);
              setError('');
              try {
                const source = q.data?.roles.find((r) => String(r.id) === template);
                const r = await api('v1/admin/platform-roles', 'POST', {
                  name,
                  permissions: source?.permissions || [],
                });
                setSelected(r.id);
                setCreating(false);
                setName('');
                setTemplate('');
                await q.refetch();
              } catch (e) {
                setError((e as Error).message);
              } finally {
                setBusy(false);
              }
            }}
          >
            <h2>Neue Plattformrolle</h2>
            <label>
              Neue Rolle
              <input
                autoFocus
                required
                maxLength={120}
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
            </label>
            <label>
              Berechtigungs-Vorlage
              <select value={template} onChange={(e) => setTemplate(e.target.value)}>
                <option value="">Leer beginnen</option>
                {q.data?.roles
                  .filter((r) => !r.locked && r.activated_at)
                  .map((r) => (
                    <option key={r.id} value={r.id}>
                      {r.name} · aktive Rechte
                    </option>
                  ))}
              </select>
            </label>
            <p className="muted">
              Die Kopie wird als neuer Entwurf angelegt und muss separat geprüft und aktiviert werden.
            </p>
            {error && <p role="alert">{error}</p>}
            <div className="rights-actions">
              <button type="button" disabled={busy} onClick={() => setCreating(false)}>
                Abbrechen
              </button>
              <button className="primary" disabled={busy || !name.trim()}>
                Anlegen
              </button>
            </div>
          </form>
        </div>
      )}
    </>
  );
}
