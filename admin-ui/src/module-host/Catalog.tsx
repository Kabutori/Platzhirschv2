import { useQuery } from '@tanstack/react-query';
import { api } from '../api';
export default function Catalog() {
  const q = useQuery({ queryKey: ['installed-modules'], queryFn: () => api('v1/admin/modules') });
  return (
    <section className="panel padded">
      <h2>Installierte Module</h2>
      <p>
        Diese Module sind Bestandteil des installierten Releases. Weitere Pakete und Versionen werden beim
        Release-Bau eingebunden.
      </p>
      <p className="notice">
        Kauf und Aktivierung kostenpflichtiger Restaurantmodule sind noch nicht verfügbar. Bestehende
        Anwendungsbereiche sind noch nicht vollständig als eigenständige Pakete ausgegliedert.
      </p>
      {q.isPending && <p>Module werden geladen …</p>}
      {q.error && <p role="alert">{q.error.message}</p>}
      {q.data?.map((m: any) => (
        <article className="panel padded" key={m.code}>
          <h3>{m.code}</h3>
          <p>Version {m.version} · Installiert</p>
          <p>Abhängigkeiten: {Object.keys(m.dependencies).join(', ') || 'Keine'}</p>
          <p>{m.permissions.length} registrierte Berechtigungsgruppen</p>
        </article>
      ))}
    </section>
  );
}
