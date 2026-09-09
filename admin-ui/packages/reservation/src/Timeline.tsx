import { clock, labels, type Row } from '@platzhirsch/ui-runtime/components';
import './timeline.css';
// SQL timestamps are UTC. Position by elapsed time so DST days retain both repeated hours.
export function instant(value: string) {
  return new Date(value.replace(' ', 'T') + (/Z$|[+-]\d\d:\d\d$/.test(value) ? '' : 'Z')).getTime();
}
export function dayBounds(date: string, timezone: string) {
  const format = new Intl.DateTimeFormat('sv-SE', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  });
  function boundary(target: string) {
    let lo = Date.parse(target + 'T00:00:00Z') - 36 * 3600000,
      hi = lo + 72 * 3600000;
    while (hi - lo > 1) {
      const mid = Math.floor((lo + hi) / 2);
      if (format.format(new Date(mid)) < target) lo = mid;
      else hi = mid;
    }
    return hi;
  }
  const next = new Date(date + 'T12:00:00Z');
  next.setUTCDate(next.getUTCDate() + 1);
  return [boundary(date), boundary(next.toISOString().slice(0, 10))];
}
export default function Timeline({
  tables,
  reservations,
  closures = [],
  date,
  timezone,
  canWrite,
  edit,
}: {
  tables: Row[];
  reservations: Row[];
  closures?: Row[];
  date: string;
  timezone: string;
  canWrite: boolean;
  edit: (r: Row) => void;
}) {
  const [start, end] = dayBounds(date, timezone),
    span = end - start;
  const ticks = Array.from({ length: Math.ceil(span / 3600000) }, (_, i) => start + i * 3600000);
  const now = Date.now();
  return (
    <div className="timeline-scroll" tabIndex={0} role="region" aria-label="Tisch-Zeitstrahl">
      <div className="table-timeline">
        <div className="timeline-row">
          <strong>Tisch · Plätze</strong>
          <div className="timeline-hours">
            {ticks
              .filter((_, i) => i % 2 === 0)
              .map((t) => (
                <span key={t} style={{ left: ((t - start) / span) * 100 + '%' }}>
                  {new Date(t).toLocaleTimeString('de-DE', {
                    timeZone: timezone,
                    hour: '2-digit',
                    minute: '2-digit',
                    hourCycle: 'h23',
                  })}
                </span>
              ))}
          </div>
        </div>
        {tables.map((table) => {
          const bookings = reservations
            .filter(
              (r) =>
                (String(r.table_id) === String(table.id) ||
                  (r.additional_table_ids || []).map(String).includes(String(table.id))) &&
                !['cancelled', 'no_show'].includes(r.status) &&
                instant(r.starts_at) < end &&
                instant(r.ends_at) > start,
            )
            .sort((a, b) => instant(a.starts_at) - instant(b.starts_at));
          const closed = closures.filter(
            (c) =>
              String(c.room_id) === String(table.room_id) &&
              instant(c.starts_at) < end &&
              instant(c.ends_at) > start,
          );
          const lanes: number[] = [];
          const bars = bookings.map((r) => {
            let lane = lanes.findIndex((until) => until <= instant(r.starts_at));
            if (lane < 0) lane = lanes.length;
            lanes[lane] = instant(r.ends_at);
            return { r, lane };
          });
          return (
            <div className="timeline-row" key={table.id}>
              <strong>
                {table.name}
                <small>
                  {table.capacity} Plätze{!table.active ? ' · Inaktiv' : ''}
                </small>
              </strong>
              <div className="timeline-track" style={{ height: Math.max(1, lanes.length) * 38 + 8 }}>
                {now >= start && now < end && (
                  <span
                    className="timeline-now"
                    title="Aktuelle Uhrzeit"
                    style={{ left: ((now - start) / span) * 100 + '%' }}
                  />
                )}
                {closed.map((c) => (
                  <span
                    key={'closure-' + c.id}
                    className="timeline-closure"
                    title={
                      'Raum gesperrt: ' +
                      c.reason +
                      ' · ' +
                      clock(c.starts_at, timezone) +
                      '–' +
                      clock(c.ends_at, timezone)
                    }
                    style={{
                      left: ((Math.max(start, instant(c.starts_at)) - start) / span) * 100 + '%',
                      width:
                        ((Math.min(end, instant(c.ends_at)) - Math.max(start, instant(c.starts_at))) / span) *
                          100 +
                        '%',
                    }}
                  >
                    Gesperrt
                  </span>
                ))}
                {bars.map(({ r, lane }) => {
                  const label = `${r.guest_name} · ${clock(r.starts_at, timezone)}–${clock(r.ends_at, timezone)} · ${r.party_size} Personen · ${labels[r.status] || r.status}`;
                  const props = {
                    className: 'timeline-booking ' + r.status,
                    style: {
                      left: ((Math.max(start, instant(r.starts_at)) - start) / span) * 100 + '%',
                      width:
                        ((Math.min(end, instant(r.ends_at)) - Math.max(start, instant(r.starts_at))) / span) *
                          100 +
                        '%',
                      top: lane * 38 + 4,
                    },
                    title: label,
                  };
                  return canWrite ? (
                    <button
                      {...props}
                      key={r.id}
                      aria-label={'Reservierung bearbeiten: ' + label}
                      onClick={() => edit(r)}
                    >
                      {r.guest_name}
                    </button>
                  ) : (
                    <span {...props} key={r.id} aria-label={label}>
                      {r.guest_name}
                    </span>
                  );
                })}
                {!bookings.length && !closed.length && (
                  <span className="timeline-empty">Keine Reservierungen</span>
                )}
              </div>
            </div>
          );
        })}
        {!tables.length && <p>Keine Tische in diesem Bereich eingerichtet.</p>}
      </div>
    </div>
  );
}
