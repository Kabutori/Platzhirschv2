import { Toggle } from '@platzhirsch/ui-runtime/controls';
import { useState, useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2, Save, Search } from 'lucide-react';
import { api } from '@platzhirsch/ui-runtime/api';
import { useData, Loading, ErrorBox, Modal, type Row } from '@platzhirsch/ui-runtime/components';
function Editor({
  role,
  catalog,
  families,
  tenant,
  done,
  onDirty,
}: {
  onDirty?: (value: boolean) => void;
  role: Row;
  catalog: Record<string, string>;
  families: Row[];
  tenant?: string;
  done: () => Promise<void>;
}) {
  const [name, setName] = useState(role.name || ''),
    [checked, setChecked] = useState<string[]>(role.permissions || []),
    [busy, setBusy] = useState(false),
    [error, setError] = useState<unknown>();
  const groups = families.length
    ? families
    : [
        {
          module: 'restaurant',
          code: 'all',
          label: 'Restaurantrechte',
          permissions: Object.entries(catalog).map(([code, label]) => ({ code, label })),
        },
      ];
  const [search, setSearch] = useState('');
  const [group, setGroup] = useState(groups[0]?.module + ':' + groups[0]?.code);
  const active = groups.find((g) => g.module + ':' + g.code === group) || groups[0];
  const dirty =
    name !== (role.name || '') ||
    JSON.stringify([...checked].sort()) !== JSON.stringify([...(role.permissions || [])].sort());
  useEffect(() => {
    onDirty?.(dirty);
  }, [dirty, onDirty]);
  function toggle(code: string) {
    setChecked(checked.includes(code) ? checked.filter((c) => c !== code) : [...checked, code]);
  }
  return (
    <form
      onSubmit={async (e) => {
        e.preventDefault();
        setBusy(true);
        setError(undefined);
        try {
          await api(
            'v1/restaurant/roles' + (role.id ? '/' + role.id : ''),
            role.id ? 'PATCH' : 'POST',
            { name, permissions: checked, version: role.version },
            tenant,
          );
          await done();
        } catch (e) {
          setError(e);
        } finally {
          setBusy(false);
        }
      }}
    >
      <div className="toolbar">
        <label>
          Rollenname
          <input required maxLength={120} value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <span className="rights-status">{dirty ? 'Ungespeicherte Änderungen' : 'Gespeicherte Rechte'}</span>
        <button className="primary" disabled={busy || !name.trim() || (!!role.id && !dirty)}>
          <Save size={16} />
          Speichern
        </button>
      </div>
      <ErrorBox error={error} />
      <div className="rights-workspace">
        <div className="rights-groups" aria-label="Berechtigungsgruppen">
          {groups.map((g) => {
            const codes: string[] = g.permissions.map((p: Row) => p.code);
            const count = codes.filter((code) => checked.includes(code)).length;
            return (
              <div
                key={g.module + ':' + g.code}
                className={'rights-group' + (g === active ? ' selected' : '')}
              >
                <button
                  type="button"
                  className="rights-group-select"
                  aria-pressed={g === active}
                  onClick={() => setGroup(g.module + ':' + g.code)}
                >
                  {g.label}
                  <small>
                    {count}/{codes.length}
                  </small>
                </button>
                <Toggle
                  group
                  label={'Alle Rechte: ' + g.label}
                  checked={count === codes.length}
                  mixed={count > 0 && count < codes.length}
                  disabled={busy}
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
          <span className="muted">{active?.module}</span>
        </div>
        <section className="panel rights-list" aria-label={active?.label}>
          {active?.permissions
            .filter((p: Row) => (p.label + ' ' + p.code).toLowerCase().includes(search.toLowerCase()))
            .map((p: Row) => (
              <div className="rights-row" key={p.code}>
                <div>
                  <strong>{p.label}</strong>
                  <code>{p.code}</code>
                </div>
                <Toggle
                  label={p.label}
                  checked={checked.includes(p.code)}
                  disabled={busy}
                  onChange={() => toggle(p.code)}
                />
              </div>
            ))}
          {active &&
            !active.permissions.some((p: Row) =>
              (p.label + ' ' + p.code).toLowerCase().includes(search.toLowerCase()),
            ) && <p className="padded">Keine passenden Berechtigungen.</p>}
        </section>
      </div>
      <p className="muted">
        Speichern aktiviert diese Restaurantrechte unmittelbar. Neue Berechtigungen werden durch Module
        registriert. Buchungsänderungen benötigen zusätzlich das Leserecht.
      </p>
    </form>
  );
}
export default function RestaurantRoles({ tenant }: { tenant?: string }) {
  const q = useData('v1/restaurant/roles', tenant),
    qc = useQueryClient();
  const [dirty, setDirty] = useState(false);
  const [selected, setSelected] = useState<number | null>(null),
    [creating, setCreating] = useState(false),
    [error, setError] = useState<unknown>();
  const role = q.data?.roles.find((r: Row) => r.id === selected) || q.data?.roles[0];
  async function refresh() {
    await qc.invalidateQueries();
  }
  return (
    <>
      <div className="toolbar">
        <p className="muted">
          Rolle → Berechtigungsgruppe → Berechtigung. Eigene Mitarbeiterrollen gelten nur für dieses
          Restaurant.
        </p>
        <button className="primary" onClick={() => setCreating(true)}>
          <Plus size={16} />
          Rolle anlegen
        </button>
      </div>
      <ErrorBox error={error || q.error} />
      {q.isPending ? (
        <Loading />
      ) : (
        q.data && (
          <>
            <nav className="rights-roletabs" aria-label="Restaurantrollen">
              {q.data.roles.map((r: Row) => (
                <button
                  key={r.id}
                  aria-pressed={role?.id === r.id}
                  onClick={() => {
                    if (
                      role?.id !== r.id &&
                      (!dirty || confirm('Rolle wechseln? Ungespeicherte Eingaben werden verworfen.'))
                    )
                      setSelected(r.id);
                  }}
                >
                  {r.name}
                </button>
              ))}
            </nav>
            {role ? (
              <>
                <div className="toolbar">
                  <h2>{role.name}</h2>
                  <button
                    onClick={async () => {
                      if (!confirm('Unbenutzte Rolle löschen?')) return;
                      try {
                        await api('v1/restaurant/roles/' + role.id, 'DELETE', undefined, tenant);
                        await refresh();
                      } catch (e) {
                        setError(e);
                      }
                    }}
                  >
                    <Trash2 size={16} />
                    Löschen
                  </button>
                </div>
                <Editor
                  key={role.id + ':' + role.version}
                  role={role}
                  onDirty={setDirty}
                  catalog={q.data.catalog}
                  families={q.data.families || []}
                  tenant={tenant}
                  done={refresh}
                />
              </>
            ) : (
              <p>Noch keine eigenen Mitarbeiterrollen angelegt.</p>
            )}
          </>
        )
      )}
      {creating && q.data && (
        <Modal title="Rolle anlegen" close={() => setCreating(false)}>
          <Editor
            role={{}}
            catalog={q.data.catalog}
            families={q.data.families || []}
            tenant={tenant}
            done={async () => {
              setCreating(false);
              await refresh();
            }}
          />
        </Modal>
      )}
    </>
  );
}
