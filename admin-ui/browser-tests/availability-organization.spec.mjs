import { test, expect } from '@playwright/test';
test('room closures and saved table combinations have editable dialogs and preserve errors', async ({
  page,
}) => {
  let writes = [];
  await page.route('**/api/**', async (route) => {
    const req = route.request(),
      path = new URL(req.url()).pathname;
    let data = [];
    if (path.endsWith('/auth/me'))
      data = {
        id: 1,
        name: 'Anna',
        role: 'restaurant_admin',
        permissions: ['reservation.read', 'restaurant.configure'],
        session_lifetime_seconds: 3600,
      };
    else if (path.endsWith('/csrf')) data = { token: 'csrf' };
    else if (path.endsWith('/profile')) data = { timezone: 'Europe/Berlin' };
    else if (path.endsWith('/rooms')) data = [{ id: 1, name: 'Terrasse' }];
    else if (path.endsWith('/tables'))
      data = [
        { id: 1, name: 'Fenster', capacity: 4, room_id: 1, active: true },
        { id: 2, name: 'Garten', capacity: 4, room_id: 1, active: true },
      ];
    else if (req.method() === 'POST') {
      writes.push(req.postDataJSON());
      return route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Im Sperrzeitraum bestehen Reservierungen.' }),
      });
    } else if (path.endsWith('/room-closures'))
      data = [
        {
          id: 1,
          room_id: 1,
          starts_at: '2027-05-20 16:00:00',
          ends_at: '2027-05-20 18:00:00',
          reason: 'Geschlossene Gesellschaft',
        },
      ];
    else if (path.endsWith('/table-combinations'))
      data = [{ id: 1, name: 'Familientisch', active: true, table_ids: [1, 2] }];
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(data) });
  });
  await page.goto('/restaurant/login');
  await page.getByRole('button', { name: 'Raumsperren', exact: true }).click();
  await expect(page.getByText('Geschlossene Gesellschaft', { exact: true })).toBeVisible();
  await page.screenshot({ path: 'test-results/design-room-closures.png', fullPage: true });
  await page.getByRole('button', { name: 'Raumsperre anlegen' }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByLabel('Raum', { exact: true }).selectOption('1');
  await dialog.getByLabel('Beginn', { exact: true }).fill('2027-05-20T18:00');
  await dialog.getByLabel('Ende', { exact: true }).fill('2027-05-20T20:00');
  await dialog.getByLabel('Grund', { exact: true }).fill('Geschlossene Gesellschaft');
  await dialog.getByRole('button', { name: 'Speichern', exact: true }).click();
  await expect(dialog.getByText('Im Sperrzeitraum bestehen Reservierungen.')).toBeVisible();
  await expect(dialog.getByLabel('Grund')).toHaveValue('Geschlossene Gesellschaft');
  await dialog.getByRole('button', { name: 'Abbrechen' }).click();
  await page.getByRole('button', { name: 'Tischkombinationen', exact: true }).click();
  await expect(page.getByText('Fenster + Garten')).toBeVisible();
  await page.getByRole('button', { name: 'Bearbeiten', exact: true }).click();
  await expect(page.getByLabel('Tische kombinieren')).toHaveValues(['1', '2']);
  await page.screenshot({ path: 'test-results/design-table-combinations.png', fullPage: true });
});
test('all tenant pages are reachable and organization hierarchy is visible', async ({ page }) => {
  await page.route('**/api/**', async (route) => {
    const u = new URL(route.request().url()),
      path = u.pathname;
    let data = [];
    if (path.endsWith('/auth/me')) data = { id: 1, name: 'Admin', role: 'system_admin', permissions: ['*'] };
    else if (path.endsWith('/dashboard')) data = { recent_audit: [] };
    else if (path.endsWith('/tenants'))
      data = {
        data:
          u.searchParams.get('page') === '2'
            ? [{ id: 51, name: 'Restaurant auf Seite 2', organization_id: 2, status: 'active' }]
            : [{ id: 1, name: 'Restaurant auf Seite 1', organization_id: 1, status: 'active' }],
        last_page: 2,
        total: 51,
      };
    else if (path.endsWith('/organizations'))
      data = [
        { id: 1, name: 'Unternehmensgruppe', parent_id: null, version: 1 },
        { id: 2, name: 'Region Nord', parent_id: 1, version: 1 },
      ];
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(data) });
  });
  await page.goto('/administration/login');
  await page.getByRole('button', { name: 'Mandanten', exact: true }).click();
  await page.getByRole('button', { name: 'Nächste Seite' }).click();
  await expect(page.getByText('Restaurant auf Seite 2', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Nächste Seite' })).toBeDisabled();
  await page.getByRole('button', { name: 'Organisationen', exact: true }).click();
  await expect(page.getByText('Unternehmensgruppe', { exact: true })).toBeVisible();
  await expect(page.getByText('Region Nord', { exact: true })).toBeVisible();
  await expect(page.getByText(/Restaurant auf Seite 2/)).toBeVisible();
  await page.screenshot({ path: 'test-results/design-organizations.png', fullPage: true });
});

