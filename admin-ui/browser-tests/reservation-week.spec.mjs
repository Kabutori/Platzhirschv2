import { test, expect } from '@playwright/test';
async function mock(page, writable = true, failDay = false) {
  const dates = [];
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    let body = [];
    let status = 200;
    if (url.pathname.endsWith('/auth/me'))
      body = {
        id: 1,
        name: 'Anna',
        role: writable ? 'restaurant_admin' : 'staff',
        tenant_id: 1,
        permissions: ['reservation.read', ...(writable ? ['reservation.write'] : [])],
      };
    else if (url.pathname.endsWith('/profile')) body = { name: 'Restaurant', timezone: 'Europe/Berlin' };
    else if (url.pathname.endsWith('/tables')) body = [{ id: 1, name: 'Fenster', capacity: 4, active: true }];
    else if (url.pathname.endsWith('/reservations')) {
      const date = url.searchParams.get('date');
      dates.push(date);
      if (failDay && date === '2025-12-31') {
        status = 503;
        body = { message: 'Tagesdaten vorübergehend nicht verfügbar' };
      } else if (['2025-12-29', '2026-01-02'].includes(date))
        body = [
          {
            id: date === '2025-12-29' ? 1 : 2,
            version: 3,
            guest_name: date === '2025-12-29' ? 'Alice' : 'Bob',
            party_size: 2,
            table_id: 1,
            table_name: 'Fenster',
            starts_at: date + ' 17:00:00',
            ends_at: date + ' 18:30:00',
            status: date === '2025-12-29' ? 'confirmed' : 'seated',
            notes: 'Fensterplatz bitte',
          },
        ];
    }
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  });
  return dates;
}
test('week spans the year boundary, supports filters and opens the selected day', async ({ page }) => {
  const dates = await mock(page);
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Reservierungen', exact: true }).click();
  await page.getByLabel('Reservierungsdatum', { exact: true }).fill('2026-01-01');
  await page.getByRole('button', { name: 'Woche', exact: true }).click();
  await expect(page.getByRole('button', { name: /Reservierung bearbeiten: Alice/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /Reservierung bearbeiten: Bob/ })).toBeVisible();
  expect(dates).toEqual(
    expect.arrayContaining([
      '2025-12-29',
      '2025-12-30',
      '2025-12-31',
      '2026-01-01',
      '2026-01-02',
      '2026-01-03',
      '2026-01-04',
    ]),
  );
  await page.getByLabel('Status', { exact: true }).selectOption('seated');
  await expect(page.getByRole('button', { name: /Reservierung bearbeiten: Alice/ })).toHaveCount(0);
  await page.getByLabel('Suche nach Gast oder Tisch', { exact: true }).fill('bob');
  await expect(page.getByRole('button', { name: /Reservierung bearbeiten: Bob/ })).toBeVisible();
  await page.getByRole('button', { name: 'Filter zurücksetzen', exact: true }).click();
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({
    path: 'test-results/design-reservation-week.png',
    fullPage: true,
    animations: 'disabled',
  });
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await page.screenshot({
    path: 'test-results/design-reservation-week-mobile.png',
    fullPage: true,
    animations: 'disabled',
  });
  await page.getByRole('button', { name: 'Tagesliste öffnen: Freitag, 2. Januar', exact: true }).click();
  await expect(page.getByLabel('Reservierungsdatum', { exact: true })).toHaveValue('2026-01-02');
  await expect(page.getByRole('cell', { name: 'Bob', exact: false })).toBeVisible();
  await expect(page.getByRole('cell', { name: 'Fensterplatz bitte', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Vorheriger Tag', exact: true }).click();
  await expect(page.getByLabel('Reservierungsdatum', { exact: true })).toHaveValue('2026-01-01');
  await page.getByLabel('Reservierungsdatum', { exact: true }).fill('2026-03-30');
  await page.getByRole('button', { name: 'Vorheriger Tag', exact: true }).click();
  await expect(page.getByLabel('Reservierungsdatum', { exact: true })).toHaveValue('2026-03-29');
});
test('read-only weekly cards cannot edit and failed days are not shown as empty', async ({ page }) => {
  await mock(page, false, true);
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Reservierungen', exact: true }).click();
  await page.getByLabel('Reservierungsdatum', { exact: true }).fill('2026-01-01');
  await page.getByRole('button', { name: 'Woche', exact: true }).click();
  await expect(page.getByText('Alice', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: /Reservierung bearbeiten:/ })).toHaveCount(0);
  const failed = page.getByRole('region', { name: 'Mittwoch, 31. Dezember', exact: true });
  await expect(failed.getByRole('alert')).toContainText('Tagesdaten vorübergehend nicht verfügbar');
  await expect(failed.getByText('Keine Reservierungen.', { exact: true })).toHaveCount(0);
});
