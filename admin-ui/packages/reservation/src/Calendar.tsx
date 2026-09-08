import { useEffect, useRef, useState } from 'react';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import { shiftDate } from './Week';
import './calendar.css';
const parse = (date: string) => new Date(date + 'T12:00:00Z');
export default function Calendar({
  value,
  onChange,
  today,
}: {
  value: string;
  onChange: (date: string) => void;
  today: string;
}) {
  const [open, setOpen] = useState(false);
  const [month, setMonth] = useState(value.slice(0, 7) + '-01');
  const root = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const selected = useRef<HTMLButtonElement>(null);
  function close() {
    setOpen(false);
    trigger.current?.focus();
  }
  function choose(date: string) {
    onChange(date);
    close();
  }
  useEffect(() => {
    if (!open) return;
    selected.current?.focus();
    const outside = (e: PointerEvent) => {
      if (!root.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('pointerdown', outside);
    return () => document.removeEventListener('pointerdown', outside);
  }, [open]);
  const start = shiftDate(month, -((parse(month).getUTCDay() + 6) % 7));
  function moveMonth(amount: number) {
    const d = parse(month);
    d.setUTCMonth(d.getUTCMonth() + amount);
    setMonth(d.toISOString().slice(0, 10));
  }
  return (
    <div
      className="reservation-calendar"
      ref={root}
      onKeyDown={(e) => {
        if (e.key === 'Escape' && open) {
          e.stopPropagation();
          close();
        }
      }}
      onBlur={(e) => {
        if (!e.currentTarget.contains(e.relatedTarget as Node)) setOpen(false);
      }}
    >
      <button
        type="button"
        ref={trigger}
        aria-label="Kalender öffnen"
        aria-expanded={open}
        aria-haspopup="dialog"
        onClick={() => {
          setMonth(value.slice(0, 7) + '-01');
          setOpen(!open);
        }}
      >
        <CalendarDays size={17} />
      </button>
      {open && (
        <div role="dialog" aria-label="Reservierungsdatum wählen" className="calendar-popup">
          <div className="calendar-heading">
            <button type="button" aria-label="Vorheriger Monat" onClick={() => moveMonth(-1)}>
              <ChevronLeft size={16} />
            </button>
            <strong aria-live="polite">
              {parse(month).toLocaleDateString('de-DE', { month: 'long', year: 'numeric', timeZone: 'UTC' })}
            </strong>
            <button type="button" aria-label="Nächster Monat" onClick={() => moveMonth(1)}>
              <ChevronRight size={16} />
            </button>
          </div>
          <div className="calendar-grid">
            {['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'].map((d) => (
              <small key={d}>{d}</small>
            ))}
            {Array.from({ length: 42 }, (_, i) => shiftDate(start, i)).map((d) => (
              <button
                type="button"
                key={d}
                ref={d === value ? selected : undefined}
                className={d.slice(0, 7) !== month.slice(0, 7) ? 'outside-month' : ''}
                aria-label={parse(d).toLocaleDateString('de-DE', {
                  day: 'numeric',
                  month: 'long',
                  year: 'numeric',
                  timeZone: 'UTC',
                })}
                aria-pressed={d === value}
                aria-current={d === today ? 'date' : undefined}
                onClick={() => choose(d)}
              >
                {parse(d).getUTCDate()}
              </button>
            ))}
          </div>
          <button type="button" className="calendar-today" onClick={() => choose(today)}>
            Heute auswählen
          </button>
        </div>
      )}
    </div>
  );
}
