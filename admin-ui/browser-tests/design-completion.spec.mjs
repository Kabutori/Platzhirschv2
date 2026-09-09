import { test, expect } from '@playwright/test';
import { mkdir } from 'node:fs/promises';
async function setup(page) {
  const calls = [];
  let releases = [];
  await page.route('**/api/**', async (route) => {
    const r = route.request(),
      u = new URL(r.url());
    calls.push(u.pathname + u.search);
    let body = {};
    if (u.pathname.endsWith('/auth/me'))
      body = {
        id: 1,
        name: 'Admin',
        role: 'system_admin',
        permissions: ['*'],
        installed_modules: ['identity', 'support', 'reservation'],
      };
    else if (u.pathname.endsWith('/csrf')) body = { token: 'csrf' };
    else if (u.pathname.endsWith('/dashboard')) body = { recent_audit: [] };
    else if (u.pathname.endsWith('/modules'))
      body = [
        { code: 'identity', installed: true },
        { code: 'support', installed: true },
      ];
    else if (u.pathname.endsWith('/health'))
      body = {
        version: '0.1.0',
        php: '8.5',
        database: 'MySQL',
        migrations: [{ migration: 'support_release_notes', batch: 1 }],
      };
    else if (u.pathname.endsWith('/audit-log'))
      body = {
        data: [
          {
            id: 1,
            action: u.searchParams.get('scope') === 'tenant' ? 'reservation.created' : 'platform.changed',
            tenant_id: u.searchParams.get('scope') === 'tenant' ? 42 : null,
          },
        ],
        total: 1,
        last_page: 1,
      };
    else if (u.pathname.endsWith('/releases')) {
      if (r.method() === 'POST') {
        releases = [{ ...r.postDataJSON(), id: 1, revision: 1, published_at: '2026-09-09' }];
        body = releases[0];
      } else body = { data: releases, last_page: 1 };
    }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/administration/login');
  await mkdir('artifacts', { recursive: true });
  return calls;
}
test('audit tabs filter at the server and system tabs show actual migration history', async ({ page }) => {
  const calls = await setup(page);
  await page.getByRole('button', { name: 'Audit Log', exact: true }).click();
  await expect(page.getByText('platform.changed', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Mandanten', exact: true }).last().click();
  await page.getByLabel('Mandanten-ID').fill('42');
  await expect.poll(() => calls.some((x) => x.includes('tenant_id=42'))).toBe(true);
  await expect(page.getByText('reservation.created', { exact: true })).toBeVisible();
  await page.screenshot({ path: 'artifacts/design-audit-tabs.png' });
  await page.getByRole('button', { name: 'System', exact: true }).click();
  await page.getByRole('button', { name: 'Migrationen', exact: true }).click();
  await expect(page.getByText('support_release_notes')).toBeVisible();
  await page.getByRole('button', { name: 'Backups', exact: true }).click();
  await expect(page.getByRole('link', { name: 'Betriebsanleitung öffnen' })).toBeVisible();
  await page.screenshot({ path: 'artifacts/design-health-tabs.png' });
});
test('release editor publishes a categorized note and preserves mobile dialog layout', async ({ page }) => {
  await setup(page);
  await page.getByRole('button', { name: 'Releases', exact: true }).click();
  await page.getByRole('button', { name: '+ Release', exact: true }).click();
  const d = page.getByRole('dialog');
  await d.getByLabel('Modul', { exact: true }).fill('Reservation');
  await d.getByLabel('Version', { exact: true }).fill('0.2.0');
  await d.getByLabel('Kategorie', { exact: true }).selectOption('feature');
  await d.getByLabel('Änderungen', { exact: true }).fill('Neue Ansicht');
  await d.getByLabel('Für Restaurantportale veröffentlichen', { exact: true }).check();
  await page.screenshot({ path: 'artifacts/design-release-editor.png' });
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(d).toBeVisible();
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await page.screenshot({ path: 'artifacts/design-release-mobile.png' });
  await d.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(page.getByText('Neue Ansicht', { exact: true })).toBeVisible();
});
