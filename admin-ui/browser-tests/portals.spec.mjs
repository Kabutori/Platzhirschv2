import { test, expect } from '@playwright/test';
test('portal login pages use their own identity and API context', async ({ page }) => {
  const portals = [];
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/auth/me')) {
      portals.push(route.request().headers()['x-platzhirsch-portal']);
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: '{"message":"Unauthenticated"}',
      });
    } else await route.fulfill({ contentType: 'application/json', body: '{"bootstrapped":true}' });
  });
  await page.goto('/administration/login');
  await expect(page.getByText('SYSTEM-ADMINISTRATION', { exact: true })).toBeVisible();
  await page.goto('/restaurant/login');
  await expect(page.getByText('RESERVIERUNGSSYSTEM FÜR RESTAURANTS', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Passwort anzeigen', exact: true }).click();
  await expect(page.getByLabel('Passwort', { exact: true })).toHaveAttribute('type', 'text');
  expect(portals).toContain('administration');
  expect(portals).toContain('restaurant');
});
