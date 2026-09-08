import {
  Login,
  ResetPassword,
  UsersPage,
  RestaurantRoles,
  Account,
} from '@platzhirsch/identity-ui/Screens.tsx';
const Widget = lazy(() => import('@platzhirsch/widget-ui').then((m) => ({ default: m.Widget })));
const Booking = lazy(() => import('@platzhirsch/widget-ui').then((m) => ({ default: m.Booking })));
const Reservations = lazy(() =>
  import('@platzhirsch/reservation-ui').then((m) => ({ default: m.Reservations })),
);
const RestaurantResource = lazy(() =>
  import('@platzhirsch/reservation-ui').then((m) => ({ default: m.RestaurantResource })),
);
import {
  Row,
  Field,
  today,
  labels,
  pick,
  clock,
  Badge,
  ErrorBox,
  Loading,
  Empty,
  useData,
  Modal,
  Form,
  DataTable,
} from '@platzhirsch/ui-runtime/components';
const Support = lazy(() => import('@platzhirsch/support-ui/Support.tsx'));
import { billingManifest } from '@platzhirsch/billing-ui';
import { reportingManifest } from '@platzhirsch/reporting-ui';
const ModuleShop = lazy(billingManifest.nav[0].screen);
const Reporting = lazy(reportingManifest.nav[0].screen);
const BillingAdministration = lazy(() => import('@platzhirsch/billing-ui/Administration.tsx'));
const Placement = lazy(() => import('@platzhirsch/provisioning-ui/Placement.tsx'));
const DatabaseAccess = lazy(() => import('@platzhirsch/provisioning-ui/DatabaseAccess.tsx'));
import { identityManifest } from '@platzhirsch/identity-ui';
import ModuleCatalog from './module-host/Catalog';
const PlatformRoles = lazy(identityManifest.nav[0].screen);
import { portal } from './api';
import { Suspense, lazy } from 'react';
import { provisioningManifest } from '@platzhirsch/provisioning-ui';
import { navigationFor } from './module-host/registry';
const provisioningNavigation = provisioningManifest.nav[0];
const DatabaseServers = lazy(provisioningNavigation.screen);
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

