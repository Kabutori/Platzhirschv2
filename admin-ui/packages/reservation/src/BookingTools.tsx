import { useRef } from 'react';
import Calendar from './Calendar';
import type { Row } from '@platzhirsch/ui-runtime/components';
function nowIn(timezone: string) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('sv-SE', {
      timeZone: timezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(new Date())
      .map((p) => [p.type, p.value]),
  );
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}
export default function BookingTools({
  values,
  change,
  timezone,
  walkIn,
}: {
  values: Row;
  change: (patch: Row) => void;
  timezone: string;
  walkIn: boolean;
}) {
  const timePicker = useRef<HTMLDetailsElement>(null);
  const current = nowIn(timezone);
  const stamp = String(values.starts_at || current);
  const date = stamp.slice(0, 10),
    time = stamp.slice(11, 16);
  return (
    <section className="booking-tools" aria-label="Schnellauswahl für die Reservierung">
      <div className="toolbar">
        {walkIn && (
          <button type="button" onClick={() => change({ starts_at: nowIn(timezone), status: 'seated' })}>
            Walk-in · jetzt eingetroffen
          </button>
        )}
        <Calendar
          value={date}
          today={current.slice(0, 10)}
          onChange={(day) => change({ starts_at: day + 'T' + time })}
        />
        <span>
          {new Date(date + 'T12:00:00Z').toLocaleDateString('de-DE', {
            dateStyle: 'medium',
            timeZone: 'UTC',
          })}
        </span>
        <details
          ref={timePicker}
          className="booking-time-picker"
          onKeyDown={(e) => {
            if (e.key === 'Escape' && timePicker.current?.open) {
              e.stopPropagation();
              timePicker.current.open = false;
              timePicker.current.querySelector('summary')?.focus();
            }
          }}
        >
          <summary>Uhrzeit wählen · {time}</summary>
          <div className="booking-time-grid" aria-label="Uhrzeiten im Viertelstundentakt">
            {Array.from(
              { length: 96 },
              (_, i) =>
                String(Math.floor(i / 4)).padStart(2, '0') + ':' + String((i % 4) * 15).padStart(2, '0'),
            ).map((slot) => (
              <button
                type="button"
                key={slot}
                aria-pressed={time === slot}
                onClick={() => {
                  change({ starts_at: date + 'T' + slot });
                  if (timePicker.current) {
                    timePicker.current.open = false;
                    timePicker.current.querySelector('summary')?.focus();
                  }
                }}
              >
                {slot}
              </button>
            ))}
          </div>
        </details>
      </div>
      <p className="muted">
        Zeiten in {timezone}. Die Auswahl zeigt keine Verfügbarkeit; Öffnungszeiten und Tischkonflikte werden
        beim Speichern geprüft. Beginn und Dauer bleiben unten frei bearbeitbar.
      </p>
    </section>
  );
}
