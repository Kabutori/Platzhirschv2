import Timeline from './Timeline';
import { shiftDate } from './Week';
import { useRef, useState, useEffect, type PointerEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Armchair, DoorOpen, TreePine, Wine, PartyPopper } from 'lucide-react';
import { api } from '@platzhirsch/ui-runtime/api';
import { useData, ErrorBox, Loading, clock, type Row } from '@platzhirsch/ui-runtime/components';
import './table-plan.css';
export default function TablePlan({
  date,
  tables,
  reservations,
  timezone,
  tenant,
  canConfigure,
  canWrite,
  edit,
  create,
}: {
  date: string;
  tables: Row[];
  reservations: Row[];
  timezone: string;
  tenant?: string;
  canConfigure: boolean;
  canWrite: boolean;
  edit: (r: Row) => void;
  create: (table: Row) => void;
}) {
  const rooms = useData('v1/restaurant/rooms', tenant),
    qc = useQueryClient();
  const closures = useData('v1/restaurant/room-closures', tenant);
  const previous = useData('v1/restaurant/reservations?date=' + shiftDate(date, -1), tenant);
  const [view, setView] = useState<'tiles' | 'timeline'>('tiles');
  const [room, setRoom] = useState('all'),
    [time, setTime] = useState('18:00'),
    [layout, setLayout] = useState(false),
    [selected, setSelected] = useState<number | null>(null),
    [error, setError] = useState<unknown>(),
    [busy, setBusy] = useState(false),
    [position, setPosition] = useState<{ id: number; x: number; y: number } | null>(null);
  const [columns, setColumns] = useState(() => (window.innerWidth < 600 ? 2 : 4));
  useEffect(() => {
    const m = matchMedia('(max-width:600px)');
    const change = () => setColumns(m.matches ? 2 : 4);
    change();
    m.addEventListener('change', change);
    return () => m.removeEventListener('change', change);
  }, []);
  const stage = useRef<HTMLDivElement>(null),
    drag = useRef<{
      id: number;
      px: number;
      py: number;
      x: number;
      y: number;
      w: number;
      h: number;
      moved: boolean;
    } | null>(null);
  const list = tables.filter((t) => room === 'all' || String(t.room_id) === room);
  const selectedTable = list.find((t) => t.id === selected);
  const allReservations = [...(previous.data || []), ...reservations];
  const current = allReservations.filter((r) => !['cancelled', 'no_show', 'completed'].includes(r.status));
  function stamp(utc: string) {
    return new Intl.DateTimeFormat('sv-SE', {
      timeZone: timezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .format(new Date(utc.replace(' ', 'T') + 'Z'))
      .replace(' ', 'T');
  }
  function bookings(id: number) {
    return current.filter(
      (r) =>
        (r.table_id === id || (r.additional_table_ids || []).includes(id)) &&
        stamp(r.starts_at) <= date + 'T' + time &&
        stamp(r.ends_at) > date + 'T' + time,
    );
  }
  function blocked(table: Row) {
    return (closures.data || []).find(
      (c: Row) =>
        String(c.room_id) === String(table.room_id) &&
        stamp(c.starts_at) <= date + 'T' + time &&
        stamp(c.ends_at) > date + 'T' + time,
    );
  }
  async function save(table: Row, x: number, y: number) {
    setBusy(true);
    setError(undefined);
    try {
      await api(
        'v1/restaurant/tables/' + table.id,
        'PATCH',
        {
          name: table.name,
          room_id: table.room_id,
          capacity: table.capacity,
          active: !!table.active,
          shape: table.shape || 'rectangle',
          layout_x: x,
          layout_y: y,
        },
        tenant,
      );
      await qc.invalidateQueries({ queryKey: ['v1/restaurant/tables', tenant] });
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
      setPosition(null);
    }
  }
  function start(e: PointerEvent<HTMLButtonElement>, t: Row, x: number, y: number) {
    if (!layout || !canConfigure || busy) return;
    const r = stage.current!.getBoundingClientRect(),
      b = e.currentTarget.getBoundingClientRect();
    drag.current = {
      id: t.id,
      px: e.clientX,
      py: e.clientY,
      x,
      y,
      w: Math.max(1, r.width - b.width),
      h: Math.max(1, r.height - b.height),
      moved: false,
    };
    e.currentTarget.setPointerCapture(e.pointerId);
  }
  const roomIcons: Record<string, typeof DoorOpen> = {
    room: DoorOpen,
    terrace: TreePine,
    bar: Wine,
    event: PartyPopper,
  };
  return (
    <div className="floor-plan">
      <div className="toolbar padded">
        <div className="section-tabs" role="group" aria-label="Tischansicht">
          <button aria-pressed={view === 'tiles'} onClick={() => setView('tiles')}>
            Kacheln
          </button>
          <button
            aria-pressed={view === 'timeline'}
            onClick={() => {
              setView('timeline');
              setLayout(false);
              setSelected(null);
            }}
          >
            Zeitstrahl
          </button>
        </div>
        <label>
          Raum
          <select
            aria-label="Raum"
            value={room}
            onChange={(e) => {
              setRoom(e.target.value);
              setSelected(null);
              setLayout(false);
            }}
          >
            <option value="all">Alle Räume</option>
            {rooms.data?.map((r: Row) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </select>
        </label>
        {view === 'tiles' && (
          <label>
            Belegung um ({timezone})
            <input type="time" value={time} onChange={(e) => setTime(e.target.value)} />
          </label>
        )}
        {canConfigure && view === 'tiles' && (
          <label className="floor-toggle">
            <input
              type="checkbox"
              disabled={room === 'all'}
              checked={layout}
              onChange={(e) => setLayout(e.target.checked)}
            />
            Tische positionieren {room === 'all' ? '(zuerst Raum wählen)' : ''}
          </label>
        )}
      </div>
      <ErrorBox error={error || rooms.error} />
      {rooms.isPending && <Loading />}
      {room !== 'all' &&
        rooms.data
          ?.filter((r: Row) => String(r.id) === room)
          .map((r: Row) => {
            const Icon = roomIcons[r.icon] || DoorOpen;
            return (
              <div className="padded" key={r.id}>
                <h3>
                  <Icon size={20} /> {r.name}
                </h3>
                <p>
                  {r.location}
                  {r.note ? ' · ' + r.note : ''}
                </p>
              </div>
            );
          })}
      {view === 'timeline' ? (
        previous.isPending || closures.isPending ? (
          <Loading />
        ) : previous.error || closures.error ? (
          <ErrorBox error={previous.error || closures.error} />
        ) : (
          <>
            <p className="muted padded">
              {timezone} · Ganzer Tag, einschließlich hineinreichender Buchungen vom Vortag. Stornierungen und
              nicht erschienene Gäste werden ausgeblendet.
            </p>
            <Timeline
              date={date}
              tables={list}
              reservations={[...(previous.data || []), ...reservations]}
              timezone={timezone}
              closures={closures.data || []}
              canWrite={canWrite}
              edit={edit}
            />
          </>
        )
      ) : previous.isPending || closures.isPending ? (
        <Loading />
      ) : previous.error || closures.error ? (
        <ErrorBox error={previous.error || closures.error} />
      ) : (
        <>
          <p className="muted padded">
            {layout
              ? 'Tische ziehen oder fokussieren und mit Pfeiltasten verschieben. Jede Position wird unmittelbar gespeichert.'
              : 'Tisch auswählen, um Reservierungen zu sehen. Freie Anzeige gilt für die gewählte Uhrzeit, nicht automatisch für die gesamte Buchungsdauer.'}
          </p>
          <div
            className="floor-stage"
            style={{ height: Math.max(480, Math.ceil(list.length / columns) * 130) }}
            ref={stage}
            aria-label="Grafischer Tischplan"
            aria-busy={busy}
          >
            {list.map((t, i) => {
              const x =
                  position && position.id === t.id
                    ? position.x
                    : room !== 'all' && t.layout_x != null
                      ? t.layout_x
                      : ((i % columns) * 100) / (columns - 1),
                y =
                  position && position.id === t.id
                    ? position.y
                    : room !== 'all' && t.layout_y != null
                      ? t.layout_y
                      : (Math.floor(i / columns) * 100) / Math.max(1, Math.ceil(list.length / columns) - 1);
              const booked = bookings(t.id).length > 0;
              const closure = blocked(t);
              return (
                <button
                  key={t.id}
                  className={
                    'floor-table ' +
                    (t.shape || 'rectangle') +
                    (!t.active ? ' inactive' : closure ? ' closed' : booked ? ' occupied' : ' free')
                  }
                  style={{
                    left: x + '%',
                    top: y + '%',
                    transform: `translate(-${x}%,-${y}%)`,
                    touchAction: layout ? 'none' : 'auto',
                  }}
                  aria-label={`${t.name}, ${t.capacity} Plätze, ${!t.active ? 'deaktiviert' : closure ? 'Raum gesperrt' : booked ? 'belegt' : 'frei'}`}
                  aria-pressed={selected === t.id}
                  disabled={busy}
                  onPointerDown={(e) => start(e, t, x, y)}
                  onPointerMove={(e) => {
                    const d = drag.current;
                    if (!d || d.id !== t.id) return;
                    if (Math.abs(e.clientX - d.px) + Math.abs(e.clientY - d.py) > 3) d.moved = true;
                    if (d.moved)
                      setPosition({
                        id: t.id,
                        x: Math.round(Math.max(0, Math.min(100, d.x + ((e.clientX - d.px) / d.w) * 100))),
                        y: Math.round(Math.max(0, Math.min(100, d.y + ((e.clientY - d.py) / d.h) * 100))),
                      });
                  }}
                  onPointerUp={() => {
                    const d = drag.current;
                    drag.current = null;
                    if (d?.moved && position && position.id === t.id) void save(t, position.x, position.y);
                  }}
                  onPointerCancel={() => {
                    drag.current = null;
                    setPosition(null);
                  }}
                  onClick={() => setSelected(t.id)}
                  onKeyDown={(e) => {
                    if (
                      !layout ||
                      !canConfigure ||
                      !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)
                    )
                      return;
                    e.preventDefault();
                    void save(
                      t,
                      Math.max(
                        0,
                        Math.min(100, x + (e.key === 'ArrowRight' ? 5 : e.key === 'ArrowLeft' ? -5 : 0)),
                      ),
                      Math.max(
                        0,
                        Math.min(100, y + (e.key === 'ArrowDown' ? 5 : e.key === 'ArrowUp' ? -5 : 0)),
                      ),
                    );
                  }}
                >
                  <Armchair size={20} />
                  <strong>{t.name}</strong>
                  <small>
                    {t.capacity} Plätze ·{' '}
                    {!t.active ? 'Inaktiv' : closure ? 'Raum gesperrt' : booked ? 'Belegt' : 'Frei'}
                  </small>
                </button>
              );
            })}
          </div>
          {!list.length && <p className="padded">Keine Tische in diesem Bereich eingerichtet.</p>}
        </>
      )}
      {selectedTable && (
        <section className="padded">
          {blocked(selectedTable) && <p className="notice">Raum gesperrt: {blocked(selectedTable).reason}</p>}
          <div className="toolbar">
            <h3>{selectedTable.name} · Reservierungen am gewählten Tag</h3>
            {canWrite && selectedTable.active && (
              <button className="primary" onClick={() => create(selectedTable)}>
                Reservierung anlegen
              </button>
            )}
          </div>
          {allReservations
            .filter((r) => r.table_id === selected || (r.additional_table_ids || []).includes(selected))
            .map((r) => (
              <button className="floor-booking" key={r.id} disabled={!canWrite} onClick={() => edit(r)}>
                {clock(r.starts_at, timezone)}–{clock(r.ends_at, timezone)} · {r.guest_name} · {r.party_size}{' '}
                Personen · {r.status}
              </button>
            ))}
          {!allReservations.some(
            (r) => r.table_id === selected || (r.additional_table_ids || []).includes(selected),
          ) && <p>Keine Reservierungen.</p>}
        </section>
      )}
    </div>
  );
}
