import { useState } from 'react';
import { api } from '@platzhirsch/ui-runtime/api';
import { useData, Loading, ErrorBox, Form, type Row } from '@platzhirsch/ui-runtime/components';
function Assign({
  rows,
  room,
  tenant,
  done,
}: {
  rows: Row[];
  room: Row;
  tenant?: string;
  done: () => Promise<void>;
}) {
  const [snapshot] = useState(rows);
  return (
    <>
      <p className="padded">
        Ausgewählte Tische werden dem Raum „{room.name}“ zugeordnet. Nicht ausgewählte Tische bleiben
        unverändert. Laufende/zukünftige Reservierungen und gespeicherte Kombinationen schützen vor
        unbeabsichtigtem Verschieben.
      </p>
      <Form
        fields={[
          {
            key: 'ids',
            label: 'Tische zuordnen',
            multiple: true,
            required: true,
            options: snapshot.map((t) => ({
              value: String(t.id),
              label: t.name + ' · ' + t.capacity + ' Plätze · Raum #' + t.room_id,
            })),
          },
        ]}
        label="Zuordnung speichern"
        onSave={async (data) => {
          await api(
            'v1/restaurant/rooms/' + room.id + '/tables',
            'PATCH',
            {
              tables: snapshot
                .filter((t) => data.ids.includes(String(t.id)))
                .map((t) => ({ id: t.id, room_id: t.room_id })),
            },
            tenant,
          );
          await done();
        }}
      />
    </>
  );
}
export default function RoomTables({
  room,
  tenant,
  done,
}: {
  room: Row;
  tenant?: string;
  done: () => Promise<void>;
}) {
  const q = useData('v1/restaurant/tables', tenant);
  return q.isPending ? (
    <Loading />
  ) : q.error ? (
    <ErrorBox error={q.error} />
  ) : (
    <Assign rows={q.data} room={room} tenant={tenant} done={done} />
  );
}
