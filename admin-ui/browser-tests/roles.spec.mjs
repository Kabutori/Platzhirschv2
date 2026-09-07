import { test, expect } from '@playwright/test';
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
