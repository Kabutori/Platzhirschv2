import { test, expect } from '@playwright/test';
test('waitlist conversion preserves entry on conflict and supports table combinations in booking dialog', async ({
  page,
}) => {
  let converted = false,
    attempts = 0;
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    let body = [],
      status = 200;
    if (url.pathname.endsWith('/auth/me'))
      body = { id: 1, name: 'Anna', role: 'restaurant_admin', tenant_id: 1, permissions: ['*'] };
    else if (url.pathname.endsWith('/csrf')) body = { token: 'test' };
    else if (url.pathname.endsWith('/profile')) body = { name: 'Restaurant', timezone: 'Europe/Berlin' };
    else if (url.pathname.endsWith('/tables'))
      body = [
        { id: 1, name: 'Fenster', capacity: 4, active: true },
        { id: 2, name: 'Mitte', capacity: 4, active: true },
      ];
    else if (url.pathname.endsWith('/waitlist'))
      body = [
        {
          id: 1,
          guest_name: 'Maya',
          party_size: 2,
          requested_at: '2026-09-10 16:00:00',
          duration_minutes: 90,
          status: converted ? 'booked' : 'waiting',
          version: 1,
        },
      ];
    else if (url.pathname.endsWith('/book')) {
      attempts++;
      if (attempts === 1) {
        status = 409;
        body = { message: 'Dieser Tisch ist in dem Zeitraum bereits reserviert.' };
      } else {
        converted = true;
        body = { reservation_id: 5 };
      }
    }
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Warteliste', exact: true }).click();
  await expect(page.getByText('Maya', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'In Reservierung übernehmen', exact: true }).click();
  await page.getByRole('dialog').getByLabel('Tisch', { exact: false }).selectOption('1');
  await page.getByRole('dialog').getByRole('button', { name: 'Speichern', exact: false }).click();
  await expect(page.getByRole('dialog').getByRole('alert')).toContainText('bereits reserviert');
  await page.getByRole('dialog').getByRole('button', { name: 'Speichern', exact: false }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await page.getByLabel('Wartelistenstatus', { exact: true }).selectOption('all');
  await expect(page.getByRole('cell', { name: 'Übernommen', exact: true })).toBeVisible();
  await page.screenshot({ path: 'test-results/design-waitlist.png', fullPage: true, animations: 'disabled' });
  await page.getByRole('button', { name: 'Reservierungen', exact: true }).click();
  await page.getByRole('button', { name: 'Reservierung', exact: true }).click();
  const combo = page.getByLabel('Weitere Tische kombinieren', { exact: false });
  await combo.selectOption(['1', '2']);
  await expect(combo).toHaveValues(['1', '2']);
});
