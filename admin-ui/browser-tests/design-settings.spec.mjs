import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
async function mock(page, readOnly = false) {
  const writes = [];
  let table = {
    id: 1,
    name: 'Fenster',
    capacity: 4,
    room_id: 1,
    active: true,
    shape: 'round',
    layout_x: 20,
    layout_y: 30,
  };
  await page.route('**/api/**', async (route) => {
    const req = route.request(),
      path = new URL(req.url()).pathname;
    let body = [];
    if (path.endsWith('/auth/me'))
      body = {
        id: 1,
        name: 'Anna',
        role: readOnly ? 'staff' : 'restaurant_admin',
        tenant_id: 1,
        permissions: readOnly
          ? ['reservation.read']
          : ['reservation.read', 'reservation.export', 'restaurant.configure', 'widget.manage'],
      };
    else if (path.endsWith('/csrf')) body = { token: 'csrf' };
    else if (path.endsWith('/profile')) body = { name: 'Restaurant', timezone: 'Europe/Berlin' };
    else if (path.endsWith('/rooms'))
      body = [{ id: 1, name: 'Terrasse', icon: 'terrace', location: 'Garten' }];
    else if (path.endsWith('/tables')) body = [table];
    else if (path.endsWith('/tables/1')) {
      writes.push(req.postDataJSON());
      table = { ...table, ...req.postDataJSON() };
      body = table;
    } else if (path.endsWith('/widget/7')) {
      writes.push(req.postDataJSON());
      body = { id: 7, updated: true };
    } else if (path.endsWith('/widget'))
      body = [
        {
          id: 7,
          origins: ['https://example.test'],
          expires_at: '2027-01-01',
          duration_minutes: 90,
          accent: '#cc794e',
          language: 'de',
          position: 'inline',
          max_party_size: 6,
          show_brand: true,
        },
      ];
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  return writes;
}
test('favorites persist in this portal and keep other permitted pages reachable', async ({ page }) => {
  await mock(page);
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Einstellungen', exact: true }).click();
  await page.getByRole('switch', { name: /Favoriten in der Navigation/ }).check();
  await page.getByRole('checkbox', { name: 'Tischplan', exact: true }).check();
  await page.reload();
  await expect(page.getByRole('button', { name: 'Tischplan', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Einstellungen', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: /Weitere/ }).click();
  await expect(page.getByRole('button', { name: 'Einstellungen', exact: true })).toBeVisible();
  const order = await page
    .getByRole('navigation', { name: 'Hauptnavigation' })
    .getByRole('button')
    .allTextContents();
  expect(order.indexOf('Tischplan')).toBeLessThan(order.indexOf('Weitere'));
  expect(order.indexOf('Weitere')).toBeLessThan(order.indexOf('Einstellungen'));
  expect(await page.evaluate(() => localStorage.getItem('platzhirsch.ui.v1.administration:1'))).toBeNull();
});
test('table layout saves keyboard movement and read-only users cannot reposition', async ({ page }) => {
  const writes = await mock(page);
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Tischplan', exact: true }).click();
  await page.getByLabel('Raum', { exact: true }).selectOption('1');
  await page.getByLabel(/Tische positionieren/).check();
  await page.getByRole('button', { name: 'Fenster, 4 Plätze, frei' }).press('ArrowRight');
  await expect.poll(() => writes.length).toBe(1);
  expect(writes[0]).toMatchObject({ layout_x: 25, layout_y: 30, shape: 'round', room_id: 1 });
  await mkdir('test-results', { recursive: true });
  await page.screenshot({
    path: 'test-results/design-table-plan.png',
    fullPage: true,
    animations: 'disabled',
  });
});
test('read-only table plan offers no layout controls', async ({ page }) => {
  await mock(page, true);
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Tischplan', exact: true }).click();
  await expect(page.getByLabel(/Tische positionieren/)).toHaveCount(0);
});
test('widget designer edits appearance without issuing a new link', async ({ page }) => {
  const writes = await mock(page);
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Widget', exact: true }).click();
  await page.getByRole('button', { name: 'Gestaltung bearbeiten', exact: true }).click();
  await page.getByLabel('Sprache', { exact: true }).selectOption('en');
  await page.getByLabel('Position', { exact: true }).selectOption('bottom-right');
  await page.getByLabel('Max. Personenzahl', { exact: true }).fill('5');
  await expect(page.getByText('Up to 5 guests')).toBeVisible();
  await expect(page.getByLabel('Website-Ursprung', { exact: false })).toBeDisabled();
  await expect(page.getByLabel('Website-Ursprung', { exact: false })).toHaveValue('https://example.test');
  await mkdir('test-results', { recursive: true });
  await page.screenshot({ path: 'test-results/design-widget.png', fullPage: true, animations: 'disabled' });
  await page.getByRole('button', { name: 'Einstellungen speichern', exact: true }).click();
  await expect.poll(() => writes.length).toBe(1);
  expect(writes[0]).toMatchObject({ language: 'en', position: 'bottom-right', max_party_size: 5 });
  await expect(page.getByText(/Widget-Einstellungen gespeichert/)).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Dein neuer Buchungslink' })).toHaveCount(0);
});

test('template preference cards control CSV visibility, survive reload and remain portal scoped', async ({
  page,
}) => {
  await mock(page);
  await page.goto('/restaurant/login');
  await expect(page.getByRole('button', { name: 'CSV', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'Einstellungen', exact: true }).click();
  await page.getByRole('switch', { name: 'CSV anbieten', exact: true }).uncheck();
  await page.getByRole('button', { name: 'Reservierungen', exact: true }).click();
  await expect(page.getByRole('button', { name: 'CSV', exact: true })).toHaveCount(0);
  await page.reload();
  await expect(page.getByRole('button', { name: 'CSV', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Einstellungen', exact: true }).click();
  await page.getByRole('switch', { name: 'CSV anbieten', exact: true }).check();
  await page.getByRole('switch', { name: 'Exporte anzeigen', exact: true }).uncheck();
  await expect(page.getByRole('switch', { name: 'CSV anbieten', exact: true })).toHaveCount(0);
  await page.getByRole('switch', { name: 'Exporte anzeigen', exact: true }).check();
  await expect(page.getByRole('switch', { name: 'CSV anbieten', exact: true })).toBeChecked();
  await page.getByRole('switch', { name: 'Favoriten in der Navigation', exact: true }).check();
  await page.getByRole('checkbox', { name: 'Tischplan', exact: true }).check();
  await page.getByRole('button', { name: /Weitere Bereiche ausklappen/ }).click();
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({
    path: 'test-results/design-preferences-desktop.png',
    fullPage: true,
    animations: 'disabled',
  });
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await page.screenshot({
    path: 'test-results/design-preferences-mobile.png',
    fullPage: true,
    animations: 'disabled',
  });
  expect(await page.evaluate(() => localStorage.getItem('platzhirsch.ui.v1.administration:1'))).toBeNull();
});
test('export settings do not appear without the export permission', async ({ page }) => {
  await mock(page, true);
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Einstellungen', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Export-Formate', exact: true })).toHaveCount(0);
});

test('interactive designer completes a sample booking, supports back and sends nothing', async ({ page }) => {
  const writes = await mock(page);
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Widget', exact: true }).click();
  await page.getByRole('button', { name: 'Gestaltung bearbeiten', exact: true }).click();
  const preview = page.getByLabel('Beispielbuchung', { exact: true });
  await preview.getByLabel('Beispieldatum', { exact: true }).fill('2027-05-20');
  await preview.getByRole('button', { name: 'Verfügbare Tische anzeigen' }).click();
  await preview.getByLabel('Beispieltisch wählen').selectOption('Terrasse');
  await preview.getByRole('button', { name: 'Weiter zu Kontaktdaten' }).click();
  await preview.getByLabel('Beispielname', { exact: true }).fill('Anna Beispiel');
  await preview.getByLabel('Beispiel-E-Mail', { exact: true }).fill('example@example.test');
  await preview.getByRole('button', { name: 'Zurück', exact: true }).click();
  await expect(preview.getByLabel('Beispieltisch wählen')).toHaveValue('Terrasse');
  await preview.getByRole('button', { name: 'Weiter zu Kontaktdaten' }).click();
  await preview.getByRole('button', { name: 'Beispielbuchung bestätigen' }).click();
  await expect(preview.getByRole('heading', { name: 'Beispielbuchung bestätigt' })).toBeVisible();
  expect(writes).toHaveLength(0);
  await page.screenshot({ path: 'test-results/design-widget-confirmation.png', fullPage: true });
  await preview.getByRole('button', { name: 'Neue Beispielbuchung' }).click();
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: 'test-results/design-widget-interactive-mobile.png', fullPage: true });
  const overflow = await page.evaluate(() =>
    Array.from(document.querySelectorAll('*'))
      .filter((el) => el.getBoundingClientRect().right > 395 && getComputedStyle(el).display !== 'none')
      .slice(0, 25)
      .map((el) => ({
        tag: el.tagName,
        cls: el.className,
        width: el.getBoundingClientRect().width,
        right: el.getBoundingClientRect().right,
        overflow: getComputedStyle(el).overflowX,
      })),
  );
  console.log('Widget layout bounds', JSON.stringify(overflow));
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
});
test('profile menu opens actual profile editor and session can be extended', async ({ page }) => {
  await mock(page);
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Sitzung verlängern' }).click();
  await page.getByRole('button', { name: 'Profilmenü öffnen' }).click();
  await page.screenshot({ path: 'test-results/design-profile-menu.png', fullPage: true });
  await page.getByRole('button', { name: 'Profil bearbeiten', exact: true }).click();
  await expect(page.getByLabel('Name', { exact: true })).toHaveValue('Anna');
  await expect(page.getByLabel('Aktuelles Passwort', { exact: true })).toBeVisible();
  await page.screenshot({ path: 'test-results/design-profile-editor.png', fullPage: true });
});
