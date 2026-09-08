import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
export default function Reports({ tenant, canManage = false }: { tenant?: string; canManage?: boolean }) {
  const today = new Date().toISOString().slice(0, 10);
  const [range, setRange] = useState({ from: today, to: today });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const qc = useQueryClient();
  const q = useQuery({
    queryKey: ['reporting', tenant, range],
    queryFn: () => api('v1/restaurant/reporting?' + new URLSearchParams(range), 'GET', undefined, tenant),
  });
  async function mutate(path: string, method: string, body?: unknown) {
    setBusy(true);
    setError('');
    try {
      await api('v1/restaurant/reporting/saved' + path, method, body, tenant);
      await qc.invalidateQueries({ queryKey: ['reporting'] });
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <>
      <p className="eyebrow">RESTAURANT · REPORTING</p>
      <h2>Erweiterte Auswertungen</h2>
      <form
        className="toolbar"
        onSubmit={(e) => {
          e.preventDefault();
          const f = new FormData(e.currentTarget);
          setRange({ from: String(f.get('from')), to: String(f.get('to')) });
        }}
      >
        <label>
          Von
          <input type="date" name="from" required defaultValue={range.from} />
        </label>
        <label>
          Bis
          <input type="date" name="to" required defaultValue={range.to} />
        </label>
        <button>Auswerten</button>
      </form>
      <p>Bis zu 93 Kalendertage. Gruppierung nach der Zeitzone des Restaurants.</p>
      {(error || q.error) && <p role="alert">{error || q.error?.message}</p>}
      <section className="panel">
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Tag</th>
                <th>Reservierungen</th>
                <th>Gäste</th>
                <th>Storniert</th>
              </tr>
            </thead>
            <tbody>
              {q.data?.days.map((r: any) => (
                <tr key={r.date}>
                  <td>{r.date}</td>
                  <td>{r.reservations}</td>
                  <td>{r.guests}</td>
                  <td>{r.cancelled}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {q.data?.days.length === 0 && <p>Keine Reservierungen im Zeitraum.</p>}
      </section>
      {canManage && (
        <form
          className="toolbar"
          onSubmit={(e) => {
            e.preventDefault();
            void mutate('', 'POST', { ...range, name: new FormData(e.currentTarget).get('name') });
          }}
        >
          <label>
            Auswertung benennen
            <input name="name" required maxLength={100} />
          </label>
          <button disabled={busy || !q.data}>Zeitraum speichern</button>
        </form>
      )}
      <section className="panel">
        <h3>Gespeicherte Zeiträume</h3>
        {q.data?.saved.map((s: any) => (
          <div className="toolbar" key={s.id}>
            <span>
              {s.name} · {s.date_from} – {s.date_to}
            </span>
            <button onClick={() => setRange({ from: s.date_from, to: s.date_to })}>Öffnen</button>
            {canManage && (
              <button
                disabled={busy}
                onClick={() => {
                  if (confirm('Gespeicherten Zeitraum löschen?')) void mutate('/' + s.id, 'DELETE');
                }}
              >
                Löschen
              </button>
            )}
          </div>
        ))}
      </section>
    </>
  );
}
