import { useState, useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Search } from 'lucide-react';
import { api } from '@platzhirsch/ui-runtime/api';
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
export function Tenants({ user }: { user: Row }) {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const q = useData('v1/admin/tenants?page=' + page + '&search=' + encodeURIComponent(search));
  const servers = useQuery({
    queryKey: ['tenant-create-servers'],
    queryFn: () => api('v1/admin/database-servers'),
    enabled: user.role === 'system_admin',
  });
  const qc = useQueryClient();
  const [form, setForm] = useState<Row | null>(null);
  const [demo, setDemo] = useState(false);
  const [demoNotice, setDemoNotice] = useState('');
  const [error, setError] = useState<unknown>();
  useEffect(() => {
    const timer = setInterval(() => q.refetch(), 10000);
    return () => clearInterval(timer);
  }, [q.refetch]);
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
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
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
      <nav className="toolbar" aria-label="Mandantenseiten">
        <button disabled={page <= 1 || q.isPending} onClick={() => setPage(page - 1)}>
          Vorherige Seite
        </button>
        <span>
          Seite {page} von {q.data?.last_page || 1} · {q.data?.total ?? q.data?.data?.length ?? 0} Restaurants
        </span>
        <button disabled={page >= (q.data?.last_page || 1) || q.isPending} onClick={() => setPage(page + 1)}>
          Nächste Seite
        </button>
      </nav>
      <section className="panel">
        {q.isPending ? (
          <Loading />
        ) : q.error ? (
          <ErrorBox error={q.error} />
        ) : (
          <DataTable
            rows={q.data.data}
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
export function Profile({ tenant }: { tenant?: string }) {
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
            { key: 'cuisine', label: 'Küche' },
            {
              key: 'price_range',
              label: 'Preisklasse',
              options: [
                { value: '', label: 'Keine Angabe' },
                { value: 'budget', label: 'Günstig' },
                { value: 'moderate', label: 'Mittel' },
                { value: 'upscale', label: 'Gehoben' },
                { value: 'fine_dining', label: 'Fine Dining' },
              ],
            },
            { key: 'total_seats', label: 'Plätze gesamt (Stammdaten)', type: 'number', min: 1, max: 10000 },
            { key: 'description', label: 'Beschreibung', type: 'textarea' },
            { key: 'website', label: 'Website', type: 'url' },
            {
              key: 'logo_url',
              label: 'Logo-Adresse (HTTPS)',
              type: 'url',
              help: 'Öffentlich abrufbares Logo; wird bei Verwendung vom Browser geladen.',
            },
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
