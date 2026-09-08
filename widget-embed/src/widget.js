(() => {
  'use strict';
  const origin = new URL(document.currentScript.src).origin;
  const css = '__WIDGET_CSS__';
  const english = {
    Tischreservierung: 'Table reservation',
    'Tisch reservieren': 'Reserve a table',
    'Lade Restaurant …': 'Loading restaurant …',
    'Erneut laden': 'Retry',
    'Datum und Uhrzeit': 'Date and time',
    Personen: 'Guests',
    'Verfügbare Tische anzeigen': 'Show available tables',
    Tisch: 'Table',
    'Zuerst Verfügbarkeit prüfen': 'Check availability first',
    'Dein Name': 'Your name',
    'E-Mail': 'Email',
    Telefon: 'Phone',
    Wünsche: 'Requests',
    'Meine Angaben dürfen zur Bearbeitung dieser Reservierung verwendet werden.':
      'My details may be used to process this reservation.',
    'Verbindlich reservieren': 'Confirm reservation',
    'Verfügbarkeit erneut prüfen': 'Check availability again',
    'Prüfe freie Tische …': 'Checking available tables …',
    'Bitte einen Tisch wählen': 'Select a table',
    'Bitte einen Tisch auswählen. Verfügbarkeit wird beim Buchen erneut geprüft.':
      'Please select a table. Availability is checked again when booking.',
    'Zu dieser Zeit ist kein passender Tisch frei. Bitte eine andere Zeit wählen.':
      'No suitable table is available at this time. Please choose another time.',
    'Reservierung wird gespeichert …': 'Saving reservation …',
    'Bitte Angaben prüfen oder erneut versuchen.': 'Please check your details or try again.',
    'Die Reservierung konnte nicht geladen werden.': 'The reservation form could not be loaded.',
    'Anfrage fehlgeschlagen.': 'Request failed.',
    Schließen: 'Close',
  };
  if (customElements.get('platzhirsch-booking')) return;
  class BookingWidget extends HTMLElement {
    constructor() {
      super();
      this.root = this.attachShadow({ mode: 'open' });
      this.requestKey = crypto.randomUUID();
      this.generation = 0;
    }
    connectedCallback() {
      this.controller?.abort();
      this.controller = new AbortController();
      this.initialize();
    }
    disconnectedCallback() {
      this.controller?.abort();
    }
    t(text) {
      return this.config?.language === 'en' ? english[text] || text : text;
    }
    async request(path = '', data) {
      const response = await fetch(`${origin}/api/widget/${this.token}${path}`, {
        method: data ? 'POST' : 'GET',
        credentials: 'omit',
        signal: this.controller.signal,
        headers: data
          ? { Accept: 'application/json', 'Content-Type': 'application/json' }
          : { Accept: 'application/json' },
        body: data ? JSON.stringify(data) : undefined,
      });
      const body = await response.json();
      if (!response.ok)
        throw new Error(
          body.errors
            ? Object.values(body.errors).flat().join(' ')
            : body.message || this.t('Anfrage fehlgeschlagen.'),
        );
      return body;
    }
    async initialize() {
      this.token = this.getAttribute('token') || '';
      this.root.innerHTML = `<style></style><section aria-label="Tischreservierung">
        <h2>Tisch reservieren</h2><p class="muted" id="hint">Lade Restaurant …</p>
        <p role="alert" id="error"></p><p role="status" id="status"></p>
        <button id="retry" type="button" hidden>Erneut laden</button>
        <form hidden><fieldset>
          <label>Datum und Uhrzeit<input name="starts_at" type="datetime-local" required></label>
          <label>Personen<input name="party_size" type="number" min="1" max="50" value="2" required></label>
          <button id="check" type="button">Verfügbare Tische anzeigen</button>
          <label>Tisch<select name="table_id" aria-label="Tisch" required disabled><option value="">Zuerst Verfügbarkeit prüfen</option></select></label>
          <label>Dein Name<input name="guest_name" autocomplete="name" maxlength="120" required></label>
          <label>E-Mail<input name="email" type="email" autocomplete="email" maxlength="254" required></label>
          <label>Telefon<input name="phone" type="tel" autocomplete="tel" maxlength="50"></label>
          <label>Wünsche<textarea name="notes" maxlength="2000"></textarea></label>
          <label class="trap" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label>
          <label class="consent"><input name="consent" type="checkbox" required>Meine Angaben dürfen zur Bearbeitung dieser Reservierung verwendet werden.</label>
          <button id="book" type="submit" disabled>Verbindlich reservieren</button>
        </fieldset></form></section>`;
      this.root.querySelector('style').textContent = css;
      this.error = this.root.querySelector('#error');
      this.status = this.root.querySelector('#status');
      const retry = this.root.querySelector('#retry');
      retry.onclick = () => this.initialize();
      try {
        if (!/^[a-f0-9]{64}$/.test(this.token))
          throw new Error('Ungültiger Buchungszugang. Bitte das Restaurant kontaktieren.');
        this.config = await this.request();
        if (!this.isConnected) return;
        this.root.querySelector('h2').textContent = this.config.name;
        const section = this.root.querySelector('section');
        section.lang = this.config.language === 'en' ? 'en' : 'de';
        const walker = document.createTreeWalker(section, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
          const node = walker.currentNode;
          const trimmed = node.textContent.trim();
          if (english[trimmed]) node.textContent = node.textContent.replace(trimmed, this.t(trimmed));
        }
        section
          .querySelectorAll('[aria-label]')
          .forEach((node) => node.setAttribute('aria-label', this.t(node.getAttribute('aria-label'))));
        if (this.config.show_brand !== false) {
          const brand = document.createElement('p');
          brand.className = 'widget-brand';
          brand.textContent = 'P · Platzhirsch';
          section.prepend(brand);
        }

        this.root.querySelector('#hint').textContent =
          this.config.language === 'en'
            ? `Times in ${this.config.timezone} · Duration: ${this.config.duration_minutes} minutes`
            : `Uhrzeiten in ${this.config.timezone} · Reservierungsdauer: ${this.config.duration_minutes} Minuten`;
        if (/^#[a-f0-9]{6}$/i.test(this.config.accent || ''))
          this.style.setProperty('--accent', this.config.accent);
        this.form = this.root.querySelector('form');
        this.form.hidden = false;
        this.form.elements.party_size.max = String(this.config.max_party_size || 50);
        this.form.elements.party_size.value = String(Math.min(2, this.config.max_party_size || 50));
        if (['bottom-right', 'bottom-left', 'top-right', 'top-left'].includes(this.config.position)) {
          this.setAttribute('data-placement', this.config.position);
          const dialog = document.createElement('dialog');
          dialog.setAttribute('aria-label', this.config.name);
          const close = document.createElement('button');
          close.type = 'button';
          close.textContent = this.t('Schließen');
          close.onclick = () => dialog.close();
          const launch = document.createElement('button');
          launch.type = 'button';
          launch.className = 'widget-launch';
          launch.textContent = this.t('Tisch reservieren');
          launch.onclick = () => dialog.showModal();
          dialog.append(close, section);
          this.root.append(launch, dialog);
        } else this.removeAttribute('data-placement');

        this.table = this.form.elements.table_id;
        this.book = this.root.querySelector('#book');
        for (const name of ['starts_at', 'party_size'])
          this.form.elements[name].addEventListener('input', () => {
            this.generation++;
            this.table.replaceChildren(new Option(this.t('Verfügbarkeit erneut prüfen'), ''));
            this.table.disabled = true;
            this.book.disabled = true;
            this.status.textContent = '';
          });
        this.root.querySelector('#check').onclick = () => this.check();
        this.table.onchange = () => {
          this.book.disabled = !this.table.value;
        };
        this.form.onsubmit = (event) => {
          event.preventDefault();
          this.submit();
        };
      } catch (error) {
        if (error.name === 'AbortError') return;
        this.error.textContent = error.message;
        this.root.querySelector('#hint').textContent = this.t(
          'Die Reservierung konnte nicht geladen werden.',
        );
        retry.hidden = false;
      }
    }
    async check() {
      const date = this.form.elements.starts_at;
      const party = this.form.elements.party_size;
      if (!date.reportValidity() || !party.reportValidity()) return;
      const generation = ++this.generation;
      this.error.textContent = '';
      this.book.disabled = true;
      this.table.disabled = true;
      this.status.textContent = this.t('Prüfe freie Tische …');
      try {
        const query = new URLSearchParams({ starts_at: date.value, party_size: party.value });
        const result = await this.request('/availability?' + query);
        if (generation !== this.generation) return;
        this.table.replaceChildren(new Option(this.t('Bitte einen Tisch wählen'), ''));
        for (const table of result.tables)
          this.table.add(
            new Option(
              `${table.name} · ${table.capacity} ${this.config.language === 'en' ? 'seats' : 'Plätze'}`,
              String(table.id),
            ),
          );
        this.table.disabled = !result.tables.length;
        this.status.textContent = result.tables.length
          ? this.t('Bitte einen Tisch auswählen. Verfügbarkeit wird beim Buchen erneut geprüft.')
          : this.t('Zu dieser Zeit ist kein passender Tisch frei. Bitte eine andere Zeit wählen.');
      } catch (error) {
        if (generation !== this.generation || error.name === 'AbortError') return;
        this.error.textContent = error.message;
        this.status.textContent = '';
      }
    }
    async submit() {
      if (this.submitting || !this.table.value || !this.form.reportValidity()) return;
      this.submitting = true;
      const data = Object.fromEntries(new FormData(this.form));
      data.party_size = Number(data.party_size);
      data.table_id = Number(data.table_id);
      data.consent = this.form.elements.consent.checked;
      data.request_key = this.requestKey;
      data.duration_minutes = this.config.duration_minutes;
      this.form.querySelector('fieldset').disabled = true;
      this.error.textContent = '';
      this.status.textContent = this.t('Reservierung wird gespeichert …');
      try {
        const result = await this.request('', data);
        this.form.hidden = true;
        this.status.textContent =
          this.config.language === 'en'
            ? `Your table is reserved. Booking number: ${result.id}. Please keep this number. Contact the restaurant for changes.`
            : `Dein Tisch ist reserviert. Buchungsnummer: ${result.id}. Bitte notiere diese Nummer. Für Änderungen kontaktiere das Restaurant.`;
        this.status.setAttribute('tabindex', '-1');
        this.status.focus();
        this.dispatchEvent(
          new CustomEvent('platzhirsch:booked', { detail: { id: result.id }, bubbles: true, composed: true }),
        );
      } catch (error) {
        if (error.name !== 'AbortError') {
          this.error.textContent = error.message;
          this.status.textContent = this.t('Bitte Angaben prüfen oder erneut versuchen.');
        }
      } finally {
        this.submitting = false;
        this.form.querySelector('fieldset').disabled = false;
      }
    }
  }
  customElements.define('platzhirsch-booking', BookingWidget);
})();
