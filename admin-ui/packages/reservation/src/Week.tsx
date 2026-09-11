import { Badge, ErrorBox, Loading, clock, useData, type Row } from '@platzhirsch/ui-runtime/components';
import './week.css';
export function shiftDate(date: string, amount: number) {
  const value = new Date(date + 'T12:00:00Z');
  value.setUTCDate(value.getUTCDate() + amount);
  return value.toISOString().slice(0, 10);
}
export function matchesReservation(row: Row, search: string, status: string) {
  return (
    (!status || row.status === status) &&
    [row.guest_name, row.table_name, row.phone, row.email, row.notes].some((value) =>
      String(value || '')
        .toLocaleLowerCase('de-DE')
        .includes(search.trim().toLocaleLowerCase('de-DE')),
    )
  );
}
function Day({
  date,
  selected,
  tenant,
  search,
  status,
  timezone,
  canWrite,
  edit,
  select,
}: {
  date: string;
  selected: boolean;
  tenant?: string;
  search: string;
  status: string;
  timezone: string;
  canWrite: boolean;
  edit: (row: Row) => void;
  select: (date: string) => void;
}) {
  const q = useData('v1/restaurant/reservations?date=' + date, tenant);
  const rows: Row[] = (q.data || []).filter((row: Row) => matchesReservation(row, search, status));
  const label = new Intl.DateTimeFormat('de-DE', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
  }).format(new Date(date + 'T12:00:00Z'));
  return (
    <section className={'reservation-day' + (selected ? ' selected' : '')} aria-label={label}>
      <button
        type="button"
        className="reservation-day-heading"
        onClick={() => select(date)}
        aria-label={'Tagesliste öffnen: ' + label}
      >
        <span>
          {new Intl.DateTimeFormat('de-DE', { weekday: 'short', timeZone: 'UTC' }).format(
            new Date(date + 'T12:00:00Z'),
          )}
        </span>
        <strong>
          {date.slice(8, 10)}.{date.slice(5, 7)}.
        </strong>
      </button>
      {q.isPending ? (
        <Loading />
      ) : q.error ? (
        <ErrorBox error={q.error} />
      ) : (
        <>
          <small className="muted">
            {rows.length} Buchungen · {rows.reduce((sum, row) => sum + Number(row.party_size), 0)} Personen
          </small>
          {rows.map((row) => {
            const content = (
              <>
                <code>
                  {clock(row.starts_at, timezone)}–{clock(row.ends_at, timezone)}
                </code>
                <strong>{row.guest_name}</strong>
                <small>
                  {row.party_size} Pers. · {row.table_name || 'Ohne Tisch'}
                </small>
                <Badge value={row.status} />
                {row.notes && <small className="reservation-note">{row.notes}</small>}
              </>
            );
            return canWrite ? (
              <button
                key={row.id}
                type="button"
                className="reservation-week-card"
                aria-label={'Reservierung bearbeiten: ' + row.guest_name + ', ' + label}
                onClick={() => edit(row)}
              >
                {content}
              </button>
            ) : (
              <article key={row.id} className="reservation-week-card">
                {content}
              </article>
            );
          })}
          {!rows.length && (
            <p className="muted">
              {search || status ? 'Keine passenden Reservierungen.' : 'Keine Reservierungen.'}
            </p>
          )}
        </>
      )}
    </section>
  );
}
export default function Week(props: {
  date: string;
  tenant?: string;
  search: string;
  status: string;
  timezone: string;
  canWrite: boolean;
  edit: (row: Row) => void;
  select: (date: string) => void;
}) {
  const weekday = new Date(props.date + 'T12:00:00Z').getUTCDay();
  const monday = shiftDate(props.date, -((weekday + 6) % 7));
  return (
    <div className="reservation-week" aria-label="Reservierungen der Woche">
      {Array.from({ length: 7 }, (_, i) => shiftDate(monday, i)).map((date) => (
        <Day {...props} key={date} date={date} selected={date === props.date} />
      ))}
    </div>
  );
}
