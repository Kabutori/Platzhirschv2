import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
const money = (n: number) =>
  new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(n / 100);
const states: Record<string, string> = {
  checkout: 'Checkout offen',
  active: 'Aktiv',
  past_due: 'Zahlung ausstehend',
  unpaid: 'Unbezahlt',
  canceled: 'Beendet',
  expired: 'Checkout abgelaufen',
  incomplete: 'Zahlung unvollständig',
  incomplete_expired: 'Abgelaufen',
};
export default function Automation({ admin, tenant }: { admin: boolean; tenant?: string }) {
  const [page, setPage] = useState(1),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(''),
    [notice, setNotice] = useState('');
  const base = `v1/${admin ? 'admin' : 'restaurant'}/billing/automation`,
    qc = useQueryClient();
  const q = useQuery({
    queryKey: ['billing-automation', admin, tenant, page],
    queryFn: () => api(base + '?page=' + page, 'GET', undefined, tenant),
  });
  async function action(path: string, data: any = {}, method = 'POST') {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      const result = await api(base + path, method, data, tenant);
      if (result.url) {
        const u = new URL(result.url);
        if (u.protocol !== 'https:' || !['checkout.stripe.com', 'billing.stripe.com'].includes(u.hostname))
          throw new Error('Ungültige Zahlungsadresse.');
        window.location.assign(u.href);
        return;
      }
      await qc.invalidateQueries();
      setNotice(
        result.status === 'queued'
          ? 'Auftrag vorgemerkt. Der Hintergrunddienst übernimmt die Verarbeitung.'
          : 'Gespeichert.',
      );
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  const d = q.data;
  return (
    <section className="billing-automation">
      <h3>{admin ? 'Automatische Abrechnung & Versand' : 'Abonnements & Zahlungen'}</h3>
      {error && <p role="alert">{error}</p>}
      {notice && <p role="status">{notice}</p>}
      {q.error && <p role="alert">{q.error.message}</p>}
      {!d ? (
        <p role="status">Abrechnung wird geladen …</p>
      ) : (
        <>
          <p>
            {d.settings.live ? 'Echtbetrieb' : 'Testbetrieb'} ·{' '}
            {d.settings.enabled ? 'Stripe-Zahlungen aktiviert' : 'Automatische Zahlungen deaktiviert'}
          </p>
          {admin ? (
            <details className="panel padded">
              <summary>Zahlungsanbieter und Versand einrichten</summary>
              <form
                className="fields"
                key={d.settings.revision}
                onSubmit={(e) => {
                  e.preventDefault();
                  const f = new FormData(e.currentTarget);
                  void action(
                    '',
                    {
                      enabled: f.has('enabled'),
                      live: f.has('live'),
                      send_invoices: f.has('send_invoices'),
                      send_reminders: f.has('send_reminders'),
                      reminder_days: Number(f.get('reminder_days')),
                      api_key: f.get('api_key'),
                      webhook_secret: f.get('webhook_secret'),
                      password: f.get('password'),
                      confirmed: f.has('confirmed'),
                      revision: d.settings.revision,
                    },
                    'PUT',
                  );
                }}
              >
                <p>
                  Monatliche Zahlungen über Stripe Checkout. Zahlungsdaten verbleiben beim Anbieter.
                  Bestehende Abonnements laufen beim Anbieter weiter, auch wenn diese Anbindung deaktiviert
                  wird.
                </p>
                <label>
                  <input type="checkbox" name="enabled" defaultChecked={d.settings.enabled} /> Stripe
                  aktivieren
                </label>
                <label>
                  <input type="checkbox" name="live" defaultChecked={d.settings.live} /> Echte Zahlungen statt
                  Testbetrieb
                </label>
                <label>
                  Stripe API-Schlüssel
                  <input name="api_key" type="password" autoComplete="new-password" />
                </label>
                <label>
                  Webhook-Signaturschlüssel
                  <input name="webhook_secret" type="password" autoComplete="new-password" />
                </label>
                <p>
                  {d.settings.configured
                    ? 'Zugangsdaten gespeichert. Leere Felder behalten vorhandene Werte.'
                    : 'Noch keine Zugangsdaten gespeichert.'}
                </p>
                <p>
                  Webhook: <code>{window.location.origin}/api/v1/billing/stripe/webhook</code> · API-Version
                  2024-06-20.
                </p>
                <label>
                  <input type="checkbox" name="send_invoices" defaultChecked={d.settings.send_invoices} />{' '}
                  Ausgestellte Rechnungen automatisch per E-Mail senden
                </label>
                <label>
                  <input type="checkbox" name="send_reminders" defaultChecked={d.settings.send_reminders} />{' '}
                  Offene Zahlungen per E-Mail anmahnen
                </label>
                <label>
                  Abstand zwischen Mahnungen (Tage)
                  <input
                    name="reminder_days"
                    type="number"
                    min="3"
                    max="60"
                    defaultValue={d.settings.reminder_days}
                  />
                </label>
                <p>
                  Höchstens drei Erinnerungen je Rechnung, ohne Mahngebühren. Versand über die gemeinsamen
                  SMTP-Einstellungen. Belege werden als druckbare HTML-Datei angehängt.
                </p>
                <label>
                  Administratorkennwort
                  <input type="password" name="password" autoComplete="current-password" required />
                </label>
                <label>
                  <input type="checkbox" name="confirmed" required /> Anbieter, Aussteller und automatische
                  Verarbeitung geprüft
                </label>
                <button disabled={busy}>Abrechnungseinstellungen speichern</button>
              </form>
            </details>
          ) : (
            d.settings.enabled && (
              <section className="panel padded">
                <h4>Monatliches Abonnement abschließen</h4>
                <p>
                  Monatliche Verlängerung zum bestätigten Gesamtpreis. Kündigung zum Laufzeitende ist unten
                  möglich. Bereits bezahlte manuelle Zeiträume müssen zuerst enden.
                </p>
                {d.products.map((p: any) => (
                  <form
                    key={p.module_code}
                    className="panel padded"
                    onSubmit={(e) => {
                      e.preventDefault();
                      void action('/checkout', {
                        module_code: p.module_code,
                        expected_amount_cents: p.amount_cents,
                        confirmed: true,
                      });
                    }}
                  >
                    <h4>
                      {p.module_code} · {money(p.amount_cents)} / Monat
                    </h4>
                    <label>
                      <input type="checkbox" required /> Monatliche wiederkehrende Zahlung zum angezeigten
                      Gesamtpreis bestätigen
                    </label>
                    <button disabled={busy}>
                      {d.settings.live ? 'Zahlungspflichtiges Abonnement starten' : 'Test-Abonnement starten'}
                    </button>
                  </form>
                ))}
              </section>
            )
          )}
          <h4>Abonnements</h4>
          {!d.subscriptions.data.length && <p>Noch keine Abonnements.</p>}
          {d.subscriptions.data.map((s: any) => (
            <article className="panel padded" key={s.id}>
              <h4>
                {s.module_code} · {money(s.amount_cents)} / Monat
              </h4>
              <p>
                {admin ? `Restaurant #${s.tenant_id} · ` : ''}
                {states[s.state] ?? s.state} · {s.live ? 'Echtbetrieb' : 'Testbetrieb'}
              </p>
              <p>
                {s.cancel_at_period_end ? 'Gekündigt zum' : 'Laufzeitende'}:{' '}
                {s.period_end
                  ? new Date(s.period_end.replace(' ', 'T')).toLocaleString('de-DE')
                  : 'Noch nicht begonnen'}
              </p>
              {s.sync_error && <p role="alert">{s.sync_error}</p>}
              {!admin && (
                <div className="toolbar">
                  {!['checkout', 'expired'].includes(s.state) && (
                    <button disabled={busy} onClick={() => void action(`/subscriptions/${s.id}/portal`)}>
                      Zahlungsmethode verwalten
                    </button>
                  )}
                  {!s.cancel_at_period_end &&
                    !['canceled', 'expired', 'incomplete_expired'].includes(s.state) && (
                      <form
                        onSubmit={(e) => {
                          e.preventDefault();
                          void action(`/subscriptions/${s.id}/cancel`, { confirmed: true });
                        }}
                      >
                        <label>
                          <input type="checkbox" required />{' '}
                          {s.state === 'checkout'
                            ? 'Checkout beenden'
                            : 'Kündigung zum Laufzeitende bestätigen'}
                        </label>
                        <button disabled={busy}>
                          {s.state === 'checkout' ? 'Checkout abbrechen' : 'Abonnement kündigen'}
                        </button>
                      </form>
                    )}
                </div>
              )}
            </article>
          ))}
          <div className="toolbar">
            <button disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Vorherige Abonnements
            </button>
            <span>
              Seite {page} / {d.subscriptions.last_page}
            </span>
            <button disabled={page >= d.subscriptions.last_page} onClick={() => setPage(page + 1)}>
              Weitere Abonnements
            </button>
          </div>
          {admin && (
            <section className="panel padded">
              <h4>Rechnungslauf & Versandprotokoll</h4>
              <p>
                Stündlicher Zahlungsabgleich. „An SMTP übergeben“ bestätigt die Annahme durch den Mailserver,
                nicht den Eingang im Postfach.
              </p>
              <div className="toolbar">
                <button disabled={busy} onClick={() => void action('/run')}>
                  Rechnungslauf starten
                </button>
                <button disabled={busy} onClick={() => void q.refetch()}>
                  Versandstatus aktualisieren
                </button>
              </div>
              {!d.deliveries.length && <p>Noch keine Versandaufträge.</p>}
              {d.deliveries.map((v: any) => (
                <article key={v.id}>
                  <h4>
                    Beleg #{v.invoice_id} · {v.kind === 'reminder' ? `Mahnung ${v.level}` : 'Rechnung'}
                  </h4>
                  <p>
                    {(
                      {
                        pending: 'Vorgemerkt',
                        retry: 'Erneut vorgemerkt',
                        sending: 'Versand läuft',
                        sent: 'An SMTP übergeben',
                        uncertain: 'Zustellung unklar',
                        skipped: 'Nicht mehr erforderlich',
                      } as Record<string, string>
                    )[v.status] ?? v.status}{' '}
                    · Versuche: {v.attempts}
                  </p>
                  {v.error && <p role="alert">{v.error}</p>}
                  {['pending', 'retry', 'uncertain'].includes(v.status) && (
                    <form
                      onSubmit={(e) => {
                        e.preventDefault();
                        void action(`/deliveries/${v.id}/retry`, { confirmed: true });
                      }}
                    >
                      <label>
                        <input type="checkbox" required /> Zustellung geprüft; erneuten Versuch freigeben
                      </label>
                      <button disabled={busy}>Versand erneut versuchen</button>
                    </form>
                  )}
                </article>
              ))}
            </section>
          )}
        </>
      )}
    </section>
  );
}
