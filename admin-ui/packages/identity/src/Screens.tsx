import { useState, useEffect, type FormEvent } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
  Building2,
  Users,
  ShieldCheck,
  Settings,
  Plus,
  Search,
  RefreshCw,
  ArrowLeft,
  Download,
  Check,
  AlertCircle,
  ChevronRight,
} from 'lucide-react';
import { api, ApiError, portal } from '@platzhirsch/ui-runtime/api';
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
function allowed(user: Row, permission: string) {
  return user.permissions?.includes('*') || user.permissions?.includes(permission);
}
export function Login({ onLogin, setup = false }: { onLogin: () => void; setup?: boolean }) {
  const [mfa, setMfa] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
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
    <div className={`auth-page portal-${portal}`}>
      <div className="auth-wrap">
        <div className="brand large">
          <b>P</b>
          <div>
            <h1>{portal === 'administration' ? 'Platzhirsch Plattform' : 'Platzhirsch'}</h1>
            <small>
              {setup
                ? 'ERSTEINRICHTUNG'
                : portal === 'administration'
                  ? 'SYSTEM-ADMINISTRATION'
                  : 'RESERVIERUNGSSYSTEM FÜR RESTAURANTS'}
            </small>
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
                      aria-label="Passwort"
                      type={showPassword ? 'text' : 'password'}
                      required
                      autoComplete="current-password"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                    />
                    <button
                      type="button"
                      className="text"
                      aria-pressed={showPassword}
                      onClick={() => setShowPassword(!showPassword)}
                    >
                      {showPassword ? 'Passwort verbergen' : 'Passwort anzeigen'}
                    </button>
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
        <a
          className="text"
          href={portal === 'administration' ? '/restaurant/login' : '/administration/login'}
        >
          {portal === 'administration' ? 'Zum Restaurantportal' : 'Zur Administration'}
        </a>
        <p className="security">
          <span />
          Zugriff wird protokolliert · Geschützte Verbindung empfohlen
        </p>
      </div>
    </div>
  );
}
export function ResetPassword() {
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
export function UsersPage({ tenant, team = false, user }: { tenant?: string; team?: boolean; user: Row }) {
  const path = team ? 'v1/restaurant/team' : 'v1/admin/users';
  const q = useData(path, tenant);
  const roleOptions = useQuery({
    queryKey: ['team-roles', tenant],
    queryFn: () => api('v1/restaurant/roles', 'GET', undefined, tenant),
    enabled: team,
  });
  const platformRoles = useQuery({
    queryKey: ['platform-roles'],
    queryFn: () => api('v1/admin/platform-roles'),
    enabled: !team && user.role === 'system_admin',
  });
  const [editing, setEditing] = useState<Row | null>(null);
  const tenants = useQuery({
    queryKey: ['user-tenants'],
    queryFn: () => api('v1/admin/tenants'),
    enabled: !team && user.role === 'system_admin',
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
      options: pick(
        team
          ? ['restaurant_admin', 'staff']
          : ['system_admin', 'platform_staff', 'restaurant_admin', 'staff'],
      ),
    },
    ...(!team
      ? [
          {
            key: 'platform_role_id',
            label: 'Plattformrolle (für Plattform-Mitarbeiter)',
            options:
              platformRoles.data?.roles
                ?.filter((r: Row) => !r.locked && r.activated_at)
                .map((r: Row) => ({ value: r.id, label: r.name })) || [],
          },
        ]
      : []),
    ...(team
      ? [
          {
            key: 'restaurant_role_id',
            label: 'Eigene Mitarbeiterrolle (leer: Standard)',
            options: roleOptions.data?.roles?.map((r: Row) => ({ value: r.id, label: r.name })) || [],
          },
        ]
      : []),
    ...(!team
      ? [
          {
            key: 'tenant_id',
            label: 'Restaurant (leer für Plattformkonten)',
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
        <button
          className="primary"
          disabled={!team && user.role !== 'system_admin'}
          onClick={() => setOpen(true)}
        >
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
              {
                key: 'role',
                label: 'Rolle',
                render: (r) =>
                  roleOptions.data?.roles?.find((role: Row) => role.id === r.restaurant_role_id)?.name ||
                  labels[r.role],
              },
              { key: 'tenant_id', label: 'Restaurant-ID' },
              { key: 'active', label: 'Status', render: (r) => <Badge value={r.active} /> },
            ]}
            actions={
              !team
                ? (r) =>
                    user.role !== 'system_admin' ? null : (
                      <>
                        <button onClick={() => setEditing(r)}>Bearbeiten</button>
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
                : (r) => <button onClick={() => setEditing(r)}>Bearbeiten</button>
            }
          />
        )}
      </section>
      {editing && (
        <Modal
          title={team ? 'Teammitglied bearbeiten' : 'Benutzer bearbeiten'}
          close={() => setEditing(null)}
        >
          <Form
            fields={[
              ...fields.filter((f) =>
                team
                  ? !['email', 'password'].includes(f.key)
                  : f.key === 'name' || (f.key === 'platform_role_id' && editing.role === 'platform_staff'),
              ),
              { key: 'active', label: 'Zugang aktiv', type: 'checkbox' },
            ]}
            initial={editing}
            onSave={async (data) => {
              await api(
                path + '/' + editing.id,
                'PATCH',
                {
                  ...data,
                  ...(team
                    ? { restaurant_role_id: data.restaurant_role_id ? Number(data.restaurant_role_id) : null }
                    : editing.role === 'platform_staff'
                      ? {
                          platform_role_id: Number(data.platform_role_id),
                          expected_platform_role_id: editing.platform_role_id,
                        }
                      : {}),
                },
                tenant,
              );
              setEditing(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
      {open && (
        <Modal title="Benutzer anlegen" close={() => setOpen(false)}>
          <Form
            fields={fields}
            onSave={async (data) => {
              await api(
                path,
                'POST',
                {
                  ...data,
                  tenant_id: data.tenant_id || null,
                  platform_role_id: data.platform_role_id ? Number(data.platform_role_id) : null,
                  restaurant_role_id: data.restaurant_role_id ? Number(data.restaurant_role_id) : null,
                },
                tenant,
              );
              setOpen(false);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
    </>
  );
}
export function RestaurantRoles({ tenant }: { tenant?: string }) {
  const q = useData('v1/restaurant/roles', tenant);
  const qc = useQueryClient();
  const [form, setForm] = useState<Row | null>(null);
  const [error, setError] = useState<unknown>();
  return (
    <>
      <div className="toolbar">
        <p className="muted">
          Eigene Mitarbeiterrollen gelten nur für dieses Restaurant. Administratoren verwalten weiterhin Team
          und Rollen.
        </p>
        <button className="primary" onClick={() => setForm({})}>
          Rolle anlegen
        </button>
      </div>
      <ErrorBox error={error} />
      {q.isPending ? (
        <Loading />
      ) : q.error ? (
        <ErrorBox error={q.error} />
      ) : (
        <section className="panel">
          <DataTable
            rows={q.data.roles}
            columns={[
              { key: 'name', label: 'Rolle' },
              {
                key: 'permissions',
                label: 'Berechtigungen',
                render: (r) =>
                  r.permissions.map((permission: string) => q.data.catalog[permission]).join(', ') ||
                  'Kein Zugriff',
              },
            ]}
            actions={(r) => (
              <>
                <button onClick={() => setForm(r)}>Bearbeiten</button>
                <button
                  onClick={async () => {
                    if (!confirm('Unbenutzte Rolle löschen?')) return;
                    try {
                      await api('v1/restaurant/roles/' + r.id, 'DELETE', undefined, tenant);
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
        </section>
      )}
      {form && q.data && (
        <Modal title={form.id ? 'Rolle bearbeiten' : 'Rolle anlegen'} close={() => setForm(null)}>
          <Form
            fields={[
              { key: 'name', label: 'Rollenname', required: true },
              ...Object.entries(q.data.catalog).map(([key, label]) => ({
                key,
                label: String(label),
                type: 'checkbox',
              })),
            ]}
            initial={{
              name: form.name || '',
              ...Object.fromEntries((form.permissions || []).map((key: string) => [key, true])),
            }}
            onSave={async (data) => {
              await api(
                'v1/restaurant/roles' + (form.id ? '/' + form.id : ''),
                form.id ? 'PATCH' : 'POST',
                {
                  name: data.name,
                  version: form.version,
                  permissions: Object.keys(q.data.catalog).filter((key) => data[key]),
                },
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
export function Account({ user }: { user: Row }) {
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
