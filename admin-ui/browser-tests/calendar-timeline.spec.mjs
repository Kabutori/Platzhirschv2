import { test, expect } from '@playwright/test';
async function open(page, writable = true, failPrevious = false) {
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    let body = [],
      status = 200;
    if (url.pathname.endsWith('/auth/me'))
      body = {
        id: 1,
        name: 'Anna',
        role: 'staff',
        tenant_id: 1,
        permissions: ['reservation.read', ...(writable ? ['reservation.write'] : [])],
      };
    else if (url.pathname.endsWith('/profile')) body = { name: 'Restaurant', timezone: 'Europe/Berlin' };
    else if (url.pathname.endsWith('/rooms'))
      body = [
        { id: 1, name: 'Gastraum' },
        { id: 2, name: 'Terrasse' },
      ];
    else if (url.pathname.endsWith('/tables'))
      body = [
        { id: 1, room_id: 1, name: 'Fenster', capacity: 4, active: true },
        { id: 2, room_id: 2, name: 'Garten', capacity: 2, active: true },
      ];
    else if (url.pathname.endsWith('/reservations')) {
      const date = url.searchParams.get('date');
      if (date === '2026-01-02')
        body = [
          {
            id: 1,
            version: 1,
            table_id: 1,
            guest_name: 'Anna',
            party_size: 2,
            status: 'confirmed',
            starts_at: '2026-01-02 17:00:00',
            ends_at: '2026-01-02 19:00:00',
          },
          {
            id: 2,
            table_id: 2,
            guest_name: 'Storniert',
            party_size: 2,
            status: 'cancelled',
            starts_at: '2026-01-02 17:00:00',
            ends_at: '2026-01-02 19:00:00',
          },
        ];
      if (date === '2026-01-01') {
        body = [
          {
            id: 3,
            version: 1,
            table_id: 1,
            guest_name: 'Spätgast',
            party_size: 2,
            status: 'confirmed',
            starts_at: '2026-01-01 22:00:00',
            ends_at: '2026-01-02 01:00:00',
          },
        ];
        if (failPrevious) {
          status = 503;
          body = { message: 'Vortag nicht verfügbar' };
        }
      }
    }
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/restaurant/login');
}
test('calendar selects leap day, crosses years, dismisses and returns focus', async ({ page }) => {
  await open(page);
  await page.getByRole('button', { name: 'Reservierungen', exact: true }).click();
  const input = page.getByLabel('Reservierungsdatum', { exact: true });
  const trigger = page.getByRole('button', { name: 'Kalender öffnen', exact: true });
  await input.fill('2028-02-15');
  await trigger.click();
  const dialog = page.getByRole('dialog', { name: 'Reservierungsdatum wählen' });
  await expect(dialog.getByRole('button', { name: '15. Februar 2028', exact: true })).toBeFocused();
  await dialog.getByRole('button', { name: '29. Februar 2028', exact: true }).click();
  await expect(input).toHaveValue('2028-02-29');
  await expect(trigger).toBeFocused();
  await input.fill('2025-12-31');
  await trigger.click();
  await dialog.getByRole('button', { name: 'Nächster Monat', exact: true }).click();
  await expect(dialog).toContainText('Januar 2026');
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.screenshot({ path: 'test-results/design-calendar.png', fullPage: true, animations: 'disabled' });
  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(trigger).toBeFocused();
  await trigger.click();
  await page.getByRole('heading', { name: 'Reservierungen', exact: true }).first().click();
  await expect(dialog).toHaveCount(0);
});
test('timeline shows clipped overnight bookings, rooms and editable bookings', async ({ page }) => {
  await open(page);
  await page.getByRole('button', { name: 'Tischplan', exact: true }).click();
  await page.getByLabel('Reservierungsdatum', { exact: true }).fill('2026-01-02');
  await page.getByRole('button', { name: 'Zeitstrahl', exact: true }).click();
  const timeline = page.getByRole('region', { name: 'Tisch-Zeitstrahl' });
  await expect(timeline.getByRole('button', { name: /Reservierung bearbeiten: Anna/ })).toBeVisible();
  await expect(timeline.getByRole('button', { name: /Reservierung bearbeiten: Spätgast/ })).toBeVisible();
  await expect(timeline.getByText('Storniert', { exact: true })).toHaveCount(0);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.screenshot({ path: 'test-results/design-timeline.png', fullPage: true, animations: 'disabled' });
  await page.getByLabel('Raum', { exact: true }).selectOption('2');
  await expect(timeline.getByText('Fenster', { exact: false })).toHaveCount(0);
  await expect(timeline.getByText('Keine Reservierungen', { exact: true })).toBeVisible();
  await page.getByLabel('Raum', { exact: true }).selectOption('all');
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await page.screenshot({
    path: 'test-results/design-timeline-mobile.png',
    fullPage: true,
    animations: 'disabled',
  });
  await timeline.getByRole('button', { name: /Reservierung bearbeiten: Anna/ }).click();
  await expect(page.getByRole('dialog')).toBeVisible();
});
test('read-only timeline and failed overnight data never imply availability', async ({ page }) => {
  await open(page, false, true);
  await page.getByRole('button', { name: 'Tischplan', exact: true }).click();
  await page.getByLabel('Reservierungsdatum', { exact: true }).fill('2026-01-02');
  await page.getByRole('button', { name: 'Zeitstrahl', exact: true }).click();
  await expect(page.getByRole('alert')).toContainText('Vortag nicht verfügbar');
  await expect(page.getByRole('region', { name: 'Tisch-Zeitstrahl' })).toHaveCount(0);
  await page.unroute('**/api/**');
  await open(page, false);
  await page.getByRole('button', { name: 'Tischplan', exact: true }).click();
  await page.getByLabel('Reservierungsdatum', { exact: true }).fill('2026-01-02');
  await page.getByRole('button', { name: 'Zeitstrahl', exact: true }).click();
  const timeline = page.getByRole('region', { name: 'Tisch-Zeitstrahl' });
  await expect(timeline.getByText('Spätgast', { exact: true })).toBeVisible();
  await expect(timeline.getByRole('button')).toHaveCount(0);
});
