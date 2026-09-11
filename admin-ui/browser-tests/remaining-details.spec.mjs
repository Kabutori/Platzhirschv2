import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
test('Odoo stays an inert placeholder and server details never show a password', async ({ page }) => {
  const writes = [];
  await page.route('**/api/**', async (route) => {
    const r = route.request(),
      p = new URL(r.url()).pathname;
    if (r.method() !== 'GET') writes.push(p);
    let body = {};
    if (p.endsWith('/auth/me'))
      body = {
        id: 1,
        name: 'Admin',
        role: 'system_admin',
        permissions: ['*'],
        installed_modules: ['provisioning'],
      };
    else if (p.endsWith('/modules')) body = [{ code: 'provisioning', installed: true }];
    else if (p.endsWith('/dashboard')) body = { recent_audit: [] };
    else if (p.endsWith('/database-servers'))
      body = {
        servers: [
          {
            id: 1,
            name: 'EU Server',
            host: 'db.example.test',
            port: 3306,
            region: 'EU',
            purpose: 'test',
            database: 'probe',
            username: 'probe',
            tls_required: true,
            version: 1,
          },
        ],
        notice: '',
      };
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/administration/login');
  await page.getByRole('button', { name: 'System-Einstellungen', exact: true }).click();
  await page.getByRole('button', { name: 'Odoo', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Verbindung testen', exact: true })).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Synchronisation aktivieren' })).toBeDisabled();
  await expect(page.locator('input[type=password]')).toHaveCount(0);
  expect(writes).toEqual([]);
  await mkdir('test-results', { recursive: true });
  await page.screenshot({ path: 'test-results/design-odoo-placeholder.png' });
  await page.getByRole('button', { name: 'Datenbankserver', exact: true }).click();
  await page.getByRole('button', { name: 'Details', exact: true }).click();
  const d = page.getByRole('dialog', { name: 'Serverdetails' });
  await expect(d.getByText('db.example.test')).toBeVisible();
  await page.screenshot({ path: 'test-results/design-server-details.png' });
  await d.getByRole('button', { name: 'Schließen', exact: true }).last().click();
});
test('room dialog assigns selected tables with their original room snapshot', async ({ page }) => {
  let saved;
  await page.route('**/api/**', async (route) => {
    const r = route.request(),
      p = new URL(r.url()).pathname;
    let body = [];
    if (p.endsWith('/auth/me'))
      body = {
        id: 2,
        name: 'Restaurant',
        role: 'restaurant_admin',
        tenant_id: 1,
        permissions: ['restaurant.configure'],
      };
    else if (p.endsWith('/csrf')) body = { token: 'csrf' };
    else if (p.endsWith('/rooms/1/tables')) {
      saved = r.postDataJSON();
      body = { assigned: 1 };
    } else if (p.endsWith('/rooms'))
      body = [{ id: 1, name: 'Garten', color: 'sage', icon: 'terrace', outdoor: true }];
    else if (p.endsWith('/tables')) body = [{ id: 7, name: 'Fenstertisch', room_id: 2, capacity: 4 }];
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Räume', exact: true }).click();
  await page.getByRole('button', { name: 'Bearbeiten', exact: true }).click();
  const d = page.getByRole('dialog');
  await d.getByRole('button', { name: 'Tische zuordnen', exact: true }).click();
  await d.getByLabel('Tische zuordnen', { exact: true }).selectOption('7');
  await mkdir('test-results', { recursive: true });
  await page.screenshot({ path: 'test-results/design-room-table-assignment.png' });
  await d.getByRole('button', { name: 'Zuordnung speichern', exact: true }).click();
  await expect.poll(() => saved).toEqual({ tables: [{ id: 7, room_id: 2 }] });
  await expect(d).toHaveCount(0);
});
