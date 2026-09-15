export default function OdooPlaceholder() {
  return (
    <section className="panel padded">
      <p className="eyebrow">INTEGRATION · VORBEREITET</p>
      <h2>Odoo</h2>
      <p className="notice">
        Platzhalter – die Odoo-Anbindung ist nicht installiert. Es werden keine Daten übertragen oder
        Zugangsdaten gespeichert.
      </p>
      <div className="fields">
        <label>
          Odoo-Adresse
          <input disabled placeholder="https://odoo.example.de" />
        </label>
        <label>
          Datenbank
          <input disabled placeholder="Odoo-Datenbank" />
        </label>
      </div>
      <div className="toolbar">
        <button disabled>Verbindung testen</button>
        <button disabled>Synchronisation aktivieren</button>
      </div>
      <h3>Geplanter Umfang</h3>
      <ul>
        <li>Kunden und Ansprechpartner</li>
        <li>Reservierungen über eine noch festzulegende Modellzuordnung</li>
        <li>Rechnungsabgleich</li>
      </ul>
      <p>
        Die spätere Umsetzung erfolgt als eigenständiges Integrationsmodul mit verschlüsselten API-Schlüsseln,
        freigegebenen Zieladressen und Hintergrundaufträgen. Kauf und Aktivierung werden erst angeboten, wenn
        das Modul ausführbar ist.
      </p>
    </section>
  );
}
