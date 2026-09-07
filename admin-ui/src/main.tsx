import React, { useEffect, useRef, useState, FormEvent, ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  LayoutDashboard,
  Building2,
  Users,
  ShieldCheck,
  ScrollText,
  Activity,
  CalendarDays,
  Armchair,
  DoorOpen,
  Clock,
  Code2,
  MessageSquare,
  Settings,
  LogOut,
  ChevronRight,
  Plus,
  ArrowLeftRight,
  Search,
  RefreshCw,
  ArrowLeft,
  Download,
  Check,
  AlertCircle,
  Menu,
  X,
} from 'lucide-react';
import '@fontsource/work-sans/400.css';
import '@fontsource/work-sans/500.css';
import '@fontsource/work-sans/600.css';
import '@fontsource/work-sans/700.css';
import '@fontsource/newsreader/400.css';
import '@fontsource/jetbrains-mono/400.css';
import { api, ApiError } from './api';
import './style.css';

type Row = Record<string, any>;
type Field = {
  key: string;
  label: string;
  type?: string;
  required?: boolean;
  options?: { value: string | number; label: string }[];
  min?: number;
  max?: number;
  default?: any;
  help?: string;
};
const client = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (n, e) => !(e instanceof ApiError && e.status < 500) && n < 1,
      staleTime: 10000,
      refetchOnWindowFocus: false,
    },
  },
});
const today = () =>
  new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
const labels: Record<string, string> = {
  active: 'Aktiv',
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
  restaurant_admin: 'Restaurant-Administrator',
  staff: 'Mitarbeiter',
};
const pick = (values: string[]) => values.map((value) => ({ value, label: labels[value] || value }));
const clock = (utc: string, tz = 'Europe/Berlin') =>
  new Intl.DateTimeFormat('de-DE', { hour: '2-digit', minute: '2-digit', timeZone: tz }).format(
    new Date(utc.replace(' ', 'T') + 'Z'),
  );
