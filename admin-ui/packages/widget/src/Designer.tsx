import Preview from './Preview';
import { useState, type CSSProperties, type FormEvent } from 'react';
import { CalendarDays, Users, Code2 } from 'lucide-react';
import { api } from '@platzhirsch/ui-runtime/api';
import { ErrorBox, type Row } from '@platzhirsch/ui-runtime/components';
import './designer.css';
export default function Designer({
  tenant,
  created,
  initial,
}: {
  tenant?: string;
  initial?: Row;
  created: (result: Row) => void;
}) {
  const [origin, setOrigin] = useState(() => {
      if (!initial) return '';
      const origins = typeof initial.origins === 'string' ? JSON.parse(initial.origins) : initial.origins;
      return origins?.[0] || '';
    }),
    [months, setMonths] = useState(12),
    [duration, setDuration] = useState(initial?.duration_minutes ?? 90),
    [accent, setAccent] = useState(initial?.accent ?? '#cc794e'),
    [language, setLanguage] = useState(initial?.language ?? 'de'),
    [position, setPosition] = useState(initial?.position ?? 'inline'),
    [max, setMax] = useState(initial?.max_party_size ?? 12),
    [brand, setBrand] = useState(initial?.show_brand == null ? true : !!initial.show_brand),
    [busy, setBusy] = useState(false),
    [error, setError] = useState<unknown>();
  const en = language === 'en';
  const rgb = accent
    .slice(1)
    .match(/../g)
    .map((v: string) => {
      const n = parseInt(v, 16) / 255;
      return n <= 0.04045 ? n / 12.92 : ((n + 0.055) / 1.055) ** 2.4;
    });
  const onAccent = rgb[0] * 0.2126 + rgb[1] * 0.7152 + rgb[2] * 0.0722 > 0.179 ? '#000000' : '#ffffff';
  async function submit(e: FormEvent) {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    setError(undefined);
    try {
      const r = await api(
        'v1/restaurant/widget' + (initial?.id ? '/' + initial.id : ''),
        initial?.id ? 'PATCH' : 'POST',
        {
          origins: [origin],
          months,
          duration_minutes: duration,
          accent,
          language,
          position,
          max_party_size: max,
          show_brand: brand,
        },
        tenant,
      );
      created(r);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="widget-designer">
      <form onSubmit={submit}>
        <h3>Darstellung & Buchung</h3>
        <label>
          Website-Ursprung
          <input
            type="url"
            required={!initial}
            disabled={!!initial}
            value={origin}
            placeholder="https://mein-restaurant.de"
            onChange={(e) => setOrigin(e.target.value)}
          />
          <small>
            {initial
              ? 'Website-Freigabe und Ablaufdatum bleiben erhalten.'
              : 'Nur Ursprung, ohne Unterseite.'}
          </small>
        </label>
        <div className="designer-fields">
          <label>
            Position
            <select aria-label="Position" value={position} onChange={(e) => setPosition(e.target.value)}>
              <option value="inline">Im Seiteninhalt</option>
              <option value="bottom-right">Unten rechts</option>
              <option value="bottom-left">Unten links</option>
              <option value="top-right">Oben rechts</option>
              <option value="top-left">Oben links</option>
            </select>
          </label>
          <label>
            Sprache
            <select aria-label="Sprache" value={language} onChange={(e) => setLanguage(e.target.value)}>
              <option value="de">Deutsch</option>
              <option value="en">English</option>
            </select>
          </label>
          <label>
            Akzentfarbe
            <input type="color" value={accent} onChange={(e) => setAccent(e.target.value)} />
          </label>
          <label>
            Max. Personenzahl
            <input
              type="number"
              min="1"
              max="50"
              required
              value={max}
              onChange={(e) => setMax(Number(e.target.value))}
            />
          </label>
          <label>
            Reservierungsdauer
            <select value={duration} onChange={(e) => setDuration(Number(e.target.value))}>
              {[30, 45, 60, 75, 90, 120, 150, 180, 240].map((n) => (
                <option key={n} value={n}>
                  {n} Minuten
                </option>
              ))}
            </select>
          </label>
          {!initial ? (
            <label>
              Gültigkeit (Monate)
              <input
                type="number"
                min="1"
                max="12"
                disabled={!!initial}
                required
                value={months}
                onChange={(e) => setMonths(Number(e.target.value))}
              />
            </label>
          ) : (
            <p className="muted">Gültig bis: {initial.expires_at}</p>
          )}
        </div>
        <label className="designer-brand">
          <input type="checkbox" checked={brand} onChange={(e) => setBrand(e.target.checked)} />
          Platzhirsch-Logo anzeigen
        </label>
        <ErrorBox error={error} />
        <button className="primary" disabled={busy}>
          <Code2 size={16} />
          {busy ? 'Wird erstellt …' : initial ? 'Einstellungen speichern' : 'Buchungszugang erstellen'}
        </button>
      </form>
      <section>
        <h3>Live-Vorschau</h3>
        <p className="muted">Darstellungsvorschau mit Beispieldaten; es wird keine Reservierung versendet.</p>
        <div
          className={'designer-preview ' + position}
          style={{ '--designer-accent': accent, '--designer-on-accent': onAccent } as CSSProperties}
        >
          <Preview
            key={language + ':' + max + ':' + duration}
            en={en}
            max={max}
            duration={duration}
            brand={brand}
          />
          {position !== 'inline' && (
            <span className="preview-launch">{en ? 'Reserve a table' : 'Tisch reservieren'}</span>
          )}
        </div>
      </section>
    </div>
  );
}
