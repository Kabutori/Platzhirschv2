document.querySelectorAll('[data-registration-form]').forEach((form) => {
  form.addEventListener('submit', () => {
    if (!form.checkValidity()) return;
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    form.querySelector('[data-submit-label]').textContent = 'Bitte einen Moment …';
    form.querySelector('[data-submit-status]').textContent = 'Ihre Angaben werden geprüft. Bitte lassen Sie diese Seite geöffnet.';
  });
});
window.addEventListener('pageshow', () => {
  document.querySelectorAll('[data-registration-form] button[type="submit"]').forEach((button) => {
    button.disabled = false;
  });
});
document.querySelector('[role="alert"][tabindex]')?.focus();