test('role synchronization displays permission changes and submits a confirmed preview', async ({ page }) => {
  let applied;
  await page.route('**/api/**', async (route) => {
    const req = route.request(),
      u = new URL(req.url()),
      path = u.pathname;
    let data = [];
    if (path.endsWith('/auth/me'))
      data = {
        id: 1,
        name: 'Admin',
        role: 'system_admin',
        permissions: ['*'],
        installed_modules: ['identity'],
      };
    else if (path.endsWith('/csrf')) data = { token: 'csrf' };
    else if (path.endsWith('/modules')) data = [{ code: 'identity', installed: true }];
    else if (path.endsWith('/platform-roles')) data = { roles: [], families: [] };
    else if (path.endsWith('/dashboard')) data = { recent_audit: [] };
    else if (path.endsWith('/tenants')) data = { data: [{ id: 1, name: 'Nord' }], last_page: 1 };
    else if (path.endsWith('/role-rollout'))
      data = {
        permissions: { 'reservation.read': 'Reservierungen ansehen', 'waitlist.read': 'Warteliste ansehen' },
      };
    else if (path.endsWith('/role-rollout/preview'))
      data = {
        token: 'preview-test',
        preview: {
          ...req.postDataJSON(),
          existing: [{ id: 2, tenant_id: 1, assigned_users: 3, added: ['waitlist.read'], removed: [] }],
        },
      };
    else if (path.endsWith('/role-rollout/apply')) {
      applied = req.postDataJSON();
      data = { synchronized: [{ tenant_id: 1, role_id: 2 }] };
    }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(data) });
  });
  await page.goto('/administration/login');
  await page.getByRole('button', { name: 'Rollen & Rechte', exact: true }).click();
  await page.getByLabel('Vorgang', { exact: true }).selectOption('sync');
  await page.getByLabel('Rollenname', { exact: true }).fill('Empfang');
  await page
    .getByLabel('Berechtigungen', { exact: true })
    .selectOption(['reservation.read', 'waitlist.read']);
  await page.getByLabel('Zielrestaurants', { exact: true }).selectOption('1');
  await page.getByRole('button', { name: 'Verteilung prüfen' }).click();
  await expect(page.getByText(/3 Benutzer/)).toBeVisible();
  await expect(page.getByText(/Hinzu: waitlist.read/)).toBeVisible();
  await page.screenshot({ path: 'test-results/design-role-synchronization.png', fullPage: true });
  await page.getByLabel('Administratorkennwort', { exact: true }).fill('test-password-long');
  await page.getByLabel('Aktueller Zwei-Faktor-Code', { exact: true }).fill('123456');
  await page.getByRole('button', { name: 'Rollenänderung bestätigen' }).click();
  await expect.poll(() => applied?.token).toBe('preview-test');
  await expect(page.getByText(/1 Restaurantrollen verarbeitet/)).toBeVisible();
});