function Badge({ value }: { value: string | boolean }) {
  const text = typeof value === 'boolean' ? (value ? 'Aktiv' : 'Inaktiv') : value;
  return <span className={'badge ' + text}>{labels[text] || text}</span>;
}
function ErrorBox({ error }: { error: unknown }) {
  return error ? (
    <div className="alert" role="alert">
      <AlertCircle size={17} />
      {error instanceof Error ? error.message : String(error)}
    </div>
  ) : null;
}
function Loading() {
  return (
    <div className="empty" role="status">
      <RefreshCw className="spin" size={20} />
      Wird geladen …
    </div>
  );
}
function Empty({ children }: { children: ReactNode }) {
  return (
    <div className="empty">
      <CalendarDays size={28} />
      <p>{children}</p>
    </div>
  );
}
function useData(path: string, tenant?: string) {
  return useQuery({
    queryKey: [path, tenant],
    queryFn: ({ signal }) => api(path, 'GET', undefined, tenant, signal),
  });
}
function Modal({ title, children, close }: { title: string; children: ReactNode; close: () => void }) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    ref.current?.showModal();
    return () => ref.current?.close();
  }, []);
  return (
    <dialog ref={ref} onCancel={close} aria-labelledby="dialog-title">
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
function Form({
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
      fields.map((f) => [f.key, initial[f.key] ?? f.default ?? (f.type === 'checkbox' ? false : '')]),
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
                required={f.required}
                value={values[f.key]}
                onChange={(e) => setValues({ ...values, [f.key]: e.target.value })}
              >
                <option value="">Bitte auswählen</option>
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
function Login({ onLogin, setup = false }: { onLogin: () => void; setup?: boolean }) {
  const [mfa, setMfa] = useState(false);
  const [forgot, setForgot] = useState(false);
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(undefined);
    try {
      if (forgot) {
        const r = await api('v1/admin/auth/forgot-password', 'POST', { email });
        setNotice(r.message);
      } else {
        const r = await api('v1/admin/auth/login', 'POST', { email, password, mfa_code: code || undefined });
        if (r.mfa_required) setMfa(true);
        else onLogin();
      }
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="auth-page">
      <div className="auth-wrap">
        <div className="brand large">
          <b>P</b>
          <div>
            <h1>Platzhirsch Plattform</h1>
            <small>{setup ? 'ERSTEINRICHTUNG' : 'VERWALTUNG & RESTAURANT'}</small>
          </div>
        </div>
        <section className="auth-card">
          {setup ? (
            <>
              <h2>Willkommen bei Platzhirsch</h2>
              <p className="muted">
                Lege deinen ersten Administrator an. Den Einrichtungsschlüssel zeigt dir die
                Windows-Installation.
              </p>
              <Form
                fields={[
                  { key: 'setup_token', label: 'Einrichtungsschlüssel', required: true },
                  { key: 'name', label: 'Dein Name', required: true },
                  { key: 'email', label: 'E-Mail', type: 'email', required: true },
                  { key: 'password', label: 'Passwort', type: 'password', required: true },
                  {
                    key: 'password_confirmation',
                    label: 'Passwort wiederholen',
                    type: 'password',
                    required: true,
                  },
                ]}
                label="Einrichtung abschließen"
                onSave={async (data) => {
                  await api('bootstrap/first-admin', 'POST', data);
                  onLogin();
                }}
              />
            </>
          ) : (
            <form onSubmit={submit}>
              <h2>{forgot ? 'Passwort zurücksetzen' : 'Anmelden'}</h2>
              <ErrorBox error={error} />
              {notice && <div className="notice">{notice}</div>}
              <div className="fields">
                <label>
                  E-Mail
                  <input
                    type="email"
                    required
                    autoComplete="username"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                  />
                </label>
                {!forgot && (
                  <label>
                    Passwort
                    <input
                      type="password"
                      required
                      autoComplete="current-password"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                    />
                  </label>
                )}
                {mfa && !forgot && (
                  <label>
                    Bestätigungscode
                    <input
                      inputMode="numeric"
                      pattern="[0-9]{6}"
                      autoComplete="one-time-code"
                      required
                      value={code}
                      onChange={(e) => setCode(e.target.value)}
                    />
                    <small>Code aus deiner Authenticator-App</small>
                  </label>
                )}
              </div>
              <button className="primary full" disabled={busy}>
                {busy ? 'Bitte warten …' : forgot ? 'Link anfordern' : mfa ? 'Bestätigen' : 'Anmelden'}
                <ChevronRight size={16} />
              </button>
              <button
                className="text"
                type="button"
                onClick={() => {
                  setForgot(!forgot);
                  setNotice('');
                  setError(undefined);
                }}
              >
                {forgot ? 'Zurück zur Anmeldung' : 'Passwort vergessen?'}
              </button>
            </form>
          )}
        </section>
        <p className="security">
          <span />
          Zugriff wird protokolliert · Geschützte Verbindung empfohlen
        </p>
      </div>
    </div>
  );
}
function ResetPassword() {
  const p = new URLSearchParams(location.hash.split('?')[1]);
  const [done, setDone] = useState(false);
  return (
    <div className="auth-page">
      <section className="auth-card">
        <h1>Neues Passwort</h1>
        {done ? (
          <>
            <p>Dein Passwort wurde geändert.</p>
            <a href="#">Zur Anmeldung</a>
          </>
        ) : (
          <Form
            fields={[
              { key: 'password', label: 'Neues Passwort', type: 'password', required: true },
              {
                key: 'password_confirmation',
                label: 'Passwort wiederholen',
                type: 'password',
                required: true,
              },
            ]}
            onSave={async (data) => {
              await api('v1/admin/auth/reset-password', 'POST', {
                ...data,
                email: p.get('email'),
                token: p.get('token'),
              });
              history.replaceState(null, '', location.pathname + '#reset');
              setDone(true);
            }}
          />
        )}
      </section>
    </div>
  );
}
const systemNav = [
  ['dashboard', 'Übersicht', LayoutDashboard],
  ['tenants', 'Mandanten', Building2],
  ['users', 'Benutzer', Users],
  ['roles', 'Rollen & Rechte', ShieldCheck],
  ['audit-log', 'Audit Log', ScrollText],
  ['health', 'System', Activity],
  ['support', 'Support', MessageSquare],
  ['account', 'Mein Konto', Settings],
] as const;
const restaurantNav = [
  ['overview', 'Auswertung', LayoutDashboard],
  ['reservations', 'Reservierungen', CalendarDays],
  ['table-plan', 'Tischplan', Armchair],
  ['tables', 'Tische', Armchair],
  ['rooms', 'Räume', DoorOpen],
  ['hours', 'Öffnungszeiten', Clock],
  ['special-days', 'Sondertage', CalendarDays],
  ['widget', 'Widget', Code2],
  ['team', 'Team & Rollen', Users],
  ['profile', 'Restaurant-Profil', Building2],
  ['support', 'Support', MessageSquare],
  ['account', 'Mein Konto', Settings],
] as const;
function App() {
  const [hash, setHash] = useState(location.hash);
  useEffect(() => {
    const update = () => setHash(location.hash);
    window.addEventListener('hashchange', update);
    return () => window.removeEventListener('hashchange', update);
  }, []);
  if (hash.startsWith('#booking')) return <Booking />;
  if (hash.startsWith('#reset')) return <ResetPassword />;
  return <Authenticated />;
}
function Authenticated() {
  const q = useQueryClient();
  const me = useData('v1/admin/auth/me');
  const status = useQuery({
    queryKey: ['bootstrap-status'],
    queryFn: () => api('bootstrap-status'),
    enabled: me.error instanceof ApiError && me.error.status === 401,
  });
  if (me.isPending || (me.error instanceof ApiError && me.error.status === 401 && status.isPending))
    return <Loading />;
  if (me.error instanceof ApiError && me.error.status === 401) {
    if (status.error) return <ErrorBox error={status.error} />;
    return (
      <Login
        setup={status.data?.bootstrapped === false}
        onLogin={() => {
          void q.resetQueries();
        }}
      />
    );
  }
  if (me.error)
    return (
      <div className="auth-page">
        <section className="auth-card">
          <ErrorBox error={me.error} />
          <button onClick={() => me.refetch()}>Erneut versuchen</button>
        </section>
      </div>
    );
  return <Shell user={me.data} />;
}
function Shell({ user }: { user: Row }) {
  const [scope, setScope] = useState(user.role === 'system_admin' ? 'system' : 'restaurant');
  const [page, setPage] = useState(user.role === 'system_admin' ? 'dashboard' : 'overview');
  const [tenant, setTenant] = useState('');
  const [mobile, setMobile] = useState(false);
  const tenants = useQuery({
    queryKey: ['tenant-picker'],
    queryFn: () => api('v1/admin/tenants'),
    enabled: user.role === 'system_admin',
  });
  const q = useQueryClient();
  const [error, setError] = useState<unknown>();
  const nav =
    scope === 'system'
      ? systemNav
      : restaurantNav.filter(
          ([key]) =>
            user.role !== 'staff' ||
            ['overview', 'reservations', 'table-plan', 'support', 'account'].includes(key),
        );
  const title = nav.find(([key]) => key === page)?.[1] || 'Platzhirsch';
  function changeScope() {
    const next = scope === 'system' ? 'restaurant' : 'system';
    setScope(next);
    setPage(next === 'system' ? 'dashboard' : 'overview');
  }
  return (
    <div className="app">
      <aside className={mobile ? 'sidebar open' : 'sidebar'}>
        <div className="brand">
          <b>P</b>
          <div>
            <strong>Platzhirsch</strong>
            <small>{scope === 'system' ? 'Plattform-Verwaltung' : 'Restaurant-Verwaltung'}</small>
          </div>
        </div>
        {user.role === 'system_admin' && (
          <button className="scope-switch" onClick={changeScope}>
            <ArrowLeftRight size={15} />
            Bereich wechseln
            <ChevronRight size={14} />
          </button>
        )}
        <div className="nav-label">{scope === 'system' ? 'PLATTFORM-VERWALTUNG' : 'DEIN RESTAURANT'}</div>
        <nav aria-label="Hauptnavigation">
          {nav.map(([key, label, Icon]) => (
            <button
              key={key}
              className={key === page ? 'active' : ''}
              onClick={() => {
                setPage(key);
                setMobile(false);
              }}
              aria-current={key === page ? 'page' : undefined}
            >
              <Icon size={17} />
              {label}
              {key === page && <ChevronRight size={14} />}
            </button>
          ))}
        </nav>
        <div className="user">
          <div className="avatar">{user.name?.slice(0, 2).toUpperCase()}</div>
          <div>
            <strong>{user.name}</strong>
            <small>{labels[user.role]}</small>
          </div>
          <button
            className="icon"
            title="Abmelden"
            onClick={async () => {
              try {
                await api('v1/admin/auth/logout', 'POST');
                await q.resetQueries();
              } catch (e) {
                setError(e);
              }
            }}
          >
            <LogOut size={17} />
          </button>
        </div>
      </aside>
      <div className="workspace">
        <header className="topbar">
          <button className="mobile icon" aria-label="Menü" onClick={() => setMobile(!mobile)}>
            <Menu />
          </button>
          <div>
            <div className="breadcrumb">
              {scope === 'system' ? 'PLATTFORM' : 'RESTAURANT'}
              <ChevronRight size={12} />
              {title}
            </div>
            <h1>{title}</h1>
          </div>
          <div className="top-right">
            {scope === 'restaurant' && user.role === 'system_admin' ? (
              <select
                aria-label="Restaurant"
                value={tenant}
                onChange={(e) => {
                  setTenant(e.target.value);
                }}
              >
                <option value="">Restaurant auswählen</option>
                {tenants.data?.data
                  ?.filter((t: Row) => t.status === 'active')
                  .map((t: Row) => (
                    <option key={t.id} value={t.id}>
                      {t.name}
                    </option>
                  ))}
              </select>
            ) : (
              <span className="muted">
                {new Intl.DateTimeFormat('de-DE', { dateStyle: 'long' }).format(new Date())}
              </span>
            )}
          </div>
        </header>
        <main>
          <ErrorBox error={error} />
          {scope === 'restaurant' && user.role === 'system_admin' && !tenant ? (
            <Empty>Wähle oben ein Restaurant aus.</Empty>
          ) : (
            <Content
              key={scope + page + tenant}
              scope={scope}
              page={page}
              tenant={tenant || undefined}
              user={user}
              go={setPage}
            />
          )}
        </main>
        <footer className="page-footer">
          <span>PLATZHIRSCH</span>
          <span>Verwaltung · Version 0.1.0</span>
        </footer>
      </div>
    </div>
  );
}
function Content({
  scope,
  page,
  tenant,
  user,
  go,
}: {
  scope: string;
  page: string;
  tenant?: string;
  user: Row;
  go: (page: string) => void;
}) {
  if (page === 'account') return <Account user={user} />;
  if (page === 'support') return <Support tenant={tenant} user={user} />;
  if (scope === 'system') {
    if (page === 'dashboard') return <Dashboard go={go} />;
    if (page === 'tenants') return <Tenants />;
    if (page === 'users') return <UsersPage />;
    if (page === 'roles') return <Roles />;
    if (page === 'audit-log') return <AuditPage />;
    if (page === 'health') return <Health />;
  }
  if (['overview', 'reservations', 'table-plan'].includes(page))
    return <Reservations tenant={tenant} mode={page} go={go} />;
  if (page === 'widget') return <Widget tenant={tenant} />;
  if (page === 'team') return <UsersPage tenant={tenant} team />;
  if (page === 'profile') return <Profile tenant={tenant} />;
  return <RestaurantResource resource={page} tenant={tenant} />;
}
function DataTable({
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
function Dashboard({ go }: { go: (s: string) => void }) {
  const q = useData('v1/admin/dashboard');
  if (q.isPending) return <Loading />;
  if (q.error) return <ErrorBox error={q.error} />;
  const d = q.data;
  return (
    <>
      <div className="intro">
        <div>
          <h2>Alles im Blick.</h2>
          <p className="muted">Restaurants, Zugänge und die letzten Änderungen deiner Plattform.</p>
        </div>
        <button className="primary" onClick={() => go('tenants')}>
          <Plus size={16} />
          Mandant anlegen
        </button>
      </div>
      <div className="stats">
        {[
          ['Restaurants', d.tenants_total, 'Registrierte Mandanten'],
          ['Aktiv', d.tenants_active, 'Betriebsbereit'],
          ['In Einrichtung', d.tenants_pending, 'Provisionierung läuft'],
          ['Offene Tickets', d.open_tickets, 'Support-Anfragen'],
        ].map(([label, value, sub]) => (
          <section className="stat" key={label}>
            <span>{label}</span>
            <strong>{value}</strong>
            <small>{sub}</small>
          </section>
        ))}
      </div>
      <section className="panel">
        <div className="panel-head">
          <h2>Letzte Aktivitäten</h2>
          <button className="text" onClick={() => go('audit-log')}>
            Audit Log öffnen
            <ChevronRight size={14} />
          </button>
        </div>
        <AuditRows rows={d.recent_audit} />
      </section>
    </>
  );
}
function AuditRows({ rows }: { rows: Row[] }) {
  return (
    <DataTable
      rows={rows}
      columns={[
        { key: 'created_at', label: 'Zeitpunkt' },
        { key: 'action', label: 'Aktion', render: (r) => <code>{r.action}</code> },
        { key: 'actor_id', label: 'Benutzer-ID' },
        { key: 'tenant_id', label: 'Mandant' },
        { key: 'resource', label: 'Objekt' },
      ]}
    />
  );
}
function AuditPage() {
  const q = useData('v1/admin/audit-log');
  return (
    <section className="panel">
      <div className="panel-head">
        <h2>Protokollierte Änderungen</h2>
        <small>Die letzten 100 Einträge</small>
      </div>
      {q.isPending ? <Loading /> : q.error ? <ErrorBox error={q.error} /> : <AuditRows rows={q.data.data} />}
    </section>
  );
}
function Tenants() {
  const q = useData('v1/admin/tenants');
  const qc = useQueryClient();
  const [form, setForm] = useState<Row | null>(null);
  const [search, setSearch] = useState('');
  const [error, setError] = useState<unknown>();
  useEffect(() => {
    const timer = setInterval(() => q.refetch(), 10000);
    return () => clearInterval(timer);
  }, []);
  const fields: Field[] = [
    { key: 'name', label: 'Restaurantname', required: true },
    { key: 'email', label: 'Kontakt-E-Mail', type: 'email', required: true },
    { key: 'phone', label: 'Telefon' },
    { key: 'address', label: 'Adresse', type: 'textarea' },
    ...(!form?.id ? [{ key: 'timezone', label: 'Zeitzone', default: 'Europe/Berlin', required: true }] : []),
  ];
  async function action(row: Row, path: string, method: string, data?: Row) {
    try {
      await api('v1/admin/tenants/' + row.id + path, method, data);
      await qc.invalidateQueries();
    } catch (e) {
      setError(e);
    }
  }
  return (
    <>
      <div className="toolbar">
        <div className="search">
          <Search size={17} />
          <input
            aria-label="Mandanten suchen"
            placeholder="Restaurant suchen …"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <button className="primary" onClick={() => setForm({})}>
          <Plus size={16} />
          Mandant anlegen
        </button>
      </div>
      <ErrorBox error={error} />
      <section className="panel">
        {q.isPending ? (
          <Loading />
        ) : q.error ? (
          <ErrorBox error={q.error} />
        ) : (
          <DataTable
            rows={q.data.data.filter((r: Row) => r.name.toLowerCase().includes(search.toLowerCase()))}
            columns={[
              {
                key: 'name',
                label: 'Restaurant',
                render: (r) => (
                  <div className="cell-title">
                    <strong>{r.name}</strong>
                    <small>#{String(r.id).padStart(5, '0')}</small>
                  </div>
                ),
              },
              { key: 'email', label: 'Kontakt' },
              { key: 'status', label: 'Status', render: (r) => <Badge value={r.status} /> },
              {
                key: 'created_at',
                label: 'Seit',
                render: (r) => new Date(r.created_at).toLocaleDateString('de-DE'),
              },
            ]}
            actions={(r) => (
              <>
                <button onClick={() => setForm(r)}>Bearbeiten</button>
                {r.status === 'failed' ? (
                  <button onClick={() => action(r, '/retry', 'POST')}>Erneut einrichten</button>
                ) : (
                  ['active', 'blocked'].includes(r.status) && (
                    <button
                      onClick={() => {
                        if (confirm(r.status === 'active' ? 'Restaurant sperren?' : 'Restaurant freigeben?'))
                          action(r, '', 'PATCH', { status: r.status === 'active' ? 'blocked' : 'active' });
                      }}
                    >
                      {r.status === 'active' ? 'Sperren' : 'Freigeben'}
                    </button>
                  )
                )}
              </>
            )}
          />
        )}
      </section>
      <p className="muted note">
        Ein neuer Mandant erhält eine eigene Datenbank. Anschließend legst du unter „Benutzer“ den
        Restaurantzugang an.
      </p>
      {form && (
        <Modal title={form.id ? 'Mandant bearbeiten' : 'Neuer Mandant'} close={() => setForm(null)}>
          <Form
            fields={fields}
            initial={form}
            onSave={async (data) => {
              await api(
                'v1/admin/tenants' + (form.id ? '/' + form.id : ''),
                form.id ? 'PATCH' : 'POST',
                data,
              );
              setForm(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
    </>
  );
}
function UsersPage({ tenant, team = false }: { tenant?: string; team?: boolean }) {
  const path = team ? 'v1/restaurant/team' : 'v1/admin/users';
  const q = useData(path, tenant);
  const tenants = useQuery({
    queryKey: ['user-tenants'],
    queryFn: () => api('v1/admin/tenants'),
    enabled: !team,
  });
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [error, setError] = useState<unknown>();
  const fields: Field[] = [
    { key: 'name', label: 'Name', required: true },
    { key: 'email', label: 'E-Mail', type: 'email', required: true },
    {
      key: 'role',
      label: 'Rolle',
      required: true,
      options: pick(team ? ['restaurant_admin', 'staff'] : ['system_admin', 'restaurant_admin', 'staff']),
    },
    ...(!team
      ? [
          {
            key: 'tenant_id',
            label: 'Restaurant (leer für System-Administrator)',
            options: tenants.data?.data?.map((t: Row) => ({ value: t.id, label: t.name })) || [],
          },
        ]
      : []),
    {
      key: 'password',
      label: 'Anfangspasswort',
      type: 'password',
      required: true,
      help: 'Mindestens 12 Zeichen. Sicher übermitteln; alternativ danach einen Einrichtungslink senden.',
    },
  ];
  return (
    <>
      <div className="toolbar">
        <p className="muted">Personen und ihre Zugriffsbereiche</p>
        <button className="primary" onClick={() => setOpen(true)}>
          <Plus size={16} />
          Benutzer anlegen
        </button>
      </div>
      <ErrorBox error={error} />
      <section className="panel">
        {q.isPending ? (
          <Loading />
        ) : q.error ? (
          <ErrorBox error={q.error} />
        ) : (
          <DataTable
            rows={q.data}
            columns={[
              { key: 'name', label: 'Name' },
              { key: 'email', label: 'E-Mail' },
              { key: 'role', label: 'Rolle', render: (r) => labels[r.role] },
              { key: 'tenant_id', label: 'Restaurant-ID' },
              { key: 'active', label: 'Status', render: (r) => <Badge value={r.active} /> },
            ]}
            actions={
              !team
                ? (r) => (
                    <>
                      <button
                        onClick={async () => {
                          try {
                            await api('v1/admin/users/' + r.id + '/invite', 'POST');
                            alert('Einrichtungslink versendet.');
                          } catch (e) {
                            setError(e);
                          }
                        }}
                      >
                        Einladungslink
                      </button>
                      <button
                        onClick={async () => {
                          if (!confirm(r.active ? 'Zugang sperren?' : 'Zugang freigeben?')) return;
                          try {
                            await api('v1/admin/users/' + r.id, 'PATCH', { active: !r.active });
                            await q.refetch();
                          } catch (e) {
                            setError(e);
                          }
                        }}
                      >
                        {r.active ? 'Sperren' : 'Freigeben'}
                      </button>
                    </>
                  )
                : undefined
            }
          />
        )}
      </section>
      {open && (
        <Modal title="Benutzer anlegen" close={() => setOpen(false)}>
          <Form
            fields={fields}
            onSave={async (data) => {
              await api(path, 'POST', { ...data, tenant_id: data.tenant_id || null }, tenant);
              setOpen(false);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
    </>
  );
}
function Roles() {
  const q = useData('v1/admin/roles');
  return (
    <>
      <p className="muted">
        Diese Systemrollen sind fest definiert. Berechtigungen werden auf dem Server geprüft.
      </p>
      {q.isPending ? (
        <Loading />
      ) : q.error ? (
        <ErrorBox error={q.error} />
      ) : (
        <div className="role-grid">
          {q.data.map((r: Row) => (
            <section className="panel role" key={r.code}>
              <ShieldCheck size={24} />
              <h2>{r.name}</h2>
              <code>{r.code}</code>
              <ul>
                {r.permissions.map((p: string) => (
                  <li key={p}>
                    {p === '*'
                      ? 'Vollständiger Plattformzugriff'
                      : p === 'restaurant.manage'
                        ? 'Restaurant, Team und Einrichtung verwalten'
                        : 'Reservierungen lesen und bearbeiten'}
                  </li>
                ))}
              </ul>
              <Badge value="Systemrolle" />
            </section>
          ))}
        </div>
      )}
    </>
  );
}
function Health() {
  const q = useData('v1/admin/health');
  return (
    <section className="panel">
      <div className="panel-head">
        <h2>Betriebsstatus</h2>
        <button onClick={() => q.refetch()}>
          <RefreshCw size={15} />
          Aktualisieren
        </button>
      </div>
      {q.isPending ? (
        <Loading />
      ) : q.error ? (
        <ErrorBox error={q.error} />
      ) : (
        <dl className="details">
          {Object.entries({
            Version: q.data.version,
            'PHP-Version': q.data.php,
            Datenbank: q.data.database,
            'DB-Antwortzeit': q.data.latency_ms + ' ms',
            'Wartende Aufgaben': q.data.queued_jobs,
            'Fehlgeschlagene Aufgaben': q.data.failed_jobs,
            Mailversand: q.data.mail_configured ? 'Konfiguriert (kein Versandtest)' : 'Nicht konfiguriert',
            'Scheduler zuletzt gesehen': q.data.scheduler_last_seen || 'Noch kein Lebenszeichen',
          }).map(([k, v]) => (
            <React.Fragment key={k}>
              <dt>{k}</dt>
              <dd>{String(v)}</dd>
            </React.Fragment>
          ))}
        </dl>
      )}
    </section>
  );
}
function RestaurantResource({ resource, tenant }: { resource: string; tenant?: string }) {
  const path = 'v1/restaurant/' + resource;
  const q = useData(path, tenant);
  const rooms = useQuery({
    queryKey: ['v1/restaurant/rooms', tenant],
    queryFn: () => api('v1/restaurant/rooms', 'GET', undefined, tenant),
    enabled: resource === 'tables',
  });
  const [form, setForm] = useState<Row | null>(null);
  const [error, setError] = useState<unknown>();
  const qc = useQueryClient();
  const weekdays = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
  const fields: Field[] =
    resource === 'rooms'
      ? [
          { key: 'name', label: 'Raumname', required: true },
          {
            key: 'color',
            label: 'Kennfarbe',
            required: true,
            default: 'terracotta',
            options: pick(['terracotta', 'sage', 'sky', 'mustard', 'plum', 'slate']),
          },
          { key: 'outdoor', label: 'Außenbereich', type: 'checkbox' },
        ]
      : resource === 'tables'
        ? [
            { key: 'name', label: 'Tischname', required: true },
            {
              key: 'room_id',
              label: 'Raum',
              required: true,
              options: rooms.data?.map((r: Row) => ({ value: r.id, label: r.name })) || [],
            },
            {
              key: 'capacity',
              label: 'Sitzplätze',
              type: 'number',
              required: true,
              min: 1,
              max: 50,
              default: 4,
            },
            { key: 'active', label: 'Für Buchungen verfügbar', type: 'checkbox', default: true },
          ]
        : resource === 'hours'
          ? [
              {
                key: 'weekday',
                label: 'Wochentag',
                required: true,
                options: weekdays.map((label, i) => ({ value: i + 1, label })),
              },
              { key: 'opens', label: 'Öffnet um', type: 'time', required: true },
              { key: 'closes', label: 'Schließt um', type: 'time', required: true },
            ]
          : [
              { key: 'date', label: 'Datum', type: 'date', required: true },
              { key: 'closed', label: 'Ganztägig geschlossen', type: 'checkbox', default: true },
              { key: 'opens', label: 'Öffnet um (wenn geöffnet)', type: 'time' },
              { key: 'closes', label: 'Schließt um (wenn geöffnet)', type: 'time' },
              { key: 'note', label: 'Anlass / Hinweis' },
            ];
  const columns = fields.map((f) => ({
    key: f.key,
    label: f.label,
    render: (r: Row) =>
      f.options ? (
        f.options.find((o) => String(o.value) === String(r[f.key]))?.label || r[f.key]
      ) : f.type === 'checkbox' ? (
        <Badge value={Boolean(r[f.key])} />
      ) : (
        r[f.key] || '—'
      ),
  }));
  return (
    <>
      <div className="toolbar">
        <p className="muted">
          {resource === 'hours'
            ? 'Mehrere Zeitfenster pro Tag möglich. Buchungen müssen vollständig in ein Zeitfenster passen.'
            : resource === 'special-days'
              ? 'Sondertage ersetzen die regulären Öffnungszeiten.'
              : 'Deine Restaurant-Konfiguration'}
        </p>
        <button className="primary" onClick={() => setForm({})}>
          <Plus size={16} />
          Hinzufügen
        </button>
      </div>
      <ErrorBox error={error} />
      <section className="panel">
        {q.isPending ? (
          <Loading />
        ) : q.error ? (
          <ErrorBox error={q.error} />
        ) : (
          <DataTable
            rows={q.data}
            columns={columns}
            actions={(r) => (
              <>
                <button
                  onClick={() =>
                    setForm({ ...r, opens: r.opens?.slice(0, 5), closes: r.closes?.slice(0, 5) })
                  }
                >
                  Bearbeiten
                </button>
                <button
                  className="danger-text"
                  onClick={async () => {
                    if (!confirm('Diesen Eintrag löschen?')) return;
                    try {
                      await api(path + '/' + r.id, 'DELETE', undefined, tenant);
                      await qc.invalidateQueries();
                    } catch (e) {
                      setError(e);
                    }
                  }}
                >
                  Löschen
                </button>
              </>
            )}
          />
        )}
      </section>
      {form && (
        <Modal title={form.id ? 'Eintrag bearbeiten' : 'Neuer Eintrag'} close={() => setForm(null)}>
          <Form
            fields={fields}
            initial={form}
            onSave={async (data) => {
              await api(path + (form.id ? '/' + form.id : ''), form.id ? 'PATCH' : 'POST', data, tenant);
              setForm(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
    </>
  );
}
function localInput(utc: string, tz: string) {
  const parts = new Intl.DateTimeFormat('sv-SE', {
    timeZone: tz,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(utc.replace(' ', 'T') + 'Z'));
  const p = Object.fromEntries(parts.map((v) => [v.type, v.value]));
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
}
function Reservations({ tenant, mode, go }: { tenant?: string; mode: string; go: (s: string) => void }) {
  const [date, setDate] = useState(today());
  const q = useData('v1/restaurant/reservations?date=' + date, tenant);
  const tables = useData('v1/restaurant/tables', tenant);
  const profile = useData('v1/restaurant/profile', tenant);
  const qc = useQueryClient();
  const [form, setForm] = useState<Row | null>(null);
  const [error, setError] = useState<unknown>();
  const tz = profile.data?.timezone || 'Europe/Berlin';
  const rows: Row[] = q.data || [];
  const live = rows.filter((r) => !['cancelled', 'no_show'].includes(r.status));
  const fields: Field[] = [
    { key: 'guest_name', label: 'Name des Gastes', required: true },
    { key: 'email', label: 'E-Mail', type: 'email' },
    { key: 'phone', label: 'Telefon' },
    {
      key: 'table_id',
      label: 'Tisch',
      required: true,
      options:
        tables.data
          ?.filter((r: Row) => r.active)
          .map((r: Row) => ({ value: r.id, label: `${r.name} · ${r.capacity} Plätze` })) || [],
    },
    { key: 'party_size', label: 'Personen', type: 'number', required: true, min: 1, max: 50, default: 2 },
    {
      key: 'starts_at',
      label: `Beginn (${tz})`,
      type: 'datetime-local',
      required: true,
      default: date + 'T18:00',
    },
    {
      key: 'duration_minutes',
      label: 'Dauer in Minuten',
      type: 'number',
      required: true,
      min: 15,
      max: 360,
      default: 90,
    },
    {
      key: 'status',
      label: 'Status',
      required: true,
      default: 'confirmed',
      options: pick(['confirmed', 'seated', 'completed', 'cancelled', 'no_show']),
    },
    { key: 'notes', label: 'Notizen', type: 'textarea' },
  ];
  function edit(r: Row) {
    setForm({
      ...r,
      starts_at: localInput(r.starts_at, tz),
      duration_minutes:
        (Date.parse(r.ends_at.replace(' ', 'T') + 'Z') - Date.parse(r.starts_at.replace(' ', 'T') + 'Z')) /
        60000,
    });
  }
  async function cancel(r: Row) {
    if (!confirm('Reservierung wirklich stornieren?')) return;
    try {
      await api('v1/restaurant/reservations/' + r.id + '/cancel', 'POST', {}, tenant);
      await qc.invalidateQueries();
    } catch (e) {
      setError(e);
    }
  }
  async function download() {
    try {
      const response = await fetch('/api/v1/restaurant/export?date=' + date, {
        credentials: 'same-origin',
        headers: tenant ? { 'X-Tenant-ID': tenant } : {},
      });
      if (!response.ok) throw new Error('Export fehlgeschlagen.');
      const url = URL.createObjectURL(await response.blob());
      const a = document.createElement('a');
      a.href = url;
      a.download = 'reservierungen-' + date + '.csv';
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      setError(e);
    }
  }
  return (
    <>
      <div className="toolbar">
        <div className="date-picker">
          <CalendarDays size={17} />
          <input
            type="date"
            aria-label="Reservierungsdatum"
            value={date}
            onChange={(e) => setDate(e.target.value)}
          />
          <button onClick={() => setDate(today())}>Heute</button>
        </div>
        <div className="button-row">
          <button onClick={download}>
            <Download size={16} />
            CSV
          </button>
          <button className="primary" onClick={() => setForm({ request_key: crypto.randomUUID() })}>
            <Plus size={16} />
            Reservierung
          </button>
        </div>
      </div>
      <ErrorBox error={error} />
      <ErrorBox error={tables.error} />
      {mode === 'overview' && (
        <div className="stats">
          {[
            ['Reservierungen', live.length, 'Für den gewählten Tag'],
            ['Gäste', live.reduce((n, r) => n + r.party_size, 0), 'Erwartete Personen'],
            ['Tische', tables.data?.filter((r: Row) => r.active).length || 0, 'Für Buchungen verfügbar'],
            ['Stornierungen', rows.filter((r) => r.status === 'cancelled').length, 'Für den gewählten Tag'],
          ].map(([label, value, sub]) => (
            <section className="stat" key={label}>
              <span>{label}</span>
              <strong>{value}</strong>
              <small>{sub}</small>
            </section>
          ))}
        </div>
      )}
      <section className="panel">
        <div className="panel-head">
          <h2>{mode === 'table-plan' ? 'Belegung nach Tisch' : 'Reservierungen'}</h2>
          <small>
            {new Date(date + 'T12:00:00').toLocaleDateString('de-DE', {
              weekday: 'long',
              day: 'numeric',
              month: 'long',
            })}
          </small>
        </div>
        {q.isPending ? (
          <Loading />
        ) : q.error ? (
          <ErrorBox error={q.error} />
        ) : mode === 'table-plan' ? (
          <div className="table-plan">
            {tables.data?.map((table: Row) => (
              <section key={table.id} className="table-lane">
                <div>
                  <Armchair size={20} />
                  <strong>{table.name}</strong>
                  <small>{table.capacity} Plätze</small>
                </div>
                <div>
                  {live.filter((r) => r.table_id === table.id).length ? (
                    live
                      .filter((r) => r.table_id === table.id)
                      .map((r) => (
                        <button className="booking-block" key={r.id} onClick={() => edit(r)}>
                          <strong>
                            {clock(r.starts_at, tz)}–{clock(r.ends_at, tz)}
                          </strong>
                          <span>
                            {r.guest_name} · {r.party_size} Personen
                          </span>
                          <Badge value={r.status} />
                        </button>
                      ))
                  ) : (
                    <span className="muted">Keine Reservierungen</span>
                  )}
                </div>
              </section>
            ))}
          </div>
        ) : (
          <DataTable
            rows={rows}
            columns={[
              {
                key: 'starts_at',
                label: 'Zeit',
                render: (r) => (
                  <code>
                    {clock(r.starts_at, tz)}–{clock(r.ends_at, tz)}
                  </code>
                ),
              },
              {
                key: 'guest_name',
                label: 'Gast',
                render: (r) => (
                  <div className="cell-title">
                    <strong>{r.guest_name}</strong>
                    <small>{r.phone || r.email || 'Keine Kontaktdaten'}</small>
                  </div>
                ),
              },
              { key: 'party_size', label: 'Personen' },
              { key: 'table_name', label: 'Tisch' },
              { key: 'status', label: 'Status', render: (r) => <Badge value={r.status} /> },
            ]}
            actions={(r) => (
              <>
                <button onClick={() => edit(r)}>Bearbeiten</button>
                {r.status !== 'cancelled' && (
                  <button className="danger-text" onClick={() => cancel(r)}>
                    Stornieren
                  </button>
                )}
              </>
            )}
          />
        )}
      </section>
      {!tables.isPending && !tables.data?.length && (
        <div className="notice">
          Lege zuerst Räume und Tische an und hinterlege die Öffnungszeiten.{' '}
          <button onClick={() => go('rooms')}>Räume öffnen</button>
        </div>
      )}
      {form && (
        <Modal title={form.id ? 'Reservierung bearbeiten' : 'Neue Reservierung'} close={() => setForm(null)}>
          <Form
            fields={fields}
            initial={form}
            onSave={async (data) => {
              await api(
                'v1/restaurant/reservations' + (form.id ? '/' + form.id : ''),
                form.id ? 'PATCH' : 'POST',
                { ...data, request_key: form.request_key, version: form.version },
                tenant,
              );
              setForm(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
    </>
  );
}
function Profile({ tenant }: { tenant?: string }) {
  const q = useData('v1/restaurant/profile', tenant);
  const [notice, setNotice] = useState('');
  return (
    <section className="panel padded">
      <h2>Restaurant-Stammdaten</h2>
      {notice && <div className="notice">{notice}</div>}
      {q.isPending ? (
        <Loading />
      ) : q.error ? (
        <ErrorBox error={q.error} />
      ) : (
        <Form
          fields={[
            { key: 'name', label: 'Restaurantname', required: true },
            { key: 'email', label: 'Kontakt-E-Mail', type: 'email', required: true },
            { key: 'phone', label: 'Telefon' },
            { key: 'address', label: 'Adresse', type: 'textarea' },
          ]}
          initial={q.data}
          onSave={async (data) => {
            await api('v1/restaurant/profile', 'PATCH', data, tenant);
            setNotice('Profil gespeichert.');
            await q.refetch();
          }}
        />
      )}
    </section>
  );
}
function Account({ user }: { user: Row }) {
  const [secret, setSecret] = useState('');
  const [done, setDone] = useState(false);
  return (
    <section className="panel padded">
      <h2>Dein Zugang</h2>
      <p>
        {user.name} · {user.email}
      </p>
      <h3>Zwei-Faktor-Anmeldung</h3>
      {user.mfa_enabled || done ? (
        <div className="notice">
          <Check size={17} />
          Zwei-Faktor-Anmeldung ist aktiv. Für eine Wiederherstellung wende dich an den Server-Administrator.
        </div>
      ) : secret ? (
        <>
          <p>Füge diesen Schlüssel in deiner Authenticator-App als zeitbasierten Code hinzu:</p>
          <code className="secret">{secret}</code>
          <Form
            fields={[{ key: 'code', label: 'Bestätigungscode', required: true }]}
            onSave={async (data) => {
              await api('v1/admin/auth/mfa/confirm', 'POST', data);
              setSecret('');
              setDone(true);
            }}
          />
        </>
      ) : (
        <>
          <p className="muted">
            Schütze dein Konto mit einem zusätzlichen Code aus deiner Authenticator-App.
          </p>
          <Form
            fields={[{ key: 'password', label: 'Aktuelles Passwort', type: 'password', required: true }]}
            label="Einrichtung starten"
            onSave={async (data) => {
              const result = await api('v1/admin/auth/mfa/begin', 'POST', data);
              setSecret(result.secret);
            }}
          />
        </>
      )}
    </section>
  );
}
function Widget({ tenant }: { tenant?: string }) {
  const q = useData('v1/restaurant/widget', tenant);
  const [open, setOpen] = useState(false);
  const [url, setUrl] = useState('');
  const [error, setError] = useState<unknown>();
  return (
    <>
      <div className="toolbar">
        <p className="muted">
          Buchungszugänge für deine Website. Links sind ein Jahr gültig und können widerrufen werden.
        </p>
        <button className="primary" onClick={() => setOpen(true)}>
          <Plus size={16} />
          Buchungslink erstellen
        </button>
      </div>
      <ErrorBox error={error} />
      {url && (
        <section className="panel padded">
          <h2>Dein neuer Buchungslink</h2>
          <p>Jetzt kopieren — der vollständige Link wird nur einmal angezeigt.</p>
          <code className="secret">{url}</code>
          <button onClick={() => navigator.clipboard.writeText(url)}>Kopieren</button>
          <a className="button" href={url} target="_blank" rel="noreferrer">
            Buchungsseite öffnen
          </a>
          <p className="muted">
            Verlinke ihn auf deiner Restaurant-Website. Der Zugriff erlaubt ausschließlich öffentliche
            Buchungen, keinen Verwaltungszugang.
          </p>
        </section>
      )}
      <section className="panel">
        {q.isPending ? (
          <Loading />
        ) : q.error ? (
          <ErrorBox error={q.error} />
        ) : (
          <DataTable
            rows={q.data}
            columns={[
              { key: 'id', label: 'ID' },
              {
                key: 'origins',
                label: 'Websites',
                render: (r) =>
                  typeof r.origins === 'string' ? JSON.parse(r.origins).join(', ') : r.origins.join(', '),
              },
              { key: 'expires_at', label: 'Gültig bis' },
            ]}
            actions={(r) => (
              <button
                className="danger-text"
                onClick={async () => {
                  if (!confirm('Buchungslink widerrufen? Bestehende Buchungen bleiben erhalten.')) return;
                  try {
                    await api('v1/restaurant/widget/' + r.id, 'DELETE', undefined, tenant);
                    await q.refetch();
                  } catch (e) {
                    setError(e);
                  }
                }}
              >
                Widerrufen
              </button>
            )}
          />
        )}
      </section>
      {open && (
        <Modal title="Buchungszugang erstellen" close={() => setOpen(false)}>
          <Form
            fields={[
              {
                key: 'origin',
                label: 'Website-Ursprung',
                type: 'url',
                required: true,
                help: 'Zum Beispiel https://mein-restaurant.de — ohne Unterseite.',
              },
            ]}
            onSave={async (data) => {
              const result = await api('v1/restaurant/widget', 'POST', { origins: [data.origin] }, tenant);
              setUrl(result.url);
              setOpen(false);
              await q.refetch();
            }}
          />
        </Modal>
      )}
    </>
  );
}
function Booking() {
  const token = new URLSearchParams(location.hash.split('?')[1]).get('token') || '';
  const q = useData('widget/' + token);
  const [result, setResult] = useState<Row | null>(null);
  const [key] = useState(() => crypto.randomUUID());
  return (
    <div className="auth-page">
      <div className="booking-wrap">
        <div className="brand large">
          <b>P</b>
          <div>
            <h1>{q.data?.name || 'Tisch reservieren'}</h1>
            <small>RESERVIEREN MIT PLATZHIRSCH</small>
          </div>
        </div>
        <section className="auth-card">
          {q.isPending ? (
            <Loading />
          ) : q.error ? (
            <ErrorBox error={q.error} />
          ) : result ? (
            <div className="confirmation">
              <Check size={32} />
              <h2>Dein Tisch ist reserviert.</h2>
              <p>
                Buchungsnummer <strong>#{result.id}</strong>
              </p>
              <p>Bitte notiere diese Nummer. Bei Änderungen kontaktiere das Restaurant direkt.</p>
            </div>
          ) : (
            <>
              <h2>Ein Platz für dich.</h2>
              <p className="muted">Reservierungsdauer: 90 Minuten · Uhrzeiten in {q.data.timezone}</p>
              <Form
                label="Verbindlich reservieren"
                fields={[
                  { key: 'guest_name', label: 'Dein Name', required: true },
                  { key: 'email', label: 'E-Mail', type: 'email', required: true },
                  { key: 'phone', label: 'Telefon' },
                  {
                    key: 'party_size',
                    label: 'Personen',
                    type: 'number',
                    required: true,
                    min: 1,
                    max: 50,
                    default: 2,
                  },
                  {
                    key: 'table_id',
                    label: 'Tisch',
                    required: true,
                    options: q.data.tables.map((t: Row) => ({
                      value: t.id,
                      label: `${t.name} · ${t.capacity} Plätze`,
                    })),
                  },
                  { key: 'starts_at', label: 'Datum und Uhrzeit', type: 'datetime-local', required: true },
                  { key: 'notes', label: 'Wünsche / Hinweise', type: 'textarea' },
                  {
                    key: 'consent',
                    label: 'Meine Angaben dürfen zur Bearbeitung dieser Reservierung verwendet werden.',
                    type: 'checkbox',
                    required: true,
                  },
                ]}
                onSave={async (data) => {
                  const response = await fetch('/api/widget/' + token, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ ...data, request_key: key, duration_minutes: 90 }),
                  });
                  const body = await response.json();
                  if (!response.ok)
                    throw new Error(
                      body.errors
                        ? Object.values(body.errors).flat().join(' ')
                        : body.message || 'Buchung fehlgeschlagen.',
                    );
                  setResult(body);
                }}
              />
            </>
          )}
        </section>
      </div>
    </div>
  );
}
function Support({ tenant, user }: { tenant?: string; user: Row }) {
  const q = useData('v1/support');
  const [selected, setSelected] = useState<number | null>(null);
  const detail = useQuery({
    queryKey: ['support', selected],
    queryFn: () => api('v1/support/' + selected),
    enabled: selected !== null,
  });
  const [open, setOpen] = useState(false);
  const qc = useQueryClient();
  return (
    <>
      <div className="toolbar">
        <div>
          {selected ? (
            <button onClick={() => setSelected(null)}>
              <ArrowLeft size={16} />
              Alle Tickets
            </button>
          ) : (
            <p className="muted">Fragen, Fehler und Rückmeldungen</p>
          )}
        </div>
        <button className="primary" onClick={() => setOpen(true)}>
          <Plus size={16} />
          Ticket erstellen
        </button>
      </div>
      {selected ? (
        <section className="panel padded">
          {detail.isPending ? (
            <Loading />
          ) : detail.error ? (
            <ErrorBox error={detail.error} />
          ) : (
            <>
              <div className="panel-head">
                <h2>
                  #{detail.data.ticket.id} · {detail.data.ticket.subject}
                </h2>
                <Badge value={detail.data.ticket.status} />
              </div>
              <div className="messages">
                {detail.data.messages.map((m: Row) => (
                  <article key={m.id} className={m.internal ? 'internal' : ''}>
                    <header>
                      <strong>{m.author}</strong>
                      <small>
                        {m.created_at}
                        {m.internal ? ' · Interne Notiz' : ''}
                      </small>
                    </header>
                    <p>{m.body}</p>
                  </article>
                ))}
              </div>
              <h3>Antwort schreiben</h3>
              <Form
                fields={[
                  { key: 'body', label: 'Nachricht', type: 'textarea', required: true },
                  {
                    key: 'status',
                    label: 'Status',
                    default: 'open',
                    options: pick(['open', 'in_progress', 'closed']),
                  },
                  ...(user.role === 'system_admin'
                    ? [
                        {
                          key: 'internal',
                          label: 'Interne Notiz (nicht für Restaurant sichtbar)',
                          type: 'checkbox',
                        },
                      ]
                    : []),
                ]}
                onSave={async (data) => {
                  await api('v1/support/' + selected + '/messages', 'POST', data);
                  await qc.invalidateQueries();
                }}
              />
            </>
          )}
        </section>
      ) : (
        <section className="panel">
          {q.isPending ? (
            <Loading />
          ) : q.error ? (
            <ErrorBox error={q.error} />
          ) : (
            <DataTable
              rows={q.data.data}
              columns={[
                { key: 'id', label: 'Ticket' },
                { key: 'subject', label: 'Betreff' },
                { key: 'status', label: 'Status', render: (r) => <Badge value={r.status} /> },
                { key: 'priority', label: 'Priorität', render: (r) => <Badge value={r.priority} /> },
              ]}
              actions={(r) => (
                <button onClick={() => setSelected(r.id)}>
                  Öffnen
                  <ChevronRight size={14} />
                </button>
              )}
            />
          )}
        </section>
      )}
      {open && (
        <Modal title="Neues Support-Ticket" close={() => setOpen(false)}>
          <Form
            fields={[
              { key: 'subject', label: 'Betreff', required: true },
              {
                key: 'priority',
                label: 'Priorität',
                default: 'normal',
                options: pick(['low', 'normal', 'high']),
                required: true,
              },
              ...(user.role === 'system_admin'
                ? [
                    {
                      key: 'tenant_id',
                      label: 'Restaurant-ID',
                      type: 'number',
                      required: true,
                      default: tenant,
                    },
                  ]
                : []),
              { key: 'body', label: 'Beschreibung', type: 'textarea', required: true },
            ]}
            onSave={async (data) => {
              const result = await api('v1/support', 'POST', data);
              setOpen(false);
              setSelected(result.id);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
    </>
  );
}

class ErrorBoundary extends React.Component<{ children: ReactNode }, { error: Error | null }> {
  state = { error: null as Error | null };
  static getDerivedStateFromError(error: Error) {
    return { error };
  }
  render() {
    return this.state.error ? (
      <div className="auth-page">
        <section className="auth-card">
          <h1>Die Ansicht konnte nicht geladen werden.</h1>
          <p>Bitte lade die Seite neu. Bereits gespeicherte Daten bleiben erhalten.</p>
          <button onClick={() => location.reload()}>Neu laden</button>
        </section>
      </div>
    ) : (
      this.props.children
    );
  }
}
createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <ErrorBoundary>
      <QueryClientProvider client={client}>
        <App />
      </QueryClientProvider>
    </ErrorBoundary>
  </React.StrictMode>,
);
