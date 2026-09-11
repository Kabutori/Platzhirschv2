import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, allPages } from '@platzhirsch/ui-runtime/api';
import { useData, Form, Modal, Loading, ErrorBox, type Row } from '@platzhirsch/ui-runtime/components';
export default function Organizations() {
  const org = useData('v1/admin/organizations'),
    tenants = useQuery({ queryKey: ['all-tenants'], queryFn: () => allPages('v1/admin/tenants') }),
    qc = useQueryClient();
  const [form, setForm] = useState<Row | null>(null),
    [assign, setAssign] = useState<Row | null>(null),
    [remove, setRemove] = useState<Row | null>(null);
  if (org.isPending || tenants.isPending) return <Loading />;
  if (org.error || tenants.error) return <ErrorBox error={org.error || tenants.error} />;
  const options = [
    { value: '', label: 'Keine / oberste Ebene' },
    ...org.data.map((o: Row) => ({ value: o.id, label: o.name })),
  ];
  function branch(parent: number | null, ancestors: number[] = []): React.ReactNode {
    return (
      <ul className="organization-tree">
        {org.data
          .filter((o: Row) => (o.parent_id ?? null) === parent && !ancestors.includes(o.id))
          .map((o: Row) => (
            <li key={o.id}>
              <div>
                <strong>{o.name}</strong>
                <button onClick={() => setForm(o)}>Bearbeiten</button>
                <button onClick={() => setRemove(o)}>Löschen</button>
              </div>
              {tenants.data
                ?.filter((t) => t.organization_id === o.id)
                .map((t) => (
                  <p key={t.id}>
                    ↳ {t.name} <button onClick={() => setAssign(t)}>Zuordnung ändern</button>
                  </p>
                ))}
              {branch(o.id, [...ancestors, o.id])}
            </li>
          ))}
      </ul>
    );
  }
  return (
    <section className="panel padded">
      <div className="section-heading">
        <h2>Organisationen & Restaurants</h2>
        <button className="primary" onClick={() => setForm({})}>
          Organisation anlegen
        </button>
      </div>
      <p>
        Gruppiere Restaurants unter Unternehmen und Regionen. Die Zuordnung vergibt keine Zugriffsrechte;
        Rollen werden ausdrücklich mit Vorschau synchronisiert.
      </p>
      {branch(null)}
      <h3>Ohne Organisation</h3>
      {tenants.data
        ?.filter((t) => !t.organization_id)
        .map((t) => (
          <p key={t.id}>
            {t.name} <button onClick={() => setAssign(t)}>Organisation zuordnen</button>
          </p>
        ))}
      {form && (
        <Modal title="Organisation bearbeiten" close={() => setForm(null)}>
          <Form
            initial={form}
            fields={[
              { key: 'name', label: 'Name', required: true },
              {
                key: 'parent_id',
                label: 'Übergeordnete Organisation',
                options: options.filter((o) => o.value !== form.id),
              },
            ]}
            onSave={async (d) => {
              await api(
                'v1/admin/organizations' + (form.id ? '/' + form.id : ''),
                form.id ? 'PATCH' : 'POST',
                { ...d, parent_id: d.parent_id || null, version: form.version },
              );
              setForm(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
      {assign && (
        <Modal title={assign.name + ' zuordnen'} close={() => setAssign(null)}>
          <Form
            initial={assign}
            fields={[{ key: 'organization_id', label: 'Organisation', options }]}
            onSave={async (d) => {
              await api('v1/admin/tenants/' + assign.id + '/organization', 'PATCH', {
                organization_id: d.organization_id || null,
              });
              setAssign(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
      {remove && (
        <Modal title="Organisation löschen?" close={() => setRemove(null)}>
          <p>
            {remove.name} kann nur gelöscht werden, wenn keine Unterorganisationen oder Restaurants zugeordnet
            sind.
          </p>
          <Form
            fields={[]}
            label="Löschen bestätigen"
            onSave={async () => {
              await api('v1/admin/organizations/' + remove.id, 'DELETE');
              setRemove(null);
              await qc.invalidateQueries();
            }}
          />
        </Modal>
      )}
    </section>
  );
}
