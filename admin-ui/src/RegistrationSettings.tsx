import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
export default function RegistrationSettings() {
  const q = useQuery({
    queryKey: ['registration-settings'],
    queryFn: () => api('v1/admin/registration-settings'),
  });
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(''),
    [notice, setNotice] = useState('');
  const s = q.data;
  return (
    <section className="panel padded">
      <h2>Öffentliche Registrierung</h2>
      {q.error && <p role="alert">{q.error.message}</p>}
      {error && <p role="alert">{error}</p>}
      {notice && <p role="status">{notice}</p>}
      {s && (
        <>
          <p>
            HTTPS: {s.https_ready ? 'Bereit' : 'Anwendungsadresse noch nicht eingerichtet'} · SMTP:{' '}
            {s.smtp_ready ? 'Aktiv' : 'Noch nicht aktiv'} · Registrierung:{' '}
            {s.available ? 'Freigeschaltet' : 'Gesperrt'}
          </p>
          <p>
            Website und E-Mail müssen zur selben Domain gehören. Restaurant-/Hotelbegriffe werden geprüft; ein
            Konto entsteht erst nach Bestätigung der E-Mail-Adresse.
          </p>
          <form
            className="fields"
            key={s.revision}
            onSubmit={async (e) => {
              e.preventDefault();
              const f = new FormData(e.currentTarget);
              setBusy(true);
              setError('');
              setNotice('');
              try {
                await api('v1/admin/registration-settings', 'PUT', {
                  enabled: f.has('enabled'),
                  privacy_url: f.get('privacy_url'),
                  imprint_url: f.get('imprint_url'),
                  revision: s.revision,
                });
                await q.refetch();
                setNotice('Registrierungseinstellungen gespeichert.');
              } catch (e) {
                setError((e as Error).message);
              } finally {
                setBusy(false);
              }
            }}
          >
            <label>
              Datenschutz-URL
              <input type="url" name="privacy_url" required defaultValue={s.privacy_url} />
            </label>
            <label>
              Impressum-URL
              <input type="url" name="imprint_url" required defaultValue={s.imprint_url} />
            </label>
            <label>
              <input type="checkbox" name="enabled" defaultChecked={s.enabled} /> Neue Registrierungen
              zulassen
            </label>
            <button disabled={busy}>Registrierung speichern</button>
          </form>
          <a href="/registrierung" target="_blank" rel="noreferrer">
            Registrierungsseite öffnen
          </a>
        </>
      )}
    </section>
  );
}
