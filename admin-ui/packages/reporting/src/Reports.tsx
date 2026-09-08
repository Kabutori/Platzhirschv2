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
  const totals = (q.data?.days || []).reduce(
    (sum: any, day: any) => ({
      reservations: sum.reservations + day.reservations,
      guests: sum.guests + day.guests,
      cancelled: sum.cancelled + day.cancelled,
      no_show: sum.no_show + (day.no_show || 0),
      arrived: sum.arrived + (day.arrived || 0),
    }),
    { reservations: 0, guests: 0, cancelled: 0, no_show: 0, arrived: 0 },
  );
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
        key={range.from + range.to}
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
      {q.isPending && <p role="status">Auswertung wird geladen …</p>}
      {q.data && !q.error && (
        <div className="stats">
          {[
            ['Buchungen gesamt', totals.reservations + totals.cancelled, 'Einschließlich Stornierungen'],
            ['Gäste', totals.guests, 'Ohne Storno und nicht erschienene Buchungen'],
            ['Eingetroffen / abgeschlossen', totals.arrived, 'Anzahl Buchungen'],
            [
              'Nicht erschienen',
              totals.no_show,
              (totals.reservations ? ((100 * totals.no_show) / totals.reservations).toFixed(1) : '0') +
                ' % der nicht stornierten Buchungen',
            ],
          ].map(([label, value, note]) => (
            <section className="stat" key={label}>
              <span>{label}</span>
              <strong>{value}</strong>
              <small>{note}</small>
            </section>
          ))}
        </div>
      )}
      <section className="panel padded">
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Tag</th>
                <th>Reservierungen</th>
                <th>Gäste</th>
                <th>Storniert</th>
                <th>Nicht erschienen</th>
                <th>Eingetroffen / abgeschlossen</th>
              </tr>
            </thead>
            <tbody>
              {q.data?.days.map((r: any) => (
                <tr key={r.date}>
                  <td>{r.date}</td>
                  <td>{r.reservations}</td>
                  <td>{r.guests}</td>
                  <td>{r.cancelled}</td>
                  <td>{r.no_show || 0}</td>
                  <td>{r.arrived || 0}</td>
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
      <section className="panel padded">
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
