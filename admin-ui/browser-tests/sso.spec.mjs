import { test, expect } from '@playwright/test';
test('SSO entry follows portal context and local MFA completes separately', async ({ page }) => {
  let complete = false;
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    let body = [],
      status = 200;
    if (path.endsWith('/auth/me')) {
      status = complete ? 200 : 401;
      body = complete
        ? { id: 1, name: 'Anna', role: 'staff', tenant_id: 1, permissions: [] }
        : { message: 'Anmeldung erforderlich' };
    } else if (path.endsWith('/bootstrap-status')) body = { bootstrapped: true };
    else if (path.endsWith('/csrf')) body = { token: 'test' };
    else if (path.endsWith('/sso/status'))
      body = { enabled: true, label: 'Unternehmenskonto', pending_mfa: !complete, linked: complete };
    else if (path.endsWith('/sso/mfa')) {
      expect(route.request().headers()['x-platzhirsch-portal']).toBe('restaurant');
      expect(route.request().postDataJSON()).toEqual({ mfa_code: '123456' });
      complete = true;
      body = { ok: true };
    }
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  });
  await page.goto('/restaurant/login');
  await expect(page.getByRole('heading', { name: 'SSO bestätigen', exact: true })).toBeVisible();
  await expect(page.getByLabel('Passwort', { exact: true })).toHaveCount(0);
  await page.screenshot({ path: 'test-results/design-sso-mfa.png', fullPage: true, animations: 'disabled' });
  await page.getByLabel('Bestätigungscode', { exact: false }).fill('123456');
  await page.getByRole('button', { name: 'SSO-Anmeldung abschließen', exact: false }).click();
  await expect(page.getByRole('heading', { name: 'Dein Zugang', exact: true })).toBeVisible();
});
