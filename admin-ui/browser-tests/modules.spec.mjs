import { test, expect } from '@playwright/test';
async function mock(page, enabled = false) {
  const writes = [];
  let pending = false;
  await page.route('**/api/**', async (route) => {
    const r = route.request();
    const path = new URL(r.url()).pathname;
    let body = {};
    if (path.endsWith('/auth/me'))
      body = {
        id: 2,
        name: 'Restaurantleitung',
        role: 'restaurant_admin',
        tenant_id: 1,
        permissions: ['modules.manage', 'reporting.read'],
        enabled_modules: enabled ? ['reporting'] : [],
      };
    else if (path.endsWith('/csrf')) body = { token: 'csrf' };
    else if (path.endsWith('/restaurant/modules'))
      body = {
        core_modules: ['identity', 'customer', 'reservation', 'widget', 'support'],
        products: [{ module_code: 'reporting', available: true, amount_cents: 1900, currency: 'EUR' }],
        orders: pending
          ? [{ id: 1, module_code: 'reporting', amount_cents: 1900, currency: 'EUR', status: 'pending' }]
          : [],
        entitlements: [],
      };
    else if (path.endsWith('/modules/orders')) {
      writes.push(r.postDataJSON());
      pending = true;
      body = { id: 1 };
    } else if (path.endsWith('/reporting')) body = { days: [], saved: [] };
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  return writes;
}
test('shop separates purchase from activation and sends the displayed price', async ({ page }) => {
  const writes = await mock(page);
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Modul-Shop', exact: true }).click();
  await expect(
    page.getByRole('switch', { name: 'Erweiterte Auswertungen aktivieren', exact: true }),
  ).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Erweiterte Auswertungen', exact: true })).toHaveCount(0);
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: 'test-results/modules-desktop.png', fullPage: true });
  page.once('dialog', (d) => d.accept());
  await page.getByRole('button', { name: 'Zahlungspflichtig bestellen', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Bestätigung ausstehend', exact: true })).toBeDisabled();
  expect(writes).toHaveLength(1);
  expect(writes[0].expected_amount_cents).toBe(1900);
  await expect(
    page.getByRole('switch', { name: 'Erweiterte Auswertungen aktivieren', exact: true }),
  ).toBeDisabled();
  await page.setViewportSize({ width: 390, height: 844 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await page.screenshot({ path: 'test-results/modules-mobile.png', fullPage: true });
});
test('reporting navigation requires activation and report readers cannot save', async ({ page }) => {
  await mock(page, true);
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Erweiterte Auswertungen', exact: true }).click();
  await expect(page.getByText('Keine Reservierungen im Zeitraum.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Zeitraum speichern', exact: true })).toHaveCount(0);
});
