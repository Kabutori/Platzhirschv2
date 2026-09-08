import { Toggle } from './controls';
import { createContext, useContext, useState, type ReactNode } from 'react';
export type Preferences = {
  favoritesEnabled: boolean;
  favorites: string[];
  collapsed: boolean;
  backdropClose: boolean;
  exportEnabled: boolean;
  csvEnabled: boolean;
};
const defaults: Preferences = {
  favoritesEnabled: false,
  favorites: [],
  collapsed: false,
  backdropClose: false,
  exportEnabled: true,
  csvEnabled: true,
};
const Context = createContext({ value: defaults, save: (_value: Preferences) => {}, error: '' });
export const usePreferences = () => useContext(Context);
export function PreferencesProvider({ identity, children }: { identity: string; children: ReactNode }) {
  const key = 'platzhirsch.ui.v1.' + identity;
  const [value, setValue] = useState<Preferences>(() => {
    try {
      const v = JSON.parse(localStorage.getItem(key) || '{}');
      return {
        favoritesEnabled: v.favoritesEnabled === true,
        collapsed: v.collapsed === true,
        backdropClose: v.backdropClose === true,
        exportEnabled: v.exportEnabled !== false,
        csvEnabled: v.csvEnabled !== false,
        favorites: Array.isArray(v.favorites)
          ? v.favorites.filter((x: unknown) => typeof x === 'string')
          : [],
      };
    } catch {
      return defaults;
    }
  });
  const [error, setError] = useState('');
  function save(next: Preferences) {
    setValue(next);
    try {
      localStorage.setItem(key, JSON.stringify(next));
      setError('');
    } catch {
      setError(
        'Die Einstellung gilt jetzt, konnte aber in diesem Browser nicht dauerhaft gespeichert werden.',
      );
    }
  }
  return <Context.Provider value={{ value, save, error }}>{children}</Context.Provider>;
}
export function PreferencesPage({
  items,
  canExport = false,
}: {
  items: readonly (readonly [string, string, ...unknown[]])[];
  canExport?: boolean;
}) {
  const { value, save, error } = usePreferences();
  return (
    <div className="preferences-stack">
      <p className="muted">
        Änderungen werden sofort für dein Konto und dieses Portal in diesem Browser gespeichert.
      </p>
      {error && <p role="alert">{error}</p>}
      <section className="panel preference-card" aria-labelledby="behavior-heading">
        <h2 id="behavior-heading">Verhalten</h2>
        <div className="preference-row">
          <div>
            <strong>Dialoge durch Klick auf Hintergrund schließen</strong>
            <small>
              Ein Klick neben einen Bearbeitungsdialog schließt ihn. Sicherheits- und Zugangsdaten-Dialoge
              bleiben ausgenommen.
            </small>
          </div>
          <Toggle
            label="Dialoge durch Klick auf Hintergrund schließen"
            checked={value.backdropClose}
            onChange={() => save({ ...value, backdropClose: !value.backdropClose })}
          />
        </div>
      </section>
      <section className="panel preference-card" aria-labelledby="favorites-heading">
        <div className="preference-row">
          <div>
            <h2 id="favorites-heading">Favoriten in der Navigation</h2>
            <small>Ausgewählte Menüpunkte stehen oben; die übrigen bleiben unter „Weitere“ erreichbar.</small>
          </div>
          <Toggle
            label="Favoriten in der Navigation"
            checked={value.favoritesEnabled}
            onChange={() => save({ ...value, favoritesEnabled: !value.favoritesEnabled })}
          />
        </div>
        {value.favoritesEnabled && (
          <div className="favorite-manager" role="group" aria-label="Deine Favoriten">
            {items.map(([key, label]) => (
              <div className="preference-row" key={key}>
                <span>{label}</span>
                <Toggle
                  group
                  label={label}
                  checked={value.favorites.includes(key)}
                  onChange={() =>
                    save({
                      ...value,
                      favorites: value.favorites.includes(key)
                        ? value.favorites.filter((k) => k !== key)
                        : [...value.favorites, key],
                    })
                  }
                />
              </div>
            ))}
          </div>
        )}
      </section>
      {canExport && (
        <section className="panel preference-card" aria-labelledby="export-heading">
          <div className="preference-row">
            <div>
              <h2 id="export-heading">Export-Formate</h2>
              <small>Welche verfügbaren Formate bei Reservierungen angeboten werden.</small>
            </div>
            <Toggle
              label="Exporte anzeigen"
              checked={value.exportEnabled}
              onChange={() => save({ ...value, exportEnabled: !value.exportEnabled })}
            />
          </div>
          {value.exportEnabled && (
            <div className="favorite-manager">
              <div className="preference-row">
                <span>CSV · Reservierungen</span>
                <Toggle
                  label="CSV anbieten"
                  checked={value.csvEnabled}
                  onChange={() => save({ ...value, csvEnabled: !value.csvEnabled })}
                />
              </div>
              {!value.csvEnabled && (
                <p className="muted">
                  Kein Export-Format ausgewählt. Die Export-Schaltfläche wird ausgeblendet.
                </p>
              )}
            </div>
          )}
          <p className="muted">
            Das Reservierungsmodul stellt derzeit CSV bereit. Deine Exportberechtigung wird weiterhin vom
            Server geprüft.
          </p>
        </section>
      )}
    </div>
  );
}
