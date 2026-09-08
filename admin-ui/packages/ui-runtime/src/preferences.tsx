import { createContext, useContext, useState, type ReactNode } from 'react';
export type Preferences = {
  favoritesEnabled: boolean;
  favorites: string[];
  collapsed: boolean;
  backdropClose: boolean;
};
const defaults: Preferences = {
  favoritesEnabled: false,
  favorites: [],
  collapsed: false,
  backdropClose: false,
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
export function PreferencesPage({ items }: { items: readonly (readonly [string, string, ...unknown[]])[] }) {
  const { value, save, error } = usePreferences();
  return (
    <section className="panel padded">
      <h2>Verhalten</h2>
      <p className="muted">Für dein Konto und dieses Portal in diesem Browser gespeichert.</p>
      {error && <p role="alert">{error}</p>}
      <label className="preference-row">
        <span>
          <strong>Dialoge durch Klick auf Hintergrund schließen</strong>
          <small>
            Gilt für die allgemeinen Bearbeitungsdialoge. Sicherheits- und Zugangsdaten-Dialoge bleiben
            ausgenommen.
          </small>
        </span>
        <input
          role="switch"
          type="checkbox"
          checked={value.backdropClose}
          onChange={(e) => save({ ...value, backdropClose: e.target.checked })}
        />
      </label>
      <label className="preference-row">
        <span>
          <strong>Favoriten in der Navigation</strong>
          <small>
            Ausgewählte Menüpunkte stehen oben. Weitere erlaubte Bereiche bleiben unter „Weitere“ erreichbar.
          </small>
        </span>
        <input
          role="switch"
          type="checkbox"
          checked={value.favoritesEnabled}
          onChange={(e) => save({ ...value, favoritesEnabled: e.target.checked })}
        />
      </label>
      {value.favoritesEnabled && (
        <fieldset>
          <legend>Deine Favoriten</legend>
          <div className="favorites-grid">
            {items.map(([key, label]) => (
              <label key={key}>
                <input
                  type="checkbox"
                  checked={value.favorites.includes(key)}
                  onChange={(e) =>
                    save({
                      ...value,
                      favorites: e.target.checked
                        ? [...value.favorites, key]
                        : value.favorites.filter((k) => k !== key),
                    })
                  }
                />
                {label}
              </label>
            ))}
          </div>
        </fieldset>
      )}
      <p className="muted">
        Favoriten ändern keine Zugriffsrechte. Der Guide bleibt unter „System verstehen“ erreichbar.
      </p>
    </section>
  );
}
