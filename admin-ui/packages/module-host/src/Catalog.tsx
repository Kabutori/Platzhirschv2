import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Blocks, LockKeyhole } from 'lucide-react';
import { api } from '@platzhirsch/ui-runtime/api';
type Module = {
  code: string;
  version: string;
  dependencies: Record<string, string>;
  optional_dependencies?: Record<string, string>;
  permissions: { code: string; label: string; permissions: { code: string; label: string }[] }[];
};
const labels: Record<string, { name: string; description: string }> = {
  weather: {
    name: 'Wettervorhersage',
    description: '7-Tage-Vorschau und Regenwarnungen für wetterabhängige Räume.',
  },
  customer: { name: 'Customer', description: 'Restaurantprofile, Mandantenanlage und Testrestaurants.' },
  reservation: {
    name: 'Reservation',
    description: 'Räume, Tische, Öffnungszeiten und konfliktgeprüfte Reservierungen.',
  },
  widget: {
    name: 'Widget',
    description: 'Einbettbare Buchungsoberfläche mit Herkunfts- und Verfügbarkeitsprüfung.',
  },
  support: { name: 'Support', description: 'Tickets, Antworten und interne Notizen.' },
  billing: {
    name: 'Billing',
    description: 'Modulangebote, Bestellungen, Zahlungsfreigaben und Nutzungszeiträume.',
  },
  reporting: {
    name: 'Reporting',
    description: 'Zeitraumauswertungen und gespeicherte Berichte mit eigener Mandantenmigration.',
  },
  identity: { name: 'Identity', description: 'Plattformrollen, Berechtigungen und Rollenzuordnung.' },
  provisioning: {
    name: 'Provisioning',
    description: 'Datenbankserver, SQL-Zugriff, Provisionierung und geprüfte Mandantenumzüge.',
  },
};
export default function Catalog() {
  const q = useQuery({ queryKey: ['installed-modules'], queryFn: () => api<Module[]>('v1/admin/modules') });
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<string>();
  const detail = q.data?.find((m) => m.code === selected);
  return (
    <>
      <div className="toolbar">
        <h2>Module</h2>
        <input
          aria-label="Module suchen"
          placeholder="Modul suchen …"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>
      <p className="muted">Installierte Module und ihre eigenen Paketversionen</p>
      <button
        disabled={!q.data}
        onClick={() => {
          const content = {
            exportedAt: new Date().toISOString(),
            modules: q.data?.map((m) => ({ code: m.code, version: m.version, dependencies: m.dependencies })),
          };
          const url = URL.createObjectURL(
            new Blob([JSON.stringify(content, null, 2)], { type: 'application/json' }),
          );
          const a = document.createElement('a');
          a.href = url;
          a.download = 'platzhirsch-modulversionen.json';
          a.click();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
        }}
      >
        Versionsstand exportieren
      </button>
      {q.isPending && <p role="status">Module werden geladen …</p>}
      {q.error && <p role="alert">{q.error.message}</p>}
      <div className="module-cards">
        {q.data
          ?.filter((m) => (labels[m.code]?.name || m.code).toLowerCase().includes(search.toLowerCase()))
          .map((m) => (
            <article className="panel padded module-card" key={m.code}>
              <div className="toolbar">
                <Blocks size={22} />
                <span className="rights-status">
                  <LockKeyhole size={12} /> Im Release enthalten
                </span>
              </div>
              <h3>{labels[m.code]?.name || m.code}</h3>
              <code>v{m.version}</code>
              <p>{labels[m.code]?.description || 'Registriertes Anwendungsmodul.'}</p>
              <p className="muted">
                {m.permissions.reduce((n, f) => n + f.permissions.length, 0)} Berechtigungen ·{' '}
                {Object.keys(m.dependencies).length} Abhängigkeiten
              </p>
              <button
                aria-expanded={selected === m.code}
                onClick={() => setSelected(selected === m.code ? undefined : m.code)}
              >
                Details {selected === m.code ? 'schließen' : 'anzeigen'}
              </button>
            </article>
          ))}
      </div>
      {detail && (
        <section className="panel padded module-detail">
          <h2>{labels[detail.code]?.name || detail.code} · Details</h2>
          <h3>Erforderliche Module</h3>
          {Object.entries(detail.dependencies).length ? (
            Object.entries(detail.dependencies).map(([code, version]) => (
              <p key={code}>
                <code>
                  {code} {version}
                </code>{' '}
                ·{' '}
                {q.data?.some((m) => m.code === code && m.version === version)
                  ? 'Passende Version installiert'
                  : 'Nicht verfügbar'}
              </p>
            ))
          ) : (
            <p>Keine weiteren Module erforderlich.</p>
          )}
          <h3>Berechtigungsgruppen</h3>
          {detail.permissions.map((f) => (
            <section key={f.code}>
              <h4>{f.label}</h4>
              {f.permissions.map((p) => (
                <div className="rights-row" key={p.code}>
                  <span>{p.label}</span>
                  <code>{p.code}</code>
                </div>
              ))}
            </section>
          ))}
        </section>
      )}
      <section className="panel padded module-detail">
        <h3>Zubuchbare Module</h3>
        <p>
          Angebote und Bestellungen verwaltest du im gleichnamigen Reiter. Restaurants buchen freigegebene
          Angebote im Modul-Shop; die Aktivierung erfolgt nach Zahlungsfreigabe einschließlich der
          erforderlichen Mandantenmigrationen.
        </p>
        <p className="muted">
          Jedes Modul besitzt eine eigene Paketversion. PHP- und UI-Pakete können im Entwicklungsprojekt
          separat gebaut und veröffentlicht werden. Die Installation erfolgt weiterhin über ein gemeinsam
          geprüftes Anwendungspaket unter „System → Backups & Updates“. Ein Modulpaket allein ist kein
          installierbares Windows-Update. Die direkte Versionsauswahl in dieser Oberfläche folgt im weiteren
          Ausbau.
        </p>
      </section>
    </>
  );
}
