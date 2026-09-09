import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, allPages } from '@platzhirsch/ui-runtime/api';
import { useData, Form, Modal, ErrorBox, Loading, type Row } from '@platzhirsch/ui-runtime/components';
export default function RoleRollout() {
  const q = useData('v1/admin/role-rollout'),
    tenants = useQuery({ queryKey: ['all-tenants'], queryFn: () => allPages('v1/admin/tenants') }),
    qc = useQueryClient();
  const [error, setError] = useState<unknown>();
  const [preview, setPreview] = useState<Row | null>(null),
    [done, setDone] = useState(0);
  if (q.isPending || tenants.isPending) return <Loading />;
  if (q.error || tenants.error) return <ErrorBox error={q.error || tenants.error} />;
  const list = tenants.data || [];
  return (
    <section className="panel padded">
      <ErrorBox error={error} />
      <h2>Restaurantrollen verteilen</h2>
      <p>
        Erstellt neue Rollen oder synchronisiert gleichnamige bestehende Rollen in bis zu 50 Restaurants. Bei
        der Synchronisierung gelten geänderte Rechte sofort für zugewiesene Benutzer. Die Vorschau zeigt
        hinzugefügte und entfernte Rechte.
      </p>
      <Form
        fields={[
          {
            key: 'mode',
            label: 'Vorgang',
            default: 'create',
            options: [
              { value: 'create', label: 'Neue Rollen erstellen' },
              { value: 'sync', label: 'Bestehende Rollen synchronisieren' },
            ],
          },
          { key: 'name', label: 'Rollenname', required: true },
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
          {done} Restaurantrollen verarbeitet. Die Restaurantleitung kann sie jetzt ihrem Team zuweisen.
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
          {preview.preview.mode === 'sync' && (
            <div className="notice">
              <div>
                <strong>Bestehende Benutzer sind betroffen</strong>
                {preview.preview.existing.map((r: Row) => (
                  <p key={r.id}>
                    Restaurant #{r.tenant_id} · {r.assigned_users} Benutzer
                    <br />
                    Hinzu: {r.added.join(', ') || 'keine'}
                    <br />
                    Entfernt: {r.removed.join(', ') || 'keine'}
                  </p>
                ))}
              </div>
            </div>
          )}
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
                setDone((result.created || result.synchronized).length);
                setPreview(null);
                await qc.invalidateQueries();
              } catch (error) {
                setPreview(null);
                setError(error);
              }
            }}
            label="Rollenänderung bestätigen"
          />
        </Modal>
      )}
    </section>
  );
}
