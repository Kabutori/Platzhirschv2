import { test, expect } from '@playwright/test';
const server = {
  id: 1,
  name: 'EU Test',
  host: 'db.example.test',
  port: 3306,
  region: 'EU',
  purpose: 'test',
  database: 'ph_test',
  username: 'ph_probe',
  tls_required: true,
  version: 1,
};
async function mock(page, installed = true) {
  const requests = [];
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    let body = {};
    if (path.endsWith('/auth/me')) body = { id: 1, name: 'Admin', role: 'system_admin', permissions: ['*'] };
    else if (path.endsWith('/modules')) body = installed ? [{ code: 'provisioning', installed: true }] : [];
    else if (path.endsWith('/dashboard')) body = { recent_audit: [] };
    else if (path.endsWith('/csrf')) body = { token: 'csrf' };
    else if (path.endsWith('/database-servers')) body = { servers: [server], notice: 'Prüfziele' };
    else if (path.endsWith('/test')) {
      requests.push(request.postDataJSON());
      body = { ok: false, code: 'permission_denied' };
    } else if (request.method() === 'PATCH') {
      requests.push(request.postDataJSON());
      body = server;
    }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  return requests;
}
test('provisioning UI appears only for an installed module', async ({ page }) => {
  await mock(page, false);
  await page.goto('/administration/login');
  await expect(page.getByRole('button', { name: 'Mandanten', exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Datenbankserver', exact: true })).toHaveCount(0);
});
test('server checks report failure and editing never preloads a password', async ({ page }) => {
  const requests = await mock(page);
  await page.goto('/administration/login');
  await page.getByRole('button', { name: 'Datenbankserver', exact: true }).click();
  await page.getByRole('button', { name: 'Berechtigungen testen', exact: true }).click();
  await expect(page.getByText('Datenbankrechte reichen für diese Prüfung nicht aus')).toBeVisible();
  await page.getByRole('button', { name: 'Bearbeiten', exact: true }).click();
  await expect(page.getByLabel('Passwort', { exact: false })).toHaveValue('');
  await page.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Speichern', exact: true })).toHaveCount(0);
  expect(requests[0]).toEqual({ check: 'permissions' });
  expect(requests[1].password).toBe('');
  expect(requests[1].version).toBe(1);
});
