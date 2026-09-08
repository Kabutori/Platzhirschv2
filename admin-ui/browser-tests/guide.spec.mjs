import { test, expect } from '@playwright/test';
async function setup(page, role = 'system_admin') {
  const requests = [];
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    requests.push(path);
    let body = {};
    if (path.endsWith('/auth/me'))
      body = { id: 1, name: 'Guide Admin', role, permissions: role === 'system_admin' ? ['*'] : [] };
    else if (path.endsWith('/modules')) body = [];
    else if (path.endsWith('/dashboard')) body = { recent_audit: [] };
    else if (path.endsWith('/database-access'))
      body = [
        {
          id: 'platform',
          name: 'Plattform-Test',
          scope: 'Plattform',
          host: 'db.example.test',
          port: 3310,
          database: 'test_platform',
          status: 'active',
        },
      ];
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/administration/login');
  await page.getByRole('button', { name: 'System verstehen', exact: true }).click();
  return requests;
}
test('graph, learning journey and configured database metadata remain distinct', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  const requests = await setup(page);
  await expect(page.locator('.sg-connections g')).toHaveCount(5);
  const firstTab = await page.getByRole('button', { name: 'Systemkarte', exact: true }).boundingBox();
  const secondTab = await page.getByRole('button', { name: 'Dateien & Daten', exact: true }).boundingBox();
  expect(firstTab.y).toBe(secondTab.y);
  await page.locator('[data-guide-node=tenant]').click();
  await expect(page.locator('.sg-detail')).toContainText('ph_t_');
  await page.getByRole('button', { name: 'Buchung verfolgen', exact: true }).click();
  for (let i = 1; i < 5; i++)
    await page.getByRole('button', { name: 'Nächster Schritt', exact: true }).click();
  await expect(page.locator('.sg-journey')).toContainText('5 / 5');
  await expect(page.getByRole('button', { name: 'Nächster Schritt', exact: true })).toBeDisabled();
  await page.getByRole('button', { name: 'Frei erkunden', exact: true }).click();
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: 'test-results/guide-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.locator('[data-guide-node=tenant]')).toBeVisible();
  await page.screenshot({ path: 'test-results/guide-mobile.png', fullPage: true });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  await page.getByRole('button', { name: 'Meine Datenbanken', exact: true }).click();
  await expect(page.locator('.sg-connection-list')).toContainText('db.example.test:3310');
  await expect(page.getByText('Dies ist keine Erreichbarkeitsprüfung', { exact: false })).toBeVisible();
  expect(requests.some((p) => p.includes('/reveal'))).toBe(false);
  expect(errors).toEqual([]);
});
test('platform staff can learn without reading system administrator connection data', async ({ page }) => {
  const requests = await setup(page, 'platform_staff');
  await expect(page.getByRole('button', { name: 'Meine Datenbanken', exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Windows verstehen', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Windows rund um Platzhirsch' })).toBeVisible();
  expect(requests.some((p) => p.includes('/database-access'))).toBe(false);
});
