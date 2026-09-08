import { useState, useRef } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
export default function Shop({ tenant }: { tenant?: string }) {
  const q = useQueryClient();
  const requestKeys = useRef<Record<string, string>>({});
  const data = useQuery({
    queryKey: ['module-shop', tenant],
    queryFn: () => api('v1/restaurant/modules', 'GET', undefined, tenant),
    refetchInterval: 10000,
  });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  async function action(code: string, path: string, body: unknown) {
    setBusy(true);
    setError('');
    try {
      await api('v1/restaurant/modules/' + path, 'POST', body, tenant);
      if (path === 'orders') delete requestKeys.current[code];
      setNotice(
        path === 'orders'
          ? 'Bestellung erfasst. Die Administration bestätigt die externe Zahlung.'
          : (body as { enabled?: boolean }).enabled === false
            ? 'Modul deaktiviert. Vorhandene Daten bleiben erhalten.'
            : 'Modulstatus wird aktualisiert. Während der Migration ist das Restaurant kurzzeitig nicht verfügbar.',
      );
      await q.invalidateQueries();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <>
      <h2>Module für dein Restaurant</h2>
      <p>
        Installierte Erweiterungen für dein Restaurant freischalten. Ein bestätigter Auftrag umfasst einen
        Monat; es erfolgt keine automatische Abbuchung oder Verlängerung.
      </p>
      {error && (
        <p role="alert" className="notice">
          {error}
        </p>
      )}
      {notice && (
        <p role="status" className="notice">
          {notice}
        </p>
      )}
      {data.error && <p role="alert">{data.error.message}</p>}
      <h3>Basismodule</h3>
      <div className="module-cards shop-grid">
        {data.data?.core_modules?.map((code: string) => (
          <section className="panel padded module-card" key={code}>
            <div className="toolbar">
              <strong>
                {(
                  {
                    identity: 'Benutzer & Rechte',
                    customer: 'Restaurantprofil',
                    reservation: 'Reservierungen',
                    widget: 'Buchungswidget',
                    support: 'Support',
                  } as Record<string, string>
                )[code] || code}
              </strong>
              <span className="badge active">Aktiv</span>
            </div>
            <p className="muted">Im Basispaket enthalten</p>
          </section>
        ))}
      </div>
      <h3>Erweiterungen</h3>
      <div className="module-cards shop-grid">
        {data.data?.products.map((p: any) => {
          const entitlement = data.data.entitlements.find((e: any) => e.module_code === p.module_code);
          const pending = data.data.orders.some(
            (o: any) => o.module_code === p.module_code && o.status === 'pending',
          );
          const usable = entitlement && new Date(entitlement.paid_until) > new Date();
          return (
            <section className="panel padded module-card" key={p.module_code}>
              <p className="eyebrow">ERWEITERUNG</p>
              <h3>{p.module_code === 'reporting' ? 'Erweiterte Auswertungen' : p.module_code}</h3>
              <p>Zeiträume auswerten und Berichte speichern. Rollenrechte begrenzen den Zugriff.</p>
              <strong>
                {p.amount_cents == null
                  ? 'Noch kein Angebot'
                  : new Intl.NumberFormat('de-DE', { style: 'currency', currency: p.currency }).format(
                      p.amount_cents / 100,
                    ) + ' / Monat'}
              </strong>
              <p>
                Status:{' '}
                {(
                  {
                    active: 'Gekauft & aktiviert',
                    inactive: 'Gekauft · nicht aktiv',
                    activating: 'Wird aktiviert',
                    error: 'Aktivierung fehlgeschlagen',
                  } as Record<string, string>
                )[entitlement?.status] || 'Nicht gekauft'}
                {usable
                  ? ' · gültig bis ' + new Date(entitlement.paid_until).toLocaleDateString('de-DE')
                  : ''}
              </p>
              <div className="toolbar">
                <button
                  disabled={busy || !p.available || p.amount_cents == null || pending}
                  onClick={() => {
                    if (confirm('Kostenpflichtige Bestellung zum angezeigten Monatspreis senden?'))
                      void action(p.module_code, 'orders', {
                        module_code: p.module_code,
                        request_key: (requestKeys.current[p.module_code] ||= crypto.randomUUID()),
                        expected_amount_cents: p.amount_cents,
                      });
                  }}
                >
                  {pending
                    ? 'Bestätigung ausstehend'
                    : usable
                      ? 'Um einen Monat verlängern'
                      : 'Zahlungspflichtig bestellen'}
                </button>
                <button
                  className="rights-switch"
                  role="switch"
                  aria-label="Erweiterte Auswertungen aktivieren"
                  aria-checked={entitlement?.status === 'active' && Boolean(usable)}
                  disabled={busy || !usable || entitlement.status === 'activating'}
                  onClick={() =>
                    void action(p.module_code, p.module_code + '/activation', {
                      enabled: entitlement.status !== 'active',
                    })
                  }
                >
                  <span aria-hidden="true" />
                </button>
              </div>
            </section>
          );
        })}
      </div>
      <section className="panel padded">
        <h3>Bestellungen</h3>
        <div className="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Auftrag</th>
                <th>Modul</th>
                <th>Betrag</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {data.data?.orders.map((o: any) => (
                <tr key={o.id}>
                  <td>#{o.id}</td>
                  <td>{o.module_code}</td>
                  <td>
                    {(o.amount_cents / 100).toFixed(2)} {o.currency}
                  </td>
                  <td>{o.status === 'paid' ? 'Zahlung bestätigt' : 'Zahlung ausstehend'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </>
  );
}
