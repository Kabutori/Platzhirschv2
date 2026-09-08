import { PreferencesProvider, PreferencesPage, usePreferences } from '@platzhirsch/ui-runtime/preferences';
const Tenants = lazy(() => import('@platzhirsch/customer-ui').then((m) => ({ default: m.Tenants })));
const Profile = lazy(() => import('@platzhirsch/customer-ui').then((m) => ({ default: m.Profile })));
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
const SystemGuide = lazy(() => import('@platzhirsch/system-guide-ui'));
import { billingManifest } from '@platzhirsch/billing-ui';
import { reportingManifest } from '@platzhirsch/reporting-ui';
const ModuleShop = lazy(billingManifest.nav[0].screen);
const Reporting = lazy(reportingManifest.nav[0].screen);
const BillingAdministration = lazy(() => import('@platzhirsch/billing-ui/Administration.tsx'));
const Placement = lazy(() => import('@platzhirsch/provisioning-ui/Placement.tsx'));
const DatabaseAccess = lazy(() => import('@platzhirsch/provisioning-ui/DatabaseAccess.tsx'));
import { identityManifest } from '@platzhirsch/identity-ui';
import ModuleCatalog from '@platzhirsch/module-host/Catalog.tsx';
const PlatformRoles = lazy(identityManifest.nav[0].screen);
import { portal } from './api';
import { Suspense, lazy } from 'react';
import { provisioningManifest } from '@platzhirsch/provisioning-ui';
import { navigationFor } from '@platzhirsch/module-host';
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
  ['system-settings', 'System-Einstellungen', Settings],
  ['audit-log', 'Audit Log', ScrollText],
  ['health', 'System', Activity],
  ['system-guide', 'System verstehen', Building2],

  ['support', 'Support', MessageSquare],
  ['preferences', 'Einstellungen', Settings],
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
  ['system-guide', 'System verstehen', Building2],
  ['support', 'Support', MessageSquare],
  ['preferences', 'Einstellungen', Settings],
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
  return (
    <PreferencesProvider key={portal + user.id} identity={portal + ':' + user.id}>
      <ShellBody user={user} />
    </PreferencesProvider>
  );
}
function ShellBody({ user }: { user: Row }) {
  const { value: preferences, save: savePreferences, error: preferenceError } = usePreferences();
  const [more, setMore] = useState(false);
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
            (key !== 'system-settings' ||
              user.role === 'system_admin' ||
              provisioningVisible ||
              allowed(user, 'platform.health.read')) &&
            (key !== 'roles' || identityVisible),
        )
      : restaurantNav.filter(
          ([key]) =>
            (key !== 'reporting' || user.enabled_modules?.includes('reporting')) &&
            (key !== 'module-shop' || user.role === 'restaurant_admin') &&
            (!pagePermission[key] || allowed(user, pagePermission[key])),
        );
  const title = nav.find(([key]) => key === page)?.[1] || 'Platzhirsch';
  function navButton(key: string, label: string, Icon: typeof Menu) {
    return (
      <button
        key={key}
        className={key === page ? 'active' : ''}
        onClick={() => {
          setPage(key);
          setMobile(false);
        }}
        title={label}
        aria-label={label}
        aria-current={key === page ? 'page' : undefined}
      >
        <Icon size={17} />
        <span className="nav-text">{label}</span>
        {key === page && <ChevronRight size={14} />}
      </button>
    );
  }
  return (
    <div className={preferences.collapsed ? 'app compact-navigation' : 'app'}>
      <aside className={mobile ? 'sidebar open' : 'sidebar'}>
        <div className="brand">
          <b>P</b>
          <div>
            <strong>Platzhirsch</strong>
            <small>{scope === 'system' ? 'Plattform-Verwaltung' : 'Restaurant-Verwaltung'}</small>
          </div>
        </div>
        <button
          className="collapse-sidebar"
          aria-label={preferences.collapsed ? 'Menü ausklappen' : 'Menü einklappen'}
          onClick={() => savePreferences({ ...preferences, collapsed: !preferences.collapsed })}
        >
          <Menu size={18} />
          <span>{preferences.collapsed ? 'Ausklappen' : 'Einklappen'}</span>
        </button>
        <a
          className="scope-switch"
          href={portal === 'administration' ? '/restaurant/login' : '/administration/login'}
        >
          {portal === 'administration' ? 'Restaurantportal öffnen' : 'Administration öffnen'}
        </a>
        <div className="nav-label">{scope === 'system' ? 'PLATTFORM-VERWALTUNG' : 'DEIN RESTAURANT'}</div>
        <nav aria-label="Hauptnavigation">
          {nav
            .filter(([key]) => !preferences.favoritesEnabled || preferences.favorites.includes(key))
            .map(([key, label, Icon]) => navButton(key, label, Icon))}
          {preferences.favoritesEnabled && nav.some(([key]) => !preferences.favorites.includes(key)) && (
            <>
              <button
                className="nav-more"
                aria-label={more ? 'Weitere Bereiche einklappen' : 'Weitere Bereiche ausklappen'}
                aria-expanded={more}
                aria-controls="remaining-navigation"
                onClick={() => setMore(!more)}
              >
                <ChevronRight size={17} style={{ transform: more ? 'rotate(90deg)' : undefined }} />
                <span className="nav-text">Weitere</span>
              </button>
              {more && (
                <div id="remaining-navigation" className="remaining-navigation">
                  {nav
                    .filter(([key]) => !preferences.favorites.includes(key))
                    .map(([key, label, Icon]) => navButton(key, label, Icon))}
                </div>
              )}
            </>
          )}
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
          <ErrorBox error={error || preferenceError} />
          {page === 'preferences' ? (
            <PreferencesPage
              items={nav}
              canExport={scope === 'restaurant' && allowed(user, 'reservation.export')}
            />
          ) : (
            <Content key={scope + page} scope={scope} page={page} user={user} go={setPage} />
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
  if (scope === 'restaurant' && pagePermission[page] && !allowed(user, pagePermission[page]))
    return <Empty>Für diesen Bereich fehlt dir die Berechtigung.</Empty>;
  if (page === 'system-guide')
    return (
      <Suspense fallback={<Loading />}>
        <SystemGuide administrator={scope === 'system' && user.role === 'system_admin'} />
      </Suspense>
    );
  if (page === 'restaurant-roles') return <RestaurantRoles tenant={tenant} />;
  if (page === 'account') return <Account user={user} />;
  if (page === 'support')
    return (
      <Suspense fallback={<Loading />}>
        <Support tenant={tenant} user={user} />
      </Suspense>
    );
  if (scope === 'system') {
    if (page === 'system-guide')
      return (
        <Suspense fallback={<Loading />}>
          <SystemGuide administrator={user.role === 'system_admin'} />
        </Suspense>
      );
    if (page === 'dashboard') return <Dashboard go={go} />;
    if (page === 'tenants')
      return (
        <Suspense fallback={<Loading />}>
          <Tenants user={user} />
        </Suspense>
      );
    if (page === 'users') return <UsersPage user={user} />;
    if (page === 'roles')
      return (
        <Suspense fallback={<Loading />}>
          <PlatformRoles />
        </Suspense>
      );
    if (page === 'modules') return <ModuleCenter user={user} />;
    if (page === 'system-settings') return <Infrastructure user={user} />;
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
  if (page === 'profile')
    return (
      <Suspense fallback={<Loading />}>
        <Profile tenant={tenant} />
      </Suspense>
    );
  return (
    <Suspense fallback={<Loading />}>
      <RestaurantResource resource={page} tenant={tenant} />
    </Suspense>
  );
}
function Infrastructure({ user }: { user: Row }) {
  const tabs = [
    ...(allowed(user, 'provisioning.servers.read') ? [['servers', 'Datenbankserver']] : []),
    ...(user.role === 'system_admin'
      ? [
          ['access', 'SQL-Zugangsdaten'],
          ['move', 'Serverzuordnung & Umzüge'],
        ]
      : []),
    ...(allowed(user, 'platform.health.read') ? [['health', 'Betriebsstatus']] : []),
  ];
  const [tab, setTab] = useState(tabs[0]?.[0]);
  if (!tabs.length) return <Empty>Keine Berechtigung für System-Einstellungen.</Empty>;
  return (
    <>
      <nav className="section-tabs" aria-label="System-Einstellungen">
        {tabs.map(([key, label]) => (
          <button key={key} aria-pressed={key === tab} onClick={() => setTab(key)}>
            {label}
          </button>
        ))}
      </nav>
      <Suspense fallback={<Loading />}>
        {tab === 'servers' ? (
          <DatabaseServers />
        ) : tab === 'access' && user.role === 'system_admin' ? (
          <DatabaseAccess />
        ) : tab === 'move' && user.role === 'system_admin' ? (
          <Placement />
        ) : tab === 'health' ? (
          <Health />
        ) : null}
      </Suspense>
    </>
  );
}
function ModuleCenter({ user }: { user: Row }) {
  const [tab, setTab] = useState('catalog');
  return (
    <>
      <nav className="section-tabs" aria-label="Modulverwaltung">
        <button aria-pressed={tab === 'catalog'} onClick={() => setTab('catalog')}>
          Modul-Katalog
        </button>
        {user.role === 'system_admin' && (
          <button aria-pressed={tab === 'billing'} onClick={() => setTab('billing')}>
            Angebote & Bestellungen
          </button>
        )}
      </nav>
      {tab === 'catalog' ? (
        <ModuleCatalog />
      ) : user.role === 'system_admin' ? (
        <Suspense fallback={<Loading />}>
          <BillingAdministration />
        </Suspense>
      ) : null}
    </>
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
