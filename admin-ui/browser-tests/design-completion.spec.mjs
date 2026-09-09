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
  await mkdir('test-results', { recursive: true });
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
  await page.screenshot({ path: 'test-results/design-audit-tabs.png' });
  await page.getByRole('button', { name: 'System', exact: true }).click();
  await page.getByRole('button', { name: 'Migrationen', exact: true }).click();
  await expect(page.getByText('support_release_notes')).toBeVisible();
  await page.getByRole('button', { name: 'Backups', exact: true }).click();
  await expect(page.getByRole('link', { name: 'Betriebsanleitung öffnen' })).toBeVisible();
  await page.screenshot({ path: 'test-results/design-health-tabs.png' });
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
  await page.screenshot({ path: 'test-results/design-release-editor.png' });
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(d).toBeVisible();
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  await page.screenshot({ path: 'test-results/design-release-mobile.png' });
  await d.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(page.getByText('Neue Ansicht', { exact: true })).toBeVisible();
});
test('room colors and icons use selectable grids without submitting the editor', async ({ page }) => {
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
    else if (p.endsWith('/rooms') && r.method() === 'POST') {
      saved = r.postDataJSON();
      body = { id: 1, ...saved };
    }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Räume', exact: true }).click();
  await page.getByRole('button', { name: 'Hinzufügen', exact: true }).click();
  const d = page.getByRole('dialog');
  await d.getByLabel('Raumname', { exact: true }).fill('Garten');
  await d.getByRole('button', { name: 'Salbei', exact: true }).click();
  await d.getByRole('button', { name: 'Terrasse', exact: true }).click();
  expect(saved).toBeUndefined();
  await mkdir('test-results', { recursive: true });
  await page.screenshot({ path: 'test-results/design-room-choices.png' });
  await d.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect.poll(() => saved?.icon).toBe('terrace');
  expect(saved.color).toBe('sage');
});
test('weekly opening dialog preserves inputs on conflicts and closes a day explicitly', async ({ page }) => {
  let saved;
  let conflict = true;
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
    else if (p.endsWith('/hours-week')) {
      if (r.method() === 'PUT') {
        saved = r.postDataJSON();
        if (conflict) {
          conflict = false;
          await route.fulfill({
            status: 409,
            contentType: 'application/json',
            body: JSON.stringify({ message: 'Öffnungszeiten wurden inzwischen geändert.' }),
          });
          return;
        }
        body = { saved: true };
      } else body = { revision: 'a'.repeat(64), rows: [{ weekday: 1, opens: '12:00', closes: '22:00' }] };
    }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Öffnungszeiten', exact: true }).click();
  await page.getByRole('button', { name: 'Wochenplan bearbeiten', exact: true }).click();
  const d = page.getByRole('dialog');
  await d.getByRole('switch', { name: 'Dienstag geöffnet' }).check();
  await d.getByRole('switch', { name: 'Montag geöffnet' }).uncheck();
  await d.getByRole('button', { name: 'Wochenplan speichern', exact: true }).click();
  await expect(d.getByRole('alert')).toContainText('inzwischen geändert');
  await expect(d.getByRole('switch', { name: 'Dienstag geöffnet' })).toBeChecked();
  await mkdir('test-results', { recursive: true });
  await page.screenshot({ path: 'test-results/design-hours-week.png' });
  await page.setViewportSize({ width: 390, height: 844 });
  await page.screenshot({ path: 'test-results/design-hours-week-mobile.png' });
  expect(saved.rows).toEqual([{ weekday: 2, opens: '12:00', closes: '22:00' }]);
  await d.getByRole('button', { name: 'Abbrechen', exact: true }).click();
  await expect(d).toHaveCount(0);
});
