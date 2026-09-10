import { usePreferences, accentPresets } from '@platzhirsch/ui-runtime/preferences';
import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
import { Modal, Form, ErrorBox, labels, type Row } from '@platzhirsch/ui-runtime/components';
export default function ProfileMenu({ user, go }: { user: Row; go: (page: string) => void }) {
  const preferences = usePreferences();
  const qc = useQueryClient(),
    lifetime = Number(user.session_lifetime_seconds) || 3600;
  const [dialog, setDialog] = useState(''),
    [deadline, setDeadline] = useState(Date.now() + lifetime * 1000),
    [now, setNow] = useState(Date.now()),
    [busy, setBusy] = useState(false),
    [error, setError] = useState<unknown>();
  useEffect(() => {
    const touch = () => setDeadline(Date.now() + lifetime * 1000);
    const timer = window.setInterval(() => setNow(Date.now()), 1000);
    window.addEventListener('platzhirsch-session-activity', touch);
    return () => {
      clearInterval(timer);
      window.removeEventListener('platzhirsch-session-activity', touch);
    };
  }, [lifetime]);
  const seconds = Math.min(lifetime, Math.max(0, Math.ceil((deadline - now) / 1000)));
  return (
    <div className="profile-controls">
      <button className="profile-trigger" aria-label="Profilmenü öffnen" onClick={() => setDialog('menu')}>
        <span className="avatar">
          {user.name
            ?.split(/\s+/)
            .map((n: string) => n[0])
            .slice(0, 2)
            .join('')
            .toUpperCase()}
        </span>
        <span>
          <strong>{user.name}</strong>
          <small>{labels[user.role] || user.role}</small>
        </span>
      </button>
      <button
        className="session-control"
        title="Sitzung verlängern"
        aria-label="Sitzung verlängern"
        disabled={busy}
        onClick={async () => {
          setBusy(true);
          setError(undefined);
          try {
            await api('v1/admin/auth/session/extend', 'POST');
            setDeadline(Date.now() + lifetime * 1000);
          } catch (e) {
            setError(e);
          } finally {
            setBusy(false);
          }
        }}
      >
        <span className="session-track">
          <span style={{ width: (100 * seconds) / lifetime + '%' }} />
        </span>
        <small>
          {seconds
            ? `Sitzung · ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
            : 'Sitzung prüfen'}
        </small>
      </button>
      <ErrorBox error={error} />
      {dialog === 'menu' && (
        <Modal title={user.name} close={() => setDialog('')}>
          <div className="profile-actions">
            <span className="muted">Akzentfarbe</span>
            <div className="accent-presets">
              {Object.entries(accentPresets).map(([key, hue]) => (
                <button
                  key={key}
                  aria-label={'Akzentfarbe ' + key}
                  aria-pressed={preferences.value.accent === key}
                  style={{ background: `oklch(0.68 0.14 ${hue})` }}
                  onClick={() =>
                    preferences.save({ ...preferences.value, accent: key as keyof typeof accentPresets })
                  }
                />
              ))}
            </div>
            <button onClick={() => setDialog('edit')}>Profil bearbeiten</button>
            <button
              onClick={() => {
                setDialog('');
                go('account');
              }}
            >
              Anmeldung & Sicherheit
            </button>
            <button
              onClick={() => {
                setDialog('');
                go('preferences');
              }}
            >
              Einstellungen
            </button>
            <button
              disabled={busy}
              onClick={async () => {
                setBusy(true);
                try {
                  await api('v1/admin/auth/logout', 'POST');
                  await qc.resetQueries();
                } catch (e) {
                  setError(e);
                } finally {
                  setBusy(false);
                }
              }}
            >
              Abmelden
            </button>
          </div>
        </Modal>
      )}
      {dialog === 'edit' && (
        <Modal title="Profil bearbeiten" close={() => setDialog('')}>
          <Form
            initial={{ name: user.name, email: user.email }}
            fields={[
              { key: 'name', label: 'Name', required: true },
              { key: 'email', label: 'E-Mail', type: 'email', required: true },
              { key: 'current_password', label: 'Aktuelles Passwort', type: 'password', required: true },
              {
                key: 'password',
                label: 'Neues Passwort',
                type: 'password',
                help: 'Mindestens 12 Zeichen; leer lassen, um das Kennwort zu behalten.',
              },
              { key: 'password_confirmation', label: 'Neues Passwort wiederholen', type: 'password' },
              ...(user.mfa_enabled
                ? [{ key: 'mfa_code', label: 'Aktueller Zwei-Faktor-Code', required: true }]
                : []),
            ]}
            onSave={async (data) => {
              await api('v1/admin/auth/profile', 'PATCH', data);
              setDialog('');
              await qc.invalidateQueries({ queryKey: ['v1/admin/auth/me'] });
            }}
          />
        </Modal>
      )}
    </div>
  );
}
