import { useState } from 'react';
import { api } from '@platzhirsch/ui-runtime/api';
import { Modal, Form, type Row } from '@platzhirsch/ui-runtime/components';
export default function BatchMove({
  tenants,
  servers,
  done,
}: {
  tenants: Row[];
  servers: Row[];
  done: () => Promise<void>;
}) {
  const [source, setSource] = useState('all'),
    [target, setTarget] = useState(''),
    [selected, setSelected] = useState<number[]>([]),
    [snapshot, setSnapshot] = useState<Row | null>(null),
    [notice, setNotice] = useState('');
  const eligible = tenants.filter(
    (t) => t.status === 'active' && (source === 'all' || String(t.server_id ?? 'local') === source),
  );
  const choices = [{ id: 'local', name: 'Lokaler Server' }, ...servers];
  return (
    <section className="panel padded">
      <h3>Mehrere Restaurants umziehen</h3>
      <p>
        Bis zu 50 Restaurants gemeinsam prüfen und beauftragen. Jeder Umzug wird separat ausgeführt.
        Erfolgreiche Umzüge werden bei einem späteren Fehler eines anderen Restaurants nicht zurückgerollt.
      </p>
      {notice && <p role="status">{notice}</p>}
      <div className="fields">
        <label>
          Quellserver
          <select
            value={source}
            onChange={(e) => {
              setSource(e.target.value);
              setSelected([]);
            }}
          >
            <option value="all">Alle Server</option>
            {choices.map((s) => (
              <option value={s.id} key={s.id}>
                {s.name}
              </option>
            ))}
          </select>
        </label>
        <label>
          Zielserver für Auswahl
          <select value={target} onChange={(e) => setTarget(e.target.value)}>
            <option value="">Lokaler Server</option>
            {servers
              .filter((s) => s.provisioning_enabled)
              .map((s) => (
                <option value={s.id} key={s.id}>
                  {s.name}
                </option>
              ))}
          </select>
        </label>
      </div>
      <div className="batch-tenant-list">
        {eligible.map((t) => (
          <label className="checkfield" key={t.id}>
            <input
              type="checkbox"
              checked={selected.includes(t.id)}
              disabled={!selected.includes(t.id) && selected.length >= 50}
              onChange={() =>
                setSelected(
                  selected.includes(t.id) ? selected.filter((id) => id !== t.id) : [...selected, t.id],
                )
              }
            />
            {t.name} · #{t.id} · {t.server_id ? 'Server #' + t.server_id : 'Lokal'}
          </label>
        ))}
        {!eligible.length && <p>Keine aktiven Restaurants auf diesem Quellserver.</p>}
      </div>
      <button
        className="primary"
        disabled={!selected.length}
        onClick={() => {
          const rows = tenants.filter((t) => selected.includes(t.id));
          setNotice('');
          setSnapshot({
            rows: rows.map((t) => ({ id: t.id, name: t.name, placement_version: t.placement_version })),
            target_server_id: target ? Number(target) : null,
            target_name: choices.find((s) => String(s.id) === (target || 'local'))?.name || 'Zielserver',
          });
        }}
      >
        Umzug für {selected.length} Restaurants prüfen
      </button>
      {snapshot && (
        <Modal title="Cluster-Umzug bestätigen" close={() => setSnapshot(null)}>
          <p>Ziel: {snapshot.target_name}</p>
          <ul>
            {snapshot.rows.map((t: Row) => (
              <li key={t.id}>
                {t.name} · #{t.id}
              </li>
            ))}
          </ul>
          <p>
            Ab der Beauftragung sind alle ausgewählten Restaurants bis zu ihrem jeweiligen Abschluss gesperrt. Große Auswahlen können die Ausfallzeit verlängern. Quelldatenbanken bleiben zur
            Nachkontrolle erhalten.
          </p>
          <Form
            fields={[
              { key: 'password', label: 'Administratorkennwort', type: 'password', required: true },
              { key: 'code', label: 'Frischer Zwei-Faktor-Code', required: true },
              {
                key: 'backup_confirmed',
                label: 'Aktuelle Sicherungen für alle ausgewählten Restaurants geprüft',
                type: 'checkbox',
                required: true,
              },
              {
                key: 'downtime_confirmed',
                label: 'Ausfallzeiten abgestimmt und externe SQL-Schreibzugriffe gestoppt',
                type: 'checkbox',
                required: true,
              },
            ]}
            label="Bestätigte Umzüge einreihen"
            onSave={async (data) => {
              const result = await api('v1/admin/tenant-moves', 'POST', {
                ...data,
                target_server_id: snapshot.target_server_id,
                tenants: snapshot.rows.map((t: Row) => ({
                  id: t.id,
                  placement_version: t.placement_version,
                })),
              });
              setSnapshot(null);
              setSelected([]);
              setNotice(result.operations.length + ' Umzüge eingereiht. Einzelstatus unter Aufträge.');
              await done();
            }}
          />
        </Modal>
      )}
    </section>
  );
}
