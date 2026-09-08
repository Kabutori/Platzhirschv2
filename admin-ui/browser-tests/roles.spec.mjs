import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
const admin = {
  id: 1,
  name: 'Owner',
  role: 'restaurant_admin',
  tenant_id: 1,
  permissions: [
    'reservation.read',
    'reservation.write',
    'reservation.cancel',
    'reservation.export',
    'roles.manage',
    'team.manage',
    'restaurant.configure',
    'restaurant.profile',
    'widget.manage',
    'support.access',
  ],
};
async function mockApi(page, user = admin) {
  const saved = [];
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    let body = [];
    if (path.endsWith('/csrf')) body = { token: 'test-csrf' };
    else if (path.endsWith('/auth/me')) body = user;
    else if (path.endsWith('/profile')) body = { name: 'Test restaurant', timezone: 'Europe/Berlin' };
    else if (path.endsWith('/roles')) {
      if (route.request().method() === 'POST') {
        saved.push(route.request().postDataJSON());
        body = { id: 1 };
      } else
        body = {
          catalog: {
            'reservation.read': 'Reservierungen und Belegung ansehen',
            'reservation.write': 'Reservierungen anlegen und bearbeiten',
          },
          roles: [],
        };
    }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  return saved;
}
test('restaurant administrator creates a scoped employee role', async ({ page }) => {
  const saved = await mockApi(page);
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Rollen & Rechte', exact: true }).click();
  await page.getByRole('button', { name: 'Rolle anlegen', exact: true }).click();
  await page.getByLabel('Rollenname').fill('Empfang');
  await page.getByLabel('Reservierungen und Belegung ansehen').check();
  await mkdir('test-results', { recursive: true });
  await page.screenshot({
    path: 'test-results/roles-restaurant.png',
    fullPage: true,
    animations: 'disabled',
  });
  await page.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  expect(saved).toEqual([{ name: 'Empfang', permissions: ['reservation.read'] }]);
});
test('read-only role hides administration and disables booking and export', async ({ page }) => {
  await mockApi(page, { ...admin, role: 'staff', permissions: ['reservation.read'] });
  await page.goto('/restaurant/login');
  await expect(page.getByRole('button', { name: 'CSV', exact: true })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Reservierung', exact: true })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Rollen & Rechte', exact: true })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Team', exact: true })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Support', exact: true })).toHaveCount(0);
});
test('restaurant groups use module identity and show partial selection like platform roles', async ({
  page,
}) => {
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    let body = [];
    if (path.endsWith('/auth/me')) body = admin;
    else if (path.endsWith('/profile')) body = { timezone: 'Europe/Berlin' };
    else if (path.endsWith('/roles'))
      body = {
        catalog: {},
        roles: [{ id: 1, name: 'Empfang', version: 1, permissions: ['reservation.read'] }],
        families: [
          {
            module: 'reservation',
            code: 'manage',
            label: 'Reservierungen',
            permissions: [
              { code: 'reservation.read', label: 'Buchungen ansehen' },
              { code: 'reservation.write', label: 'Buchungen bearbeiten' },
            ],
          },
          {
            module: 'widget',
            code: 'manage',
            label: 'Widget',
            permissions: [{ code: 'widget.manage', label: 'Widget gestalten' }],
          },
        ],
      };
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Rollen & Rechte', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Speichern', exact: true })).toBeDisabled();
  const group = page.getByRole('checkbox', { name: 'Alle Rechte: Reservierungen', exact: true });
  await expect(group).toHaveAttribute('aria-checked', 'mixed');
  await page.locator('.rights-group-select').filter({ hasText: 'Widget' }).click();
  await expect(page.getByRole('switch', { name: 'Widget gestalten', exact: true })).toBeVisible();
  await page.locator('.rights-group-select').filter({ hasText: 'Reservierungen' }).click();
  await expect(page.getByRole('switch', { name: 'Buchungen ansehen', exact: true })).toBeChecked();
  await page.getByRole('textbox', { name: 'Berechtigungen suchen', exact: true }).fill('reservation.write');
  await expect(page.getByRole('switch', { name: 'Buchungen ansehen', exact: true })).toHaveCount(0);
  await page.getByRole('switch', { name: 'Buchungen bearbeiten', exact: true }).check();
  await expect(group).toBeChecked();
  await expect(page.getByRole('button', { name: 'Speichern', exact: true })).toBeEnabled();
  await page.getByRole('textbox', { name: 'Berechtigungen suchen', exact: true }).fill('');
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({
    path: 'test-results/roles-restaurant-groups.png',
    fullPage: true,
    animations: 'disabled',
  });
});
