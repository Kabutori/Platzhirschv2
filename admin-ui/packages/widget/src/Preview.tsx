import { useState } from 'react';
export default function Preview({
  en,
  max,
  duration,
  brand,
}: {
  en: boolean;
  max: number;
  duration: number;
  brand: boolean;
}) {
  const [step, setStep] = useState(0),
    [date, setDate] = useState(''),
    [time, setTime] = useState('19:00'),
    [party, setParty] = useState(Math.min(2,max)),
    [table, setTable] = useState('Fenster'),
    [name, setName] = useState(''),
    [email, setEmail] = useState('');
  const text = (de: string, english: string) => (en ? english : de);
  return (
    <div className="preview-surface" aria-label={text('Beispielbuchung', 'Example booking')}>
      {brand && <span className="preview-brand">P · Platzhirsch</span>}
      <h3>{text('Tisch reservieren', 'Reserve a table')}</h3>
      <p>
        {text('Bis zu', 'Up to')} {max} {text('Personen', 'guests')}
      </p>
      <p>
        {duration} {text('Minuten', 'minutes')} · {text('Beispieldaten', 'Sample data')}
      </p>
      <ol className="preview-steps" aria-label={text('Buchungsschritte', 'Booking steps')}>
        {[
          text('Termin', 'Date'),
          text('Tisch', 'Table'),
          text('Kontakt', 'Contact'),
          text('Bestätigung', 'Confirmation'),
        ].map((s, i) => (
          <li key={i} aria-current={step === i ? 'step' : undefined}>
            {s}
          </li>
        ))}
      </ol>
      <form
        onSubmit={(e) => {
          e.preventDefault();
          setStep((s) => Math.min(3, s + 1));
        }}
      >
        {step === 0 && (
          <>
            <label>
              {text('Beispieldatum', 'Example date')}
              <input type="date" required value={date} onChange={(e) => setDate(e.target.value)} />
            </label>
            <label>
              {text('Beispieluhrzeit', 'Example time')}
              <input type="time" required value={time} onChange={(e) => setTime(e.target.value)} />
            </label>
            <label>
              {text('Beispielpersonenzahl', 'Example party size')}
              <input
                type="number"
                required
                min={1}
                max={max}
                value={party}
                onChange={(e) => setParty(Number(e.target.value))}
              />
            </label>
          </>
        )}
        {step === 1 && (
          <label>
            {text('Beispieltisch wählen', 'Choose sample table')}
            <select value={table} onChange={(e) => setTable(e.target.value)}>
              <option value="Fenster">{text('Fenster', 'Window')}</option>
              <option value="Terrasse">{text('Terrasse', 'Terrace')}</option>
            </select>
          </label>
        )}
        {step === 2 && (
          <>
            <label>
              {text('Beispielname', 'Example name')}
              <input
                required
                maxLength={120}
                autoComplete="off"
                value={name}
                onChange={(e) => setName(e.target.value)}
              />
            </label>
            <label>
              {text('Beispiel-E-Mail', 'Example email')}
              <input
                type="email"
                required
                autoComplete="off"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            </label>
            <p>
              {date} · {time} · {party} {text('Personen', 'guests')} · {table}
            </p>
          </>
        )}
        {step === 3 && (
          <div role="status">
            <h4>{text('Beispielbuchung bestätigt', 'Example booking confirmed')}</h4>
            <p>
              {name} · {date} · {time} · {party} · {table}
            </p>
            <p>
              {text(
                'Es wurde nichts gespeichert und keine Nachricht versendet.',
                'Nothing was saved and no message was sent.',
              )}
            </p>
          </div>
        )}
        <div className="actions">
          {step > 0 && (
            <button type="button" onClick={() => setStep(step - 1)}>
              {text('Zurück', 'Back')}
            </button>
          )}
          {step < 3 ? (
            <button key="advance" className="preview-action" type="submit">
              {step === 0
                ? text('Verfügbare Tische anzeigen', 'Show available tables')
                : step === 1
                  ? text('Weiter zu Kontaktdaten', 'Continue to contact details')
                  : text('Beispielbuchung bestätigen', 'Confirm example booking')}
            </button>
          ) : (
            <button
              key="restart"
              type="button"
              onClick={(event) => {
                event.preventDefault();
                setStep(0);
                setName('');
                setEmail('');
              }}
            >
              {text('Neue Beispielbuchung', 'New example booking')}
            </button>
          )}
        </div>
      </form>
    </div>
  );
}
