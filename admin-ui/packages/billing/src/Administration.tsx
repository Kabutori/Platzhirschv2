import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
export default function Administration() {
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['billing-admin'], queryFn: () => api('v1/admin/billing') });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  async function save(path: string, method: string, data: unknown) {
    setBusy(true);
    setError('');
    try {
      await api('v1/admin/billing/' + path, method, data);
      await qc.invalidateQueries();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <>
      <p className="eyebrow">ADMINISTRATION · MODULE</p>
      <h2>Angebote & Bestellungen</h2>
      <p>
        Preise gelten für neue Aufträge. Eine Zahlung erst nach Prüfung im externen Zahlungssystem bestätigen.
        Die Freigabe installiert noch keine Mandantentabellen; das Restaurant aktiviert sein Modul
        anschließend selbst.
      </p>
      {error && <p role="alert">{error}</p>}
      {q.error && <p role="alert">{q.error.message}</p>}
      <section className="panel padded">
        <h3>Modulangebote</h3>
        {q.data?.products.map((p: any) => (
          <form
            className="fields"
            key={p.module_code}
            onSubmit={(e) => {
              e.preventDefault();
              const f = new FormData(e.currentTarget);
              void save('products/' + p.module_code, 'PATCH', {
                amount_cents: Math.round(Number(f.get('price')) * 100),
                available: f.get('available') === 'on',
              });
            }}
          >
            <strong>{p.module_code}</strong>
            <label>
              Monatspreis in EUR
              <input
                name="price"
                aria-label={'Monatspreis ' + p.module_code}
                type="number"
                step="0.01"
                min="0"
                max="10000"
                required
                defaultValue={p.amount_cents == null ? '' : p.amount_cents / 100}
              />
            </label>
            <label>
              <input name="available" type="checkbox" defaultChecked={Boolean(p.available)} /> Im Shop
              anbieten
            </label>
            <button disabled={busy} type="submit">
              Angebot speichern
            </button>
          </form>
        ))}
      </section>
      <section className="panel padded">
        <h3>Aufträge</h3>
        {q.data?.orders.length === 0 && <p>Noch keine Bestellungen.</p>}
        {q.data?.orders.map((o: any) => (
          <div key={o.id} className="panel padded">
            <strong>
              #{o.id} · Restaurant #{o.tenant_id} · {o.module_code}
            </strong>
            <p>
              {(o.amount_cents / 100).toFixed(2)} {o.currency} ·{' '}
              {o.status === 'paid' ? 'Zahlung bestätigt' : 'Zahlung ausstehend'}
            </p>
            {o.status === 'pending' && (
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  const f = new FormData(e.currentTarget);
                  void save('orders/' + o.id + '/confirm', 'POST', {
                    payment_reference: f.get('reference'),
                    payment_confirmed: true,
                  });
                }}
              >
                <label>
                  Zahlungsreferenz
                  <input name="reference" required maxLength={120} />
                </label>
                <label>
                  <input type="checkbox" required /> Eingang des vollständigen Betrags extern geprüft
                </label>
                <button disabled={busy}>Zahlung bestätigen</button>
              </form>
            )}
          </div>
        ))}
      </section>
    </>
  );
}
