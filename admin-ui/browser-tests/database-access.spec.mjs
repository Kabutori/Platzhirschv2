import { test, expect } from '@playwright/test';
test('database secret is requested explicitly and removed when the dialog closes', async ({ page }) => {
  let reveals = 0;
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    let body = {};
    if (path.endsWith('/auth/me')) body = { id: 1, name: 'Admin', role: 'system_admin', permissions: ['*'] };
    else if (path.endsWith('/modules')) body = [];
    else if (path.endsWith('/dashboard')) body = { recent_audit: [] };
    else if (path.endsWith('/csrf')) body = { token: 'csrf' };
    else if (path.endsWith('/database-access'))
      body = [
        {
          id: 'platform',
          name: 'Plattform-Datenbank',
          scope: 'Plattform',
          host: '127.0.0.1',
          port: 3308,
          database: 'platzhirsch_platform',
          username: 'ph_app',
          status: 'active',
        },
      ];
    else if (path.endsWith('/reveal')) {
      reveals++;
      expect(route.request().postDataJSON()).toEqual({ password: 'test-admin-password' });
      body = { password: 'fake-database-secret', visible_seconds: 30 };
    }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/administration/login');
  await page.getByRole('button', { name: 'SQL-Zugangsdaten', exact: true }).click();
  await page.getByRole('button', { name: 'Verbindungsdaten öffnen' }).click();
  expect(reveals).toBe(0);
  await page.getByLabel('Dein Administratorkennwort').fill('test-admin-password');
  await page.getByRole('button', { name: 'SQL-Passwort anzeigen' }).click();
  await expect(page.getByLabel('SQL-Passwort', { exact: true })).toHaveValue('fake-database-secret');
  await page.getByRole('button', { name: 'Schließen', exact: true }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await page.getByRole('button', { name: 'Verbindungsdaten öffnen' }).click();
  await expect(page.getByLabel('Dein Administratorkennwort')).toHaveValue('');
  await expect(page.getByLabel('SQL-Passwort', { exact: true })).toHaveCount(0);
  expect(reveals).toBe(1);
});
