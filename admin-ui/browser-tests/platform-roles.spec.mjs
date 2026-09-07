import { test, expect } from '@playwright/test';
test('platform role drafts are saved, checked and activated explicitly', async ({ page }) => {
  let role = {
    id: 2,
    name: 'Support',
    locked: false,
    permissions: [],
    draft_permissions: [],
    version: 1,
    tested_version: 1,
    activated_at: '2026-09-01',
  };
  const actions = [];
  await page.route('**/api/**', async (route) => {
    const r = route.request();
    const path = new URL(r.url()).pathname;
    let body = {};
    if (path.endsWith('/auth/me')) body = { id: 1, name: 'Admin', role: 'system_admin', permissions: ['*'] };
    else if (path.endsWith('/modules')) body = [{ code: 'identity', installed: true }];
    else if (path.endsWith('/dashboard')) body = { recent_audit: [] };
    else if (path.endsWith('/csrf')) body = { token: 'csrf' };
    else if (path.endsWith('/platform-roles'))
      body = {
        roles: [role],
        families: [
          {
            module: 'identity',
            code: 'system',
            label: 'System',
            permissions: [{ code: 'platform.audit.read', label: 'Audit Log ansehen' }],
          },
        ],
      };
    else if (r.method() === 'PATCH') {
      const data = r.postDataJSON();
      actions.push('save');
      role = {
        ...role,
        name: data.name,
        draft_permissions: data.permissions,
        version: role.version + 1,
        tested_version: null,
      };
    } else if (path.endsWith('/check')) {
      actions.push('check');
      role = { ...role, tested_version: role.version };
    } else if (path.endsWith('/activate')) {
      actions.push('activate');
      role = { ...role, permissions: role.draft_permissions, activated_at: '2026-09-07' };
    }
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/administration/login');
  await page.getByRole('button', { name: 'Rollen & Rechte', exact: true }).click();
  await page.getByRole('checkbox', { name: /Audit Log ansehen/ }).check();
  await expect(page.getByRole('button', { name: 'Rechte aktivieren', exact: true })).toBeDisabled();
  await page.getByRole('button', { name: 'Lokal speichern', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Entwurf prüfen', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'Entwurf prüfen', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Rechte aktivieren', exact: true })).toBeEnabled();
  await page.getByRole('button', { name: 'Rechte aktivieren', exact: true }).click();
  await expect.poll(() => actions).toEqual(['save', 'check', 'activate']);
});
