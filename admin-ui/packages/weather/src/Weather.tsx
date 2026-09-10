import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Sun, Cloud, CloudRain, CloudLightning, Snowflake } from 'lucide-react';
import { api } from '@platzhirsch/ui-runtime/api';
import { Modal, Form, Loading, ErrorBox, type Row } from '@platzhirsch/ui-runtime/components';
const status: Record<string, string> = {
  unconfigured: 'Wettervorhersage ist noch nicht eingerichtet oder ausgeschaltet.',
  provider_missing:
    'Der gewerbliche Anbieterzugang ist noch nicht eingerichtet. Bitte den Plattformadministrator ansprechen.',
  inactive: 'Das Wettermodul ist nicht aktiv.',
  unavailable: 'Wetterdaten sind momentan nicht verfügbar. Bitte die Raumnutzung selbst prüfen.',
  stale: 'Veraltete Vorhersage – die Aktualisierung ist fehlgeschlagen. Bitte die Raumnutzung selbst prüfen.',
};
function Settings({ tenant, close }: { tenant?: string; close: () => void }) {
  const q = useQuery({
    queryKey: ['weather-settings', tenant],
    queryFn: () => api<Row>('v1/restaurant/weather/settings', 'GET', undefined, tenant),
  });
  const qc = useQueryClient();
  return (
    <Modal title="Wetter-Einstellungen" close={close}>
      {q.isPending ? (
        <Loading />
      ) : q.error ? (
        <ErrorBox error={q.error} />
      ) : (
        <>
          <p className="padded">
            Übermittelt werden die Standortkoordinaten an Open-Meteo. Für den Restaurantbetrieb ist ein
            gewerblicher Anbieterzugang erforderlich. Der Auswertungsmodus ist nur für nichtgewerbliche Tests
            vorgesehen.
          </p>
          <Form
            initial={
              q.data.settings || { enabled: false, mode: 'commercial', rain_threshold: 60, version: 0 }
            }
            fields={[
              { key: 'enabled', label: 'Wetter automatisch aktualisieren', type: 'checkbox' },
              { key: 'latitude', label: 'Breitengrad', required: true, help: 'Zum Beispiel 52.52000 (−90 bis 90)' },
              { key: 'longitude', label: 'Längengrad', required: true, help: 'Zum Beispiel 13.40500 (−180 bis 180)' },
              {
                key: 'mode',
                label: 'Anbieternutzung',
                required: true,
                options: [
                  { value: 'commercial', label: 'Gewerblicher Betrieb' },
                  { value: 'evaluation', label: 'Nichtgewerblicher Test' },
                ],
              },
              {
                key: 'rain_threshold',
                label: 'Warnung ab Regenwahrscheinlichkeit (%)',
                type: 'number',
                required: true,
                min: 1,
                max: 100,
              },
            ]}
            onSave={async (data) => {
              await api(
                'v1/restaurant/weather/settings',
                'PUT',
                { ...data, version: q.data.settings?.version ?? 0 },
                tenant,
              );
              await qc.invalidateQueries();
              close();
            }}
          />
        </>
      )}
    </Modal>
  );
}
export default function Weather({
  tenant,
  canConfigure = false,
}: {
  tenant?: string;
  canConfigure?: boolean;
}) {
  const [open, setOpen] = useState(false);
  const q = useQuery({
    queryKey: ['weather-forecast', tenant],
    queryFn: () => api<Row>('v1/restaurant/weather', 'GET', undefined, tenant),
    refetchInterval: 60000,
  });
  const rooms = useQuery({
    queryKey: ['v1/restaurant/rooms', tenant],
    queryFn: () => api<Row[]>('v1/restaurant/rooms', 'GET', undefined, tenant),
  });
  const marked = rooms.data?.filter((r) => Boolean(Number(r.weather_dependent)));
  return (
    <section className="panel weather-panel" aria-label="Wettervorhersage">
      <header>
        <div>
          <span className="eyebrow">WETTERABHÄNGIGE RÄUME</span>
          <h2>7-Tage-Vorschau</h2>
        </div>
        {canConfigure && <button onClick={() => setOpen(true)}>Wetter einstellen</button>}
      </header>
      {rooms.error && <ErrorBox error={rooms.error} />}
      <p>
        {marked?.length
          ? marked.map((r) => r.name).join(' · ')
          : 'Im Raumdialog „Wetterabhängig?“ aktivieren, um die Vorschau diesem Raum zuzuordnen.'}
      </p>
      {q.isPending ? (
        <Loading />
      ) : q.error ? (
        <ErrorBox error={q.error} />
      ) : (
        <>
          {status[q.data.status] && (
            <p className="notice" role="status">
              {status[q.data.status]}
            </p>
          )}
          {!!marked?.length && (
            <div className="weather-days">
              {q.data.days?.map((d: Row) => {
                const Icon =
                  d.code >= 95
                    ? CloudLightning
                    : d.code >= 71 && d.code <= 77
                      ? Snowflake
                      : d.code >= 51
                        ? CloudRain
                        : d.code >= 2
                          ? Cloud
                          : Sun;
                return (
                  <article key={d.date} className={d.warning ? 'weather-warning' : ''}>
                    <time dateTime={d.date}>
                      {new Date(d.date + 'T12:00:00').toLocaleDateString('de-DE', {
                        weekday: 'short',
                        day: '2-digit',
                        month: '2-digit',
                      })}
                    </time>
                    <Icon aria-hidden="true" />
                    <strong>{d.temperature} °C</strong>
                    <span>{d.rain_probability} % Regen</span>
                    {d.warning && <small>Raumnutzung prüfen</small>}
                  </article>
                );
              })}
            </div>
          )}
          {q.data.fetched_at && (
            <p className="muted">
              Stand: {new Date(q.data.fetched_at).toLocaleString('de-DE', { timeZone: q.data.timezone })} · {q.data.timezone}.
              {' '}Der Server aktualisiert die Vorhersage alle 30 Minuten, auch bei geschlossenem Bildschirm.
            </p>
          )}
        </>
      )}
      <p className="muted">
        Vorhersage und Warnungen sind Planungshilfen. Buchungen werden nicht automatisch gesperrt oder
        storniert. Daten:{' '}
        <a href="https://open-meteo.com/" target="_blank" rel="noreferrer">
          Open-Meteo
        </a>{' '}
        (CC BY 4.0), Warnungen aus Tageswerten abgeleitet.
      </p>
      {open && <Settings tenant={tenant} close={() => setOpen(false)} />}
    </section>
  );
}
