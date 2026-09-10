import { useState } from 'react';
import { api } from '@platzhirsch/ui-runtime/api';
import { Modal, Loading, ErrorBox, useData, type Row } from '@platzhirsch/ui-runtime/components';
import { Toggle } from '@platzhirsch/ui-runtime/controls';
const days = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
function Editor({
  snapshot,
  tenant,
  done,
  close,
}: {
  snapshot: Row;
  tenant?: string;
  done: () => Promise<void>;
  close: () => void;
}) {
  const [rows, setRows] = useState<Row[]>(
    snapshot.rows.map((r: Row) => ({
      weekday: r.weekday,
      opens: r.opens.slice(0, 5),
      closes: r.closes.slice(0, 5),
    })),
  );
  const [busy, setBusy] = useState(false),
    [error, setError] = useState<unknown>();
  return (
    <form
      onSubmit={async (e) => {
        e.preventDefault();
        if (busy) return;
        setBusy(true);
        setError(undefined);
        try {
          await api('v1/restaurant/hours-week', 'PUT', { revision: snapshot.revision, rows }, tenant);
          await done();
        } catch (e) {
          setError(e);
        } finally {
          setBusy(false);
        }
      }}
    >
      <p className="padded">
        Alle Wochentage gemeinsam bearbeiten. Schließzeiten vor der Öffnung gelten am Folgetag. Geschlossene
        Tage haben keine Zeitfenster. Bestehende Reservierungen werden nicht verändert.
      </p>
      <ErrorBox error={error} />
      <div className="hours-week">
        {days.map((day, i) => {
          const entries = rows
            .map((r, index): Row & { index: number } => ({ ...r, index }))
            .filter((r) => r.weekday === i + 1);
          return (
            <section key={day}>
              <header>
                <strong>{day}</strong>
                <Toggle
                  label={day + ' geöffnet'}
                  checked={entries.length > 0}
                  disabled={busy}
                  onChange={() =>
                    setRows(
                      entries.length
                        ? rows.filter((r) => r.weekday !== i + 1)
                        : [...rows, { weekday: i + 1, opens: '12:00', closes: '22:00' }],
                    )
                  }
                />
              </header>
              {entries.map((r) => (
                <div className="hours-window" key={r.index}>
                  {['opens', 'closes'].map((key) => (
                    <label key={key}>
                      {key === 'opens' ? 'Von' : 'Bis'}
                      <input
                        type="time"
                        required
                        disabled={busy}
                        aria-label={day + (key === 'opens' ? ' öffnet' : ' schließt') + ' ' + (r.index + 1)}
                        value={r[key]}
                        onChange={(e) =>
                          setRows(rows.map((v, n) => (n === r.index ? { ...v, [key]: e.target.value } : v)))
                        }
                      />
                    </label>
                  ))}
                  <button
                    type="button"
                    disabled={busy}
                    aria-label={day + ' Zeitfenster entfernen ' + (r.index + 1)}
                    onClick={() => setRows(rows.filter((_, n) => n !== r.index))}
                  >
                    Entfernen
                  </button>
                </div>
              ))}
              {entries.length > 0 && (
                <button
                  type="button"
                  disabled={busy || rows.length >= 42}
                  onClick={() => setRows([...rows, { weekday: i + 1, opens: '18:00', closes: '23:00' }])}
                >
                  + Zeitfenster {day}
                </button>
              )}
              {!entries.length && <small>Geschlossen</small>}
            </section>
          );
        })}
      </div>
      <footer className="form-footer">
        <button type="button" disabled={busy} onClick={close}>
          Abbrechen
        </button>
        <button className="primary" disabled={busy}>
          {busy ? 'Wird gespeichert …' : 'Wochenplan speichern'}
        </button>
      </footer>
    </form>
  );
}
export default function HoursWeek({
  tenant,
  close,
  done,
}: {
  tenant?: string;
  close: () => void;
  done: () => Promise<void>;
}) {
  const q = useData('v1/restaurant/hours-week', tenant);
  return (
    <Modal title="Öffnungszeiten bearbeiten" close={close}>
      {q.isPending ? (
        <Loading />
      ) : q.error ? (
        <ErrorBox error={q.error} />
      ) : (
        <Editor snapshot={q.data} tenant={tenant} close={close} done={done} />
      )}
    </Modal>
  );
}
