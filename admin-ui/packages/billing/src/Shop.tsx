import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
export default function Shop({ tenant }: { tenant?: string }) {
  const q = useQueryClient();
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
      setNotice(
        path === 'orders'
          ? 'Bestellung erfasst. Die Administration bestätigt die externe Zahlung.'
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
      <p className="eyebrow">RESTAURANT · ERWEITERUNGEN</p>
      <h2>Modul-Shop</h2>
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
      <div className="module-grid">
        {data.data?.products.map((p: any) => {
          const entitlement = data.data.entitlements.find((e: any) => e.module_code === p.module_code);
          const pending = data.data.orders.some(
            (o: any) => o.module_code === p.module_code && o.status === 'pending',
          );
          const usable = entitlement && new Date(entitlement.paid_until) > new Date();
          return (
            <section className="panel" key={p.module_code}>
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
                Status: {entitlement?.status || 'Nicht gebucht'}
                {usable
                  ? ' · gültig bis ' + new Date(entitlement.paid_until).toLocaleDateString('de-DE')
                  : ''}
              </p>
              <div className="actions">
                <button
                  disabled={busy || !p.available || p.amount_cents == null || pending}
                  onClick={() => {
                    if (confirm('Kostenpflichtige Bestellung zum angezeigten Monatspreis senden?'))
                      void action(p.module_code, 'orders', {
                        module_code: p.module_code,
                        request_key: crypto.randomUUID(),
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
                  disabled={busy || !usable || entitlement.status === 'activating'}
                  onClick={() =>
                    void action(p.module_code, p.module_code + '/activation', {
                      enabled: entitlement.status !== 'active',
                    })
                  }
                >
                  {entitlement?.status === 'active' ? 'Deaktivieren' : 'Aktivieren'}
                </button>
              </div>
            </section>
          );
        })}
      </div>
      <section className="panel">
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
