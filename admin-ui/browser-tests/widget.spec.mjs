import { test, expect } from '@playwright/test';
const config = {
  name: 'Restaurant <img src=x onerror=alert(1)>',
  timezone: 'Europe/Berlin',
  duration_minutes: 120,
  accent: '#c08050',
};
const cors = {
  'Access-Control-Allow-Origin': 'http://127.0.0.1:4174',
  'Access-Control-Allow-Methods': 'GET,POST,OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type,Accept',
};
async function fulfill(route, body, status = 200) {
  await route.fulfill({ status, headers: cors, contentType: 'application/json', body: JSON.stringify(body) });
}
async function setup(page, handler) {
  await page.route('**/api/widget/**', async (route) => {
    if (route.request().method() === 'OPTIONS') return fulfill(route, {});
    if (route.request().url().includes('/availability')) return handler(route);
    if (route.request().method() === 'POST') return handler(route);
    return fulfill(route, config);
  });
  await page.goto('/');
  await expect(page.getByLabel('Dein Name')).toBeVisible();
}
test('isolates host CSS, treats names as text and submits only one booking', async ({ page }) => {
  const submissions = [];
  await setup(page, async (route) => {
    if (route.request().method() === 'GET')
      return fulfill(route, { tables: [{ id: 1, name: 'Saal', capacity: 4 }] });
    submissions.push(route.request().postDataJSON());
    await new Promise((resolve) => setTimeout(resolve, 200));
    return fulfill(route, { id: 42 });
  });
  await expect(page.getByRole('heading')).toHaveText(config.name);
  await expect(page.locator('platzhirsch-booking img')).toHaveCount(0);
  await page.getByLabel('Datum und Uhrzeit').fill('2030-05-20T18:00');
  await page.getByRole('button', { name: 'Verfügbare Tische anzeigen' }).click();
  await page.getByLabel('Tisch', { exact: true }).selectOption('1');
  await page.getByLabel('Dein Name').fill('Anna Gast');
  await page.getByLabel('E-Mail').fill('guest@example.test');
  await page.getByRole('checkbox').check();
  await page.getByRole('button', { name: 'Verbindlich reservieren' }).evaluate((button) => {
    button.form.requestSubmit();
    button.form.requestSubmit();
  });
  await expect(page.getByRole('status')).toContainText('Buchungsnummer: 42');
  expect(submissions).toHaveLength(1);
  expect(submissions[0]).toMatchObject({ table_id: 1, party_size: 2, consent: true, duration_minutes: 120 });
  expect(submissions[0].request_key).toMatch(/^[a-f0-9-]{36}$/);
});
test('ignores stale availability replies after changing the date', async ({ page }) => {
  await setup(page, async (route) => {
    const old = route.request().url().includes('18%3A00');
    if (old) await new Promise((resolve) => setTimeout(resolve, 500));
    return fulfill(route, {
      tables: [{ id: old ? 1 : 2, name: old ? 'Alter Termin' : 'Neuer Termin', capacity: 4 }],
    });
  });
  await page.getByLabel('Datum und Uhrzeit').fill('2030-05-20T18:00');
  await page.getByRole('button', { name: 'Verfügbare Tische anzeigen' }).click();
  await page.getByLabel('Datum und Uhrzeit').fill('2030-05-20T20:00');
  await page.getByRole('button', { name: 'Verfügbare Tische anzeigen' }).click();
  await expect(page.getByLabel('Tisch', { exact: true })).toContainText('Neuer Termin');
  await page.waitForTimeout(650);
  await expect(page.getByLabel('Tisch', { exact: true })).not.toContainText('Alter Termin');
});
test('shows availability failures without permitting a booking', async ({ page }) => {
  await setup(page, (route) => fulfill(route, { message: 'An diesem Tag ist geschlossen.' }, 422));
  await page.getByLabel('Datum und Uhrzeit').fill('2030-05-20T18:00');
  await page.getByRole('button', { name: 'Verfügbare Tische anzeigen' }).click();
  await expect(page.getByRole('alert')).toContainText('geschlossen');
  await expect(page.getByRole('button', { name: 'Verbindlich reservieren' })).toBeDisabled();
});

test('English floating widget opens accessibly and applies party limit and readable accent', async ({
  page,
}) => {
  await page.route('**/api/widget/**', (route) =>
    fulfill(route, {
      ...config,
      language: 'en',
      position: 'bottom-right',
      max_party_size: 1,
      show_brand: false,
      accent: '#000000',
    }),
  );
  await page.goto('/');
  const launch = page.getByRole('button', { name: 'Reserve a table', exact: true });
  await expect(launch).toBeVisible();
  await expect(page.getByLabel('Your name')).not.toBeVisible();
  await launch.click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await expect(page.getByLabel('Your name')).toBeVisible();
  await expect(page.getByLabel('Guests', { exact: true })).toHaveAttribute('max', '1');
  await expect(page.getByLabel('Guests', { exact: true })).toHaveValue('1');
  await expect(page.locator('.widget-brand')).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Show available tables' })).toHaveCSS(
    'color',
    'rgb(255, 255, 255)',
  );
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).not.toBeVisible();
  await expect(launch).toBeFocused();
});
