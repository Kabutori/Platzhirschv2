import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { api } from '@platzhirsch/ui-runtime/api';
import { useData, Form, Modal, ErrorBox, Loading, type Row } from '@platzhirsch/ui-runtime/components';
export default function RoleRollout() {
  const q = useData('v1/admin/role-rollout'),
    tenants = useData('v1/admin/tenants'),
    qc = useQueryClient();
  const [error, setError] = useState<unknown>();
  const [preview, setPreview] = useState<Row | null>(null),
    [done, setDone] = useState(0);
  if (q.isPending || tenants.isPending) return <Loading />;
  if (q.error || tenants.error) return <ErrorBox error={q.error || tenants.error} />;
  const list = Array.isArray(tenants.data) ? tenants.data : tenants.data?.data || [];
  return (
    <section className="panel padded">
      <ErrorBox error={error} />
      <h2>Restaurantrollen verteilen</h2>
      <p>
        Erstellt dieselbe neue Rolle in bis zu 50 ausgewählten Restaurants. Bestehende Rollen und
        Benutzerzuordnungen werden nicht verändert. Rechte stammen aus registrierten Modulen.
      </p>
      <Form
        fields={[
          { key: 'name', label: 'Name der neuen Rolle', required: true },
          {
            key: 'permissions',
            label: 'Berechtigungen',
            multiple: true,
            options: Object.entries(q.data.permissions || {}).map(([value, label]) => ({
              value,
              label: String(label),
            })),
            help: 'Mehrfachauswahl mit Strg/Cmd.',
          },
          {
            key: 'tenant_ids',
            label: 'Zielrestaurants',
            multiple: true,
            required: true,
            options: list.map((r: Row) => ({ value: r.id, label: r.name + ' (#' + r.id + ')' })),
          },
        ]}
        onSave={async (data) => {
          setDone(0);
          setError(undefined);
          setPreview(await api('v1/admin/role-rollout/preview', 'POST', data));
        }}
        label="Verteilung prüfen"
      />
      {done > 0 && (
        <p role="status">
          {done} Restaurantrollen erstellt. Die Restaurantleitung kann sie jetzt ihrem Team zuweisen.
        </p>
      )}
      {preview && (
        <Modal title="Geprüfte Rollenverteilung bestätigen" close={() => setPreview(null)}>
          <p>Rolle: {preview.preview.name}</p>
          <ul>
            {preview.preview.tenant_ids.map((id: number) => (
              <li key={id}>{list.find((r: Row) => String(r.id) === String(id))?.name || id}</li>
            ))}
          </ul>
          <p>
            {preview.preview.permissions.length} Berechtigungen je Rolle. Diese Vorschau gilt fünf Minuten und
            kann einmal bestätigt werden.
          </p>
          <Form
            fields={[
              { key: 'password', label: 'Administratorkennwort', type: 'password', required: true },
              { key: 'mfa_code', label: 'Aktueller Zwei-Faktor-Code', required: true },
            ]}
            onSave={async (data) => {
              try {
                const result = await api('v1/admin/role-rollout/apply', 'POST', {
                  ...data,
                  token: preview.token,
                });
                setDone(result.created.length);
                setPreview(null);
                await qc.invalidateQueries();
              } catch (error) {
                setPreview(null);
                setError(error);
              }
            }}
            label="Rollen jetzt erstellen"
          />
        </Modal>
      )}
    </section>
  );
}
