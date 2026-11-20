import { expect, test, type Page } from '@playwright/test';

/**
 * Site header (direction A of docs/directory/header-mockup.html) and the sign-in dialog, against the real PHP
 * pages under `php -S` (playwright.checkout.config.ts). companies' api/auth.php is not part of this server, so the
 * dialog tests stub it with page.route. Screenshots go to extension/test-results/header/.
 */
const SHOTS = 'test-results/header';
const signIn = (page: Page) => page.request.get('/__dev/login?user_id=7');
const signOut = (page: Page) => page.request.get('/__dev/login?user_id=0');

async function noSideScroll(page: Page) {
  const [scroll, client] = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
  expect(scroll).toBeLessThanOrEqual(client);
}

test.afterEach(async ({ page }) => { await signOut(page); });

for (const width of [1280, 768, 375]) {
  test(`header: no sideways scroll at ${width} px, signed out and signed in, en, fr and ar`, async ({ page }) => {
    await page.setViewportSize({ width, height: 800 });
    for (const lang of ['en', 'fr', 'ar']) {
      await page.goto(`/palette/terms?lang=${lang}`);
      await noSideScroll(page);
    }
    await page.goto('/palette/?lang=en');
    await page.screenshot({ path: `${SHOTS}/signed-out-${width}.png` });
    await signIn(page);
    for (const lang of ['en', 'fr', 'ar']) {
      await page.goto(`/palette/terms?lang=${lang}`);
      await noSideScroll(page);
    }
  });
}

test('header: desktop shows nav, the short language code and the account menu', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('/palette/?lang=en');
  const header = page.locator('header.topbar');
  await expect(header.locator('.topnav')).toContainText('Pricing');
  await expect(header.locator('details.lang summary')).toHaveAttribute('aria-label', 'Language: English');
  await expect(header.locator('details.lang summary')).toHaveText('EN');
  expect((await header.locator('details.lang summary').boundingBox())!.height).toBe(32);
  await expect(header.locator('.brand-by')).toBeVisible();
  await signIn(page);
  await page.goto('/palette/?lang=en');
  await page.locator('details.acct summary').click();
  const menu = page.locator('details.acct .dd-menu');
  await expect(menu).toContainText('Lan Nguyen');
  await expect(menu.getByRole('link', { name: 'Your submissions' })).toHaveAttribute('href', '/palette/directory/mine?lang=en');
  await page.screenshot({ path: `${SHOTS}/desktop-signed-in-menu.png` });
  await page.keyboard.press('Escape');
  await expect(menu).toBeHidden();
});

test('header: the phone menu opens and closes', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 800 });
  await page.goto('/palette/?lang=en');
  await expect(page.locator('.topnav')).toBeHidden();
  await expect(page.locator('header .brand-by')).toBeVisible();
  const toggle = page.locator('details.menu > summary');
  expect((await toggle.boundingBox())!.height).toBeGreaterThanOrEqual(40);
  await toggle.click();
  const sheet = page.locator('details.menu .sheet');
  await expect(sheet).toBeVisible();
  await expect(toggle).toHaveAttribute('aria-label', 'Close menu');
  await expect(sheet.getByRole('link', { name: 'Create account' })).toBeVisible();
  await page.screenshot({ path: `${SHOTS}/phone-menu-open.png` });
  await toggle.click();
  await expect(sheet).toBeHidden();
  await expect(toggle).toHaveAttribute('aria-label', 'Menu');

  await page.goto('/palette/?lang=ar');
  await toggle.click();
  await expect(sheet).toBeVisible();
  await noSideScroll(page);
  await page.screenshot({ path: `${SHOTS}/phone-ar-menu-open.png` });
});

test('dialog: opens from Sign in, switches tabs, validates, closes back to the opener', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('/palette/?lang=en');
  const opener = page.locator('.topbar-end > .auth-in');
  await opener.click();
  const dlg = page.locator('#auth-dlg');
  await expect(dlg).toBeVisible();
  await expect(page).toHaveURL(/\/palette\/\?lang=en$/);
  await expect(page.locator('#auth-email')).toBeFocused();
  await page.screenshot({ path: `${SHOTS}/dialog-desktop.png` });

  await dlg.getByRole('tab', { name: 'Create account' }).click();
  await expect(page.locator('#auth-register')).toBeVisible();
  await expect(page.locator('#auth-login')).toBeHidden();
  await expect(page.locator('#auth-name')).toBeFocused();
  await page.locator('#auth-name').fill('Lan');
  await page.locator('#auth-remail').fill('lan@studio-mai.vn');
  await page.locator('#auth-npw').fill('short');
  await dlg.getByRole('button', { name: 'Create account' }).click();
  await expect(page.locator('#auth-npw-err')).toHaveText('Use at least 8 characters.');
  await expect(page.locator('#auth-npw')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('#auth-npw')).toBeFocused();

  await page.keyboard.press('Escape');
  await expect(dlg).toBeHidden();
  await expect(opener).toBeFocused();
});

