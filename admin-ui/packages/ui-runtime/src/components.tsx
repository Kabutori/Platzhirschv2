import { useState, useRef, useEffect, type ReactNode, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { AlertCircle, RefreshCw, CalendarDays, X, ChevronRight } from 'lucide-react';
import { api } from './api';
import { usePreferences } from './preferences';
export type Row = Record<string, any>;
export type Field = {
  key: string;
  label: string;
  type?: string;
  required?: boolean;
  options?: { value: string | number; label: string }[];
  multiple?: boolean;
  min?: number;
  max?: number;
  default?: any;
  help?: string;
};
export const today = () =>
  new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
export const labels: Record<string, string> = {
  active: 'Aktiv',
  moving: 'Wird umgezogen',
  upgrading: 'Modulmigration läuft',
  blocked: 'Gesperrt',
  provisioning: 'Wird eingerichtet',
  failed: 'Einrichtung fehlgeschlagen',
  confirmed: 'Bestätigt',
  seated: 'Eingetroffen',
  completed: 'Abgeschlossen',
  cancelled: 'Storniert',
  no_show: 'Nicht erschienen',
  open: 'Offen',
  in_progress: 'In Bearbeitung',
  closed: 'Geschlossen',
  low: 'Niedrig',
  normal: 'Normal',
  high: 'Hoch',
  system_admin: 'System-Administrator',
  platform_staff: 'Plattform-Mitarbeiter',
  restaurant_admin: 'Restaurant-Administrator',
  staff: 'Mitarbeiter',
};
export const pick = (values: string[]) => values.map((value) => ({ value, label: labels[value] || value }));
export const clock = (utc: string, tz = 'Europe/Berlin') =>
  new Intl.DateTimeFormat('de-DE', { hour: '2-digit', minute: '2-digit', timeZone: tz }).format(
    new Date(utc.replace(' ', 'T') + 'Z'),
  );
export function Badge({ value }: { value: string | boolean }) {
  const text = typeof value === 'boolean' ? (value ? 'Aktiv' : 'Inaktiv') : value;
  return <span className={'badge ' + text}>{labels[text] || text}</span>;
}
export function ErrorBox({ error }: { error: unknown }) {
  return error ? (
    <div className="alert" role="alert">
      <AlertCircle size={17} />
      {error instanceof Error ? error.message : String(error)}
    </div>
  ) : null;
}
export function Loading() {
  return (
    <div className="empty" role="status">
      <RefreshCw className="spin" size={20} />
      Wird geladen …
    </div>
  );
}
export function Empty({ children }: { children: ReactNode }) {
  return (
    <div className="empty">
      <CalendarDays size={28} />
      <p>{children}</p>
    </div>
  );
}
export function useData(path: string, tenant?: string) {
  return useQuery({
    queryKey: [path, tenant],
    queryFn: ({ signal }) => api(path, 'GET', undefined, tenant, signal),
  });
}
export function Modal({ title, children, close }: { title: string; children: ReactNode; close: () => void }) {
  const { value: preferences } = usePreferences();
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    ref.current?.showModal();
    return () => ref.current?.close();
  }, []);
  return (
    <dialog
      ref={ref}
      onCancel={close}
      aria-labelledby="dialog-title"
      onClick={(e) => {
        if (!preferences.backdropClose || e.target !== e.currentTarget) return;
        const r = e.currentTarget.getBoundingClientRect();
        if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) close();
      }}
    >
      <header>
        <h2 id="dialog-title">{title}</h2>
        <button className="icon" onClick={close} aria-label="Schließen">
          <X size={20} />
        </button>
      </header>
      {children}
    </dialog>
  );
}
export function Form({
  fields,
  initial = {},
  onSave,
  label = 'Speichern',
}: {
  fields: Field[];
  initial?: Row;
  onSave: (data: Row) => Promise<void>;
  label?: string;
}) {
  const [values, setValues] = useState<Row>(() =>
    Object.fromEntries(
      fields.map((f) => [
        f.key,
        initial[f.key] ?? f.default ?? (f.multiple ? [] : f.type === 'checkbox' ? false : ''),
      ]),
    ),
  );
  const [error, setError] = useState<unknown>();
  const [busy, setBusy] = useState(false);
  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(undefined);
    setBusy(true);
    try {
      await onSave(values);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }
  return (
    <form onSubmit={submit}>
      <ErrorBox error={error} />
      <div className="fields">
        {fields.map((f) => (
          <label className={f.type === 'checkbox' ? 'checkfield' : ''} key={f.key}>
            <span>
              {f.label}
              {f.required ? ' *' : ''}
            </span>
            {f.options ? (
              <select
                multiple={f.multiple}
                required={f.required}
                value={values[f.key]}
                onChange={(e) =>
                  setValues({
                    ...values,
                    [f.key]: f.multiple
                      ? Array.from(e.target.selectedOptions, (o) => o.value)
                      : e.target.value,
                  })
                }
              >
                {!f.multiple && <option value="">Bitte auswählen</option>}
                {f.options.map((o) => (
                  <option value={o.value} key={o.value}>
                    {o.label}
                  </option>
                ))}
              </select>
            ) : f.type === 'textarea' ? (
              <textarea
                rows={4}
                value={values[f.key]}
                required={f.required}
                onChange={(e) => setValues({ ...values, [f.key]: e.target.value })}
              />
            ) : (
              <input
                type={f.type || 'text'}
                value={f.type === 'checkbox' ? undefined : values[f.key]}
                checked={f.type === 'checkbox' ? Boolean(values[f.key]) : undefined}
                required={f.required}
                min={f.min}
                max={f.max}
                minLength={f.type === 'password' ? 12 : undefined}
                autoComplete={f.type === 'password' ? 'new-password' : undefined}
                onChange={(e) =>
                  setValues({
                    ...values,
                    [f.key]:
                      f.type === 'checkbox'
                        ? e.target.checked
                        : f.type === 'number'
                          ? Number(e.target.value)
                          : e.target.value,
                  })
                }
              />
            )}{' '}
            {f.help && <small>{f.help}</small>}
          </label>
        ))}
      </div>
      <footer className="form-footer">
        <button className="primary" disabled={busy}>
          {busy ? 'Wird gespeichert …' : label}
          <ChevronRight size={16} />
        </button>
      </footer>
    </form>
  );
}
export function DataTable({
  rows,
  columns,
  actions,
}: {
  rows: Row[];
  columns: { key: string; label: string; render?: (row: Row) => ReactNode }[];
  actions?: (row: Row) => ReactNode;
}) {
  if (!rows.length) return <Empty>Noch keine Einträge vorhanden.</Empty>;
  return (
    <div className="table-scroll">
      <table>
        <thead>
          <tr>
            {columns.map((c) => (
              <th key={c.key}>{c.label}</th>
            ))}
            {actions && (
              <th>
                <span className="sr-only">Aktionen</span>
              </th>
            )}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.id ?? row.code}>
              {columns.map((c) => (
                <td key={c.key}>{c.render ? c.render(row) : (row[c.key] ?? '—')}</td>
              ))}
              {actions && <td className="actions">{actions(row)}</td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
