import { useQueryClient } from '@tanstack/react-query';
import { useData, Form, ErrorBox, Loading, type Row } from '@platzhirsch/ui-runtime/components';
import { api } from '@platzhirsch/ui-runtime/api';
export default function SsoAccount({ user }: { user: Row }) {
  const q = useData('v1/admin/auth/sso/status'),
    qc = useQueryClient();
  if (q.isPending) return <Loading />;
  if (q.error) return <ErrorBox error={q.error} />;
  return (
    <section className="panel padded">
      <h2>Single Sign-On</h2>
      {!q.data.enabled ? (
        <p>
          SSO ist auf diesem Server noch nicht eingerichtet. Die Anmeldung mit deinem Platzhirsch-Kennwort
          bleibt verfügbar.
        </p>
      ) : (
        <>
          <p>
            {q.data.label}: {q.data.linked ? 'Mit deinem Konto verknüpft.' : 'Noch nicht verknüpft.'}
          </p>
          <p>
            Verknüpfe ausschließlich dein eigenes Unternehmenskonto. Platzhirsch vergibt dadurch keine
            zusätzlichen Rollen oder Rechte.
          </p>
          <Form
            key={String(q.data.linked)}
            fields={[
              { key: 'password', label: 'Aktuelles Platzhirsch-Kennwort', type: 'password', required: true },
              ...(user.mfa_enabled
                ? [{ key: 'mfa_code', label: 'Aktueller Zwei-Faktor-Code', required: true }]
                : []),
            ]}
            onSave={async (data) => {
              if (q.data.linked) {
                await api('v1/admin/auth/sso/unlink', 'POST', data);
                await qc.invalidateQueries();
              } else {
                const result = await api('v1/admin/auth/sso/link', 'POST', data);
                location.assign(result.url);
              }
            }}
            label={q.data.linked ? 'SSO-Verknüpfung entfernen' : 'Eigenes Unternehmenskonto verknüpfen'}
          />
        </>
      )}
    </section>
  );
}