test('dialog: the in-page Sign in link on the account page opens it too', async ({ page }) => {
  await page.goto('/palette/account?lang=en');
  await page.getByRole('main').getByRole('link', { name: 'Sign in' }).click();
  await expect(page.locator('#auth-dlg')).toBeVisible();
  await expect(page.getByRole('tab', { name: 'Sign in' })).toHaveAttribute('aria-selected', 'true');
});

test('dialog: an expired token is fetched again and retried once, then sign-in reloads the page', async ({ page }) => {
  const calls: string[] = [];
  let logins = 0;
  await page.route('**/companies/api/auth.php', async (route) => {
    const body = new URLSearchParams(route.request().postData() ?? '');
    calls.push(`${body.get('action')}:${body.get('csrf_token') ?? ''}`);
    if (body.get('action') === 'csrf') {
      return route.fulfill({ json: { success: true, login: `L${calls.length}`, register: 'R' } });
    }
    logins++;
    if (logins === 1) return route.fulfill({ json: { success: false, code: 'csrf_expired', message: 'Your session expired.' } });
    await signIn(page); // what companies does on success: the session now holds the user
    return route.fulfill({ json: { success: true, message: 'Login successful' } });
  });
  await page.setViewportSize({ width: 375, height: 800 });
  await page.goto('/palette/?lang=en');
  await page.locator('.topbar-end > .auth-in').click();
  await page.screenshot({ path: `${SHOTS}/dialog-phone.png` });
  const box = (await page.locator('#auth-dlg').boundingBox())!;
  expect(box.height).toBe(800);
  await page.locator('#auth-email').fill('lan@studio-mai.vn');
  await page.locator('#auth-pw').fill('mai-studio-24');
  await page.locator('#auth-login').getByRole('button', { name: 'Sign in' }).click();
  await expect(page.locator('details.acct summary')).toBeVisible();
  await expect(page.locator('#auth-dlg')).toHaveCount(0);
  expect(calls).toEqual(['csrf:', 'login:L1', 'csrf:', 'login:L3']);
});

test('dialog: a wrong password shows the translated message in the alert banner', async ({ page }) => {
  await page.route('**/companies/api/auth.php', (route) => {
    const action = new URLSearchParams(route.request().postData() ?? '').get('action');
    return route.fulfill({ json: action === 'csrf' ? { success: true, login: 'L', register: 'R' } : { success: false, message: 'Invalid email or password' } });
  });
  await page.goto('/palette/?lang=fr');
  await page.locator('.topbar-end > .auth-in').click();
  await page.locator('#auth-email').fill('lan@studio-mai.vn');
  await page.locator('#auth-pw').fill('wrong-one');
  await page.locator('#auth-pw').press('Enter');
  await expect(page.locator('#auth-dlg [role="alert"]')).toContainText('ne correspondent pas');
});

test('footer: no sideways scroll at 3 widths and in Arabic, 40 px links on phones', async ({ page }) => {
  const shot = async (name: string) => page.locator('footer.foot').screenshot({ path: `test-results/footer/${name}.png` });
  for (const width of [1280, 768, 375]) {
    await page.setViewportSize({ width, height: 800 });
    await page.goto('/palette/terms?lang=en');
    await noSideScroll(page);
    await shot(`footer-${width}`);
  }
  for (const link of await page.locator('footer.foot a').all()) {
    if (await link.locator('xpath=ancestor::*[contains(@class,"foot-brand")]').count()) continue;
    expect((await link.boundingBox())!.height).toBeGreaterThanOrEqual(40);
  }
  await page.goto('/palette/terms?lang=ar');
  await noSideScroll(page);
  await shot('footer-ar-375');
});

test('footer: Sign in opens the dialog and stays on the page', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.goto('/palette/terms?lang=en');
  await page.locator('footer').getByRole('link', { name: 'Sign in' }).click();
  await expect(page.locator('#auth-dlg')).toBeVisible();
  await expect(page).toHaveURL(/\/palette\/terms\?lang=en$/);
});
