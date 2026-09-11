import { useLayoutEffect, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  Monitor,
  Server,
  Code2,
  Database,
  Building2,
  Workflow,
  ArrowLeft,
  ArrowRight,
  Map,
  FolderOpen,
  Boxes,
  GraduationCap,
  RefreshCw,
  ShieldCheck,
} from 'lucide-react';
import { api } from '@platzhirsch/ui-runtime/api';
import { nodes, files, modules } from './content';
import './guide.css';

type NodeKey = keyof typeof nodes;
type Chapter = 'map' | 'files' | 'modules' | 'windows' | 'installation';
const icons = {
  browser: Monitor,
  iis: Server,
  php: Code2,
  platform: Database,
  tenant: Building2,
  worker: Workflow,
};
const edges: [NodeKey, NodeKey, string][] = [
  ['browser', 'iis', 'HTTP'],
  ['iis', 'php', 'FastCGI'],
  ['php', 'platform', 'SQL'],
  ['php', 'tenant', 'SQL'],
  ['platform', 'worker', 'Aufträge'],
];
const short: Record<NodeKey, [string, string]> = {
  browser: ['Browser', 'Administration · Restaurant'],
  iis: ['IIS', 'Webserver'],
  php: ['PHP + Laravel', 'Module & Geschäftsregeln'],
  platform: ['Plattform', 'Konten · Rollen · Aufträge'],
  tenant: ['Restaurant', 'Tische · Reservierungen'],
  worker: ['Hintergrundaufgaben', 'Worker · Scheduler'],
};
const journey: { node: NodeKey; title: string; text: string }[] = [
  {
    node: 'browser',
    title: 'Reservierung absenden',
    text: 'Im Restaurantportal werden Tisch, Zeit und Gästezahl eingegeben. Die Oberfläche sendet eine Anfrage an den Server.',
  },
  {
    node: 'iis',
    title: 'Anfrage entgegennehmen',
    text: 'IIS nimmt den Aufruf entgegen und leitet die API-Anfrage über FastCGI an PHP weiter.',
  },
  {
    node: 'platform',
    title: 'Zugang und Restaurant zuordnen',
    text: 'Die Anwendung prüft Sitzung und Berechtigungen. Die Plattform enthält die Zuordnung zum Restaurant und dessen Datenbankserver.',
  },
  {
    node: 'php',
    title: 'Regeln und Konflikte prüfen',
    text: 'Das Reservierungsmodul prüft Öffnungszeiten und Kapazität. Konfliktprüfung und Speicherung erfolgen geschützt in einer Datenbanktransaktion.',
  },
  {
    node: 'tenant',
    title: 'Speichern und antworten',
    text: 'Die Buchung liegt in der Restaurant-Datenbank. PHP und IIS geben dem Browser die Bestätigung oder eine Konfliktmeldung zurück.',
  },
];
const windows = [
  [
    'Dienste',
    'Der Datenbankserver läuft als Windows-Dienst „PlatzhirschMySQL“. IIS betreibt die Website und den Anwendungspool „Platzhirsch“.',
    'Windows-Dienste und IIS-Manager',
  ],
  [
    'Aufgabenplanung',
    'Platzhirsch-default und Platzhirsch-provisioning starten Hintergrundarbeiter. Platzhirsch-Scheduler führt jede Minute fällige Anwendungsaufgaben aus.',
    'Windows-Aufgabenplanung',
  ],
  [
    'Dateirechte',
    'Die Webanwendung arbeitet mit einer eigenen IIS-Identität ohne Administratorrechte. Geschützte Worker-Zugänge sind für diese Identität nicht lesbar.',
    'Eigenschaften eines Ordners → Sicherheit',
  ],
  [
    'Ports und Firewall',
    'Ein Port bezeichnet einen Zugang zu einem Dienst. Standardmäßig sind Web-Port 8378 und MySQL-Port 3308 nur auf dem lokalen Rechner erreichbar.',
    'Installer-Standardwerte; keine Prüfung deines Netzwerks',
  ],
  [
    'DNS und HTTPS',
    'DNS ordnet einen Namen einer Adresse zu. HTTPS benötigt ein passendes Zertifikat. Enable-PublicAccess.ps1 richtet nach Prüfung die separate Freigabe ein; MySQL wird nicht veröffentlicht.',
    'Zertifikatsspeicher, IIS-Bindungen und Firewall',
  ],
  [
    'E-Mail / SMTP',
    'Der Mailserver wird separat konfiguriert. Ein erreichbarer Webserver bedeutet nicht, dass E-Mails versendet werden können. Automatische Reservierungsbestätigungen sind noch nicht implementiert.',
    'Anwendungskonfiguration und Mailanbieter',
  ],
  [
    'Protokolle',
    'Fehler können in IIS, PHP, der Anwendung, MySQL oder Hintergrundaufgaben entstehen. Zuerst die betroffene Komponente bestimmen; Fehlerprotokolle können sensible Daten enthalten.',
    'PHP/Anwendung: app\\storage\\logs · MySQL: logs\\mysql.log',
  ],
  [
    'Sicherung und Wiederherstellung',
    'Eine Sicherung muss Daten, Anwendung und benötigte Schlüssel umfassen. Der lokale Snapshot unterstützt dieselbe Maschine und Version; mit zusätzlichen autorisierten Servern ist er gesperrt.',
    'Keine automatische Sicherung mehrerer Datenbankserver',
  ],
];