const client = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (n, e) => !(e instanceof ApiError && e.status < 500) && n < 1,
      staleTime: 10000,
      refetchOnWindowFocus: false,
    },
  },
});
const systemNav = [
  ['dashboard', 'Übersicht', LayoutDashboard],
  ['tenants', 'Mandanten', Building2],
  ['users', 'Benutzer', Users],
  ['roles', 'Rollen & Rechte', ShieldCheck],
  ['modules', 'Module', Code2],
  ['database-access', 'SQL-Zugangsdaten', ShieldCheck],
  ['billing-admin', 'Angebote & Bestellungen', Code2],
  ['placement', 'Serverzuordnung & Umzüge', Building2],
  ['audit-log', 'Audit Log', ScrollText],
  ['health', 'System', Activity],
  [provisioningNavigation.key, provisioningNavigation.label, provisioningNavigation.icon],
  ['support', 'Support', MessageSquare],
  ['account', 'Mein Konto', Settings],
] as const;
const restaurantNav = [
  ['overview', 'Auswertung', LayoutDashboard],
  ['reporting', 'Erweiterte Auswertungen', LayoutDashboard],
  ['module-shop', 'Modul-Shop', Code2],
  ['reservations', 'Reservierungen', CalendarDays],
  ['table-plan', 'Tischplan', Armchair],
  ['tables', 'Tische', Armchair],
  ['rooms', 'Räume', DoorOpen],
  ['hours', 'Öffnungszeiten', Clock],
  ['special-days', 'Sondertage', CalendarDays],
  ['widget', 'Widget', Code2],
  ['team', 'Team', Users],
  ['restaurant-roles', 'Rollen & Rechte', ShieldCheck],
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
  if (hash.startsWith('#booking'))
    return (
      <Suspense fallback={<Loading />}>
        <Booking />
      </Suspense>
    );
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
        setup={portal === 'administration' && status.data?.bootstrapped === false}
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
function allowed(user: Row, permission: string) {
  return user.permissions?.includes('*') || user.permissions?.includes(permission);
}
const pagePermission: Record<string, string> = {
  overview: 'reservation.read',
  reporting: 'reporting.read',
  'module-shop': 'modules.manage',
  reservations: 'reservation.read',
  'table-plan': 'reservation.read',
  tables: 'restaurant.configure',
  rooms: 'restaurant.configure',
  hours: 'restaurant.configure',
  'special-days': 'restaurant.configure',
  widget: 'widget.manage',
  team: 'team.manage',
  'restaurant-roles': 'roles.manage',
  profile: 'restaurant.profile',
  support: 'support.access',
};
const platformPagePermission: Record<string, string> = {
  dashboard: 'platform.dashboard.read',
  tenants: 'platform.tenants.read',
  users: 'platform.users.read',
  roles: 'platform.roles.manage',
  modules: 'platform.modules.read',
  'audit-log': 'platform.audit.read',
  health: 'platform.health.read',
  support: 'support.access',
  'database-servers': 'provisioning.servers.read',
};
function Shell({ user }: { user: Row }) {
  const scope = portal === 'administration' ? 'system' : 'restaurant';
  const [page, setPage] = useState(
    user.role === 'system_admin' ? 'dashboard' : allowed(user, 'reservation.read') ? 'overview' : 'account',
  );
  const [mobile, setMobile] = useState(false);
  const installedModules = useQuery({
    queryKey: ['installed-modules'],
    queryFn: () => api('v1/admin/modules'),
    enabled: scope === 'system' && allowed(user, 'platform.modules.read'),
  });
  const installedCodes: string[] =
    user.installed_modules ||
    (installedModules.data || []).filter((m: Row) => m.installed).map((m: Row) => m.code);
  const provisioningVisible = navigationFor(
    [provisioningManifest, identityManifest],
    installedCodes,
    user.permissions || [],
    portal,
  ).some((n) => n.key === provisioningNavigation.key);
  const identityVisible = installedCodes.includes('identity');
  const q = useQueryClient();
  const [error, setError] = useState<unknown>();
  const nav =
    scope === 'system'
      ? systemNav.filter(
          ([key]) =>
            (!['database-access', 'placement', 'billing-admin'].includes(key) ||
              user.role === 'system_admin') &&
            (!platformPagePermission[key] || allowed(user, platformPagePermission[key])) &&
            (key !== provisioningNavigation.key || provisioningVisible) &&
            (key !== 'roles' || identityVisible),
        )
      : restaurantNav.filter(
          ([key]) =>
            (key !== 'reporting' || user.enabled_modules?.includes('reporting')) &&
            (key !== 'module-shop' || user.role === 'restaurant_admin') &&
            (!pagePermission[key] || allowed(user, pagePermission[key])),
        );
  const title = nav.find(([key]) => key === page)?.[1] || 'Platzhirsch';
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
        <a
          className="scope-switch"
          href={portal === 'administration' ? '/restaurant/login' : '/administration/login'}
        >
          {portal === 'administration' ? 'Restaurantportal öffnen' : 'Administration öffnen'}
        </a>
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
            <span className="muted">
              {new Intl.DateTimeFormat('de-DE', { dateStyle: 'long' }).format(new Date())}
            </span>
          </div>
        </header>
        <main>
          <ErrorBox error={error} />
          <Content key={scope + page} scope={scope} page={page} user={user} go={setPage} />
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
  if (scope === 'restaurant' && pagePermission[page] && !allowed(user, pagePermission[page]))
    return <Empty>Für diesen Bereich fehlt dir die Berechtigung.</Empty>;
  if (page === 'restaurant-roles') return <RestaurantRoles tenant={tenant} />;
  if (page === 'account') return <Account user={user} />;
  if (page === 'support')
    return (
      <Suspense fallback={<Loading />}>
        <Support tenant={tenant} user={user} />
      </Suspense>
    );
  if (scope === 'system') {
    if (page === 'dashboard') return <Dashboard go={go} />;
    if (page === 'tenants') return <Tenants user={user} />;
    if (page === 'users') return <UsersPage user={user} />;
    if (page === 'roles')
      return (
        <Suspense fallback={<Loading />}>
          <PlatformRoles />
        </Suspense>
      );
    if (page === 'modules') return <ModuleCatalog />;
    if (page === 'billing-admin' && user.role === 'system_admin')
      return (
        <Suspense fallback={<Loading />}>
          <BillingAdministration />
        </Suspense>
      );
    if (page === 'placement' && user.role === 'system_admin')
      return (
        <Suspense fallback={<Loading />}>
          <Placement />
        </Suspense>
      );
    if (page === 'database-access' && user.role === 'system_admin')
      return (
        <Suspense fallback={<Loading />}>
          <DatabaseAccess />
        </Suspense>
      );
    if (page === 'audit-log') return <AuditPage />;
    if (page === 'health') return <Health />;
    if (page === provisioningNavigation.key)
      return (
        <Suspense fallback={<Loading />}>
          <DatabaseServers />
        </Suspense>
      );
  }
  if (['overview', 'reservations', 'table-plan'].includes(page))
    return (
      <Suspense fallback={<Loading />}>
        <Reservations tenant={tenant} mode={page} go={go} user={user} />
      </Suspense>
    );
  if (page === 'module-shop' && user.role === 'restaurant_admin')
    return (
      <Suspense fallback={<Loading />}>
        <ModuleShop tenant={tenant} />
      </Suspense>
    );
  if (page === 'reporting' && user.enabled_modules?.includes('reporting'))
    return (
      <Suspense fallback={<Loading />}>
        <Reporting tenant={tenant} canManage={Boolean(allowed(user, 'reporting.manage'))} />
      </Suspense>
    );
  if (page === 'widget')
    return (
      <Suspense fallback={<Loading />}>
        <Widget tenant={tenant} />
      </Suspense>
    );
  if (page === 'team') return <UsersPage tenant={tenant} team user={user} />;
  if (page === 'profile') return <Profile tenant={tenant} />;
  return (
    <Suspense fallback={<Loading />}>
      <RestaurantResource resource={page} tenant={tenant} />
    </Suspense>
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
function Tenants({ user }: { user: Row }) {
  const q = useData('v1/admin/tenants');
  const servers = useQuery({
    queryKey: ['tenant-create-servers'],
    queryFn: () => api('v1/admin/database-servers'),
    enabled: user.role === 'system_admin',
  });
  const qc = useQueryClient();
  const [form, setForm] = useState<Row | null>(null);
  const [demo, setDemo] = useState(false);
  const [demoNotice, setDemoNotice] = useState('');
  const [search, setSearch] = useState('');
  const [error, setError] = useState<unknown>();
  useEffect(() => {
    const timer = setInterval(() => q.refetch(), 10000);
    return () => clearInterval(timer);
  }, []);
  const fields: Field[] = [
    ...(!form?.id
      ? [
          {
            key: 'server_id',
            label: 'Datenbankserver',
            type: 'select' as const,
            options: [
              { value: '', label: 'Lokaler Server' },
              ...(servers.data?.servers || [])
                .filter((s: Row) => s.provisioning_enabled)
                .map((s: Row) => ({ value: String(s.id), label: s.name })),
            ],
          },
        ]
      : []),
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
        <button className="primary" disabled={user.role !== 'system_admin'} onClick={() => setForm({})}>
          <Plus size={16} />
          Mandant anlegen
        </button>
        <button disabled={user.role !== 'system_admin'} onClick={() => setDemo(true)}>
          Testrestaurant einrichten
        </button>
      </div>
      <ErrorBox error={error} />
      {demoNotice && (
        <p className="notice">
          {demoNotice} <a href="/restaurant/login">Restaurant-Login öffnen</a>
        </p>
      )}
      {demo && (
        <Modal title="Testrestaurant einrichten" close={() => setDemo(false)}>
          <p>
            Erstellt einen eigenen Restaurantzugang, einen Testraum, drei Tische und tägliche Öffnungszeiten
            von 10 bis 23 Uhr.
          </p>
          <Form
            fields={[
              { key: 'name', label: 'Restaurantname', default: 'Mein Testrestaurant', required: true },
              { key: 'owner_name', label: 'Name des Restaurant-Administrators', required: true },
              { key: 'email', label: 'Login-E-Mail', type: 'email', required: true },
              {
                key: 'password',
                label: 'Login-Passwort (mindestens 12 Zeichen)',
                type: 'password',
                required: true,
              },
              {
                key: 'password_confirmation',
                label: 'Passwort wiederholen',
                type: 'password',
                required: true,
              },
            ]}
            label="Testrestaurant erstellen"
            onSave={async (data) => {
              await api('v1/admin/test-restaurant', 'POST', data);
              setDemo(false);
              setDemoNotice(
                'Testrestaurant wird eingerichtet. Sobald der Status Aktiv ist, kannst du dich mit deiner Login-E-Mail und deinem gewählten Passwort anmelden.',
              );
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
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
            actions={(r) =>
              user.role !== 'system_admin' ? null : (
                <>
                  <button onClick={() => setForm(r)}>Bearbeiten</button>
                  {r.status === 'failed' ? (
                    <button onClick={() => action(r, '/retry', 'POST')}>Erneut einrichten</button>
                  ) : (
                    ['active', 'blocked'].includes(r.status) && (
                      <button
                        onClick={() => {
                          if (
                            confirm(r.status === 'active' ? 'Restaurant sperren?' : 'Restaurant freigeben?')
                          )
                            action(r, '', 'PATCH', { status: r.status === 'active' ? 'blocked' : 'active' });
                        }}
                      >
                        {r.status === 'active' ? 'Sperren' : 'Freigeben'}
                      </button>
                    )
                  )}
                </>
              )
            }
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