function SystemMap({ selected, choose }: { selected: NodeKey; choose: (key: NodeKey) => void }) {
  const area = useRef<HTMLDivElement>(null);
  const [lines, setLines] = useState<
    { from: NodeKey; to: NodeKey; label: string; d: string; x: number; y: number }[]
  >([]);
  useLayoutEffect(() => {
    const container = area.current;
    if (!container) return;
    const measure = () => {
      const outer = container.getBoundingClientRect();
      setLines(
        edges.map(([from, to, label]) => {
          const a = container
            .querySelector<HTMLElement>(`[data-guide-node="${from}"]`)!
            .getBoundingClientRect();
          const b = container
            .querySelector<HTMLElement>(`[data-guide-node="${to}"]`)!
            .getBoundingClientRect();
          const x1 = a.left + a.width / 2 - outer.left,
            y1 = a.bottom - outer.top,
            x2 = b.left + b.width / 2 - outer.left,
            y2 = b.top - outer.top;
          const mid = (y1 + y2) / 2;
          return { from, to, label, d: `M ${x1} ${y1} V ${mid} H ${x2} V ${y2 - 5}`, x: x2, y: mid - 6 };
        }),
      );
    };
    const observer = new ResizeObserver(measure);
    observer.observe(container);
    container.querySelectorAll('button').forEach((b) => observer.observe(b));
    measure();
    return () => observer.disconnect();
  }, []);
  return (
    <div
      className="sg-map"
      ref={area}
      aria-label="Systemkarte: Browser zu IIS, PHP und Datenbanken; Plattform zu Hintergrundaufgaben"
    >
      <svg className="sg-connections" aria-hidden="true">
        <defs>
          <marker
            id="sg-arrow"
            viewBox="0 0 10 10"
            refX="8"
            refY="5"
            markerWidth="6"
            markerHeight="6"
            orient="auto-start-reverse"
          >
            <path d="M 0 0 L 10 5 L 0 10 z" fill="currentColor" />
          </marker>
        </defs>
        {lines.map((l) => (
          <g key={l.from + l.to} className={selected === l.from || selected === l.to ? 'sg-line-active' : ''}>
            <path d={l.d} fill="none" markerEnd="url(#sg-arrow)" />
            <text x={l.x + 8} y={l.y}>
              {l.label}
            </text>
          </g>
        ))}
      </svg>
      {(Object.keys(nodes) as NodeKey[]).map((key) => {
        const Icon = icons[key];
        return (
          <button
            type="button"
            key={key}
            data-guide-node={key}
            className={'sg-node sg-node-' + key}
            aria-pressed={selected === key}
            onClick={() => choose(key)}
          >
            <span className="sg-icon">
              <Icon size={25} />
            </span>
            <strong>{short[key][0]}</strong>
            <small>{short[key][1]}</small>
          </button>
        );
      })}
    </div>
  );
}
type Connection = {
  id: string;
  name: string;
  scope: string;
  host: string;
  port: number;
  database: string;
  status: string;
};
function Installation() {
  const q = useQuery({
    queryKey: ['guide-connections'],
    queryFn: () => api<Connection[]>('v1/admin/database-access'),
    retry: false,
    staleTime: 0,
    gcTime: 0,
  });
  return (
    <section>
      <div className="toolbar">
        <h2>Konfigurierte Datenbankverbindungen</h2>
        <button onClick={() => q.refetch()} disabled={q.isFetching}>
          <RefreshCw size={16} />
          Neu laden
        </button>
      </div>
      <p>
        Aus der Anwendungskonfiguration und den Restaurantzuordnungen. Dies ist keine Erreichbarkeitsprüfung
        und kein vollständiges Windows-Inventar.
      </p>
      {q.isPending ? (
        <p role="status">Verbindungen werden geladen …</p>
      ) : q.isError ? (
        <p role="alert">
          Verbindungen konnten nicht geladen werden. Bitte Berechtigung und Verbindung prüfen.
        </p>
      ) : (
        <>
          <p className="muted">Abgerufen: {new Date(q.dataUpdatedAt).toLocaleString('de-DE')}</p>
          <div className="sg-connection-list">
            {q.data?.map((c) => (
              <article key={c.id} className="panel padded">
                <Database size={24} />
                <h3>{c.name}</h3>
                <dl>
                  <dt>Bereich</dt>
                  <dd>{c.scope}</dd>
                  <dt>Adresse</dt>
                  <dd>
                    <code>
                      {c.host}:{c.port}
                    </code>
                  </dd>
                  <dt>Datenbank</dt>
                  <dd>
                    <code>{c.database}</code>
                  </dd>
                  <dt>Zuordnungsstatus</dt>
                  <dd>{c.status}</dd>
                </dl>
              </article>
            ))}
          </div>
        </>
      )}
    </section>
  );
}
export default function Guide({ administrator = false }: { administrator?: boolean }) {
  const [chapter, setChapter] = useState<Chapter>('map');
  const [selected, setSelected] = useState<NodeKey>('iis');
  const [step, setStep] = useState<number | null>(null);
  const n = nodes[selected];
  const tabs: [Chapter, string, typeof Map][] = [
    ['map', 'Systemkarte', Map],
    ['files', 'Dateien & Daten', FolderOpen],
    ['modules', 'Module', Boxes],
    ['windows', 'Windows verstehen', Server],
    ...(administrator
      ? [['installation', 'Meine Datenbanken', Database] as [Chapter, string, typeof Map]]
      : []),
  ];
  const goStep = (i: number) => {
    setStep(i);
    setSelected(journey[i].node);
  };
  return (
    <div className="system-guide">
      <div className="sg-heading">
        <span className="sg-eyebrow">Platzhirsch verstehen</span>
        <h1>Vom Browser bis zur Datenbank</h1>
        <p>Erkunde die Bausteine, ihre Verbindungen und die Windows-Umgebung.</p>
      </div>
      <nav className="sg-tabs" aria-label="Guide-Kapitel">
        {tabs.map(([key, label, Icon]) => (
          <button key={key} aria-pressed={chapter === key} onClick={() => setChapter(key)}>
            <Icon size={17} />
            {label}
          </button>
        ))}
      </nav>
      {chapter === 'map' && (
        <>
          <div className="sg-map-toolbar">
            <span className="sg-eyebrow">Lernansicht · lokale Standardinstallation</span>
            <button onClick={() => (step === null ? goStep(0) : setStep(null))}>
              <GraduationCap size={18} />
              {step === null ? 'Buchung verfolgen' : 'Frei erkunden'}
            </button>
          </div>
          {step !== null && (
            <div className="sg-journey" aria-live="polite">
              <button aria-label="Vorheriger Schritt" disabled={step === 0} onClick={() => goStep(step - 1)}>
                <ArrowLeft size={18} />
              </button>
              <span>
                <strong>
                  {step + 1} / {journey.length} · {journey[step].title}
                </strong>
                <span>{journey[step].text}</span>
              </span>
              <button
                aria-label="Nächster Schritt"
                disabled={step === journey.length - 1}
                onClick={() => goStep(step + 1)}
              >
                <ArrowRight size={18} />
              </button>
            </div>
          )}
          <div className="sg-layout">
            <SystemMap
              selected={selected}
              choose={(key) => {
                setStep(null);
                setSelected(key);
              }}
            />
            <section className="sg-detail panel padded" aria-live="polite">
              <span className="sg-eyebrow">{n.kind}</span>
              <h2>{n.name}</h2>
              <p>{n.meaning}</p>
              <dl>
                <dt>Wo liegt das? · Standardwert</dt>
                <dd>
                  <code>{n.where}</code>
                </dd>
                <dt>Verbindung</dt>
                <dd>{n.connection}</dd>
                <dt>Gut zu wissen</dt>
                <dd>{n.tip}</dd>
              </dl>
            </section>
          </div>
          <p className="sg-footnote">
            Die Karte erklärt den Aufbau; sie misst keinen Systemzustand. Standardpfad: C:\Platzhirsch.
            Zusatzserver können andere Adressen und Speicherorte haben.
          </p>
        </>
      )}
      {chapter === 'files' && (
        <>
          <h2>Dateien sind nicht gleich Datenbanken</h2>
          <p>
            Standardpfade unter C:\Platzhirsch. MySQL verwaltet seine Daten selbst; einzelne Ordner sind kein
            vollständiges Backup.
          </p>
          <div className="sg-reference">
            {files.map(([name, path, meaning]) => (
              <article key={name}>
                <FolderOpen size={22} />
                <div>
                  <h3>{name}</h3>
                  <code>{path}</code>
                  <p>{meaning}</p>
                </div>
              </article>
            ))}
          </div>
        </>
      )}
      {chapter === 'modules' && (
        <>
          <h2>Bausteine einer gemeinsamen Anwendung</h2>
          <p>
            Module teilen sich IIS und PHP. Jedes Modul bringt seine Fachaufgaben und gegebenenfalls Rechte
            oder Migrationen mit.
          </p>
          <div className="sg-module-grid">
            {modules.map(([name, meaning, data]) => (
              <article className="panel padded" key={name}>
                <Boxes size={24} />
                <h3>{name}</h3>
                <p>{meaning}</p>
                <small>{data}</small>
              </article>
            ))}
          </div>
        </>
      )}
      {chapter === 'windows' && (
        <>
          <h2>Windows rund um Platzhirsch</h2>
          <div className="sg-reference">
            {windows.map(([title, text, where]) => (
              <article key={title}>
                <ShieldCheck size={22} />
                <div>
                  <h3>{title}</h3>
                  <p>{text}</p>
                  <small>{where}</small>
                </div>
              </article>
            ))}
          </div>
        </>
      )}
      {chapter === 'installation' && administrator && <Installation />}
    </div>
  );
}
