import { expect, test, type Page } from '@playwright/test';

/**
 * Checkout page against the real PHP pages and api.php under `php -S` (playwright.checkout.config.ts).
 * PayPal REST is stubbed in the router; the PayPal JS SDK is replaced here by a fake whose button runs
 * onClick -> createOrder -> onApprove like the real one, and honours actions.disable()/enable().
 */
const EXT = 'abcdefghijklmnopabcdefghijklmnop';
const SDK = 'https://www.paypal.com/sdk/js?client-id=dev-client&currency=USD&intent=capture&components=buttons';
const SDK_AI = 'https://www.paypal.com/sdk/js?client-id=dev-client&currency=USD&vault=true&intent=subscription&components=buttons';
const KEY_RE = /^SDT-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/;

const FAKE_SDK = `
window.paypal = {
  Buttons(opts) {
    return {
      isEligible: () => true,
      render(target) {
        const host = typeof target === 'string' ? document.querySelector(target) : target;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = 'PayPal';
        btn.dataset.testid = 'fake-paypal';
        host.appendChild(btn);
        const actions = {
          enable() { btn.disabled = false; return Promise.resolve(); },
          disable() { btn.disabled = true; return Promise.resolve(); },
        };
        btn.addEventListener('click', async () => {
          const go = opts.onClick ? await opts.onClick({}, { resolve: () => Promise.resolve(true), reject: () => Promise.resolve(false) }) : true;
          if (go === false) return;
          try {
            if (opts.createSubscription) {
              // Like the subscription SDK: create() returns the subscription id PayPal minted; the dev router knows I-DEVSUB<row id>.
              const create = async (p) => { window.__subCreate = p; return 'I-DEVSUB' + String(p.custom_id).split(':')[1]; };
              const subscriptionID = await opts.createSubscription({}, { subscription: { create } });
              await opts.onApprove({ subscriptionID }, {});
            } else {
              const orderID = await opts.createOrder({}, {});
              await opts.onApprove({ orderID }, {});
            }
          } catch (e) {
            if (opts.onError) opts.onError(e);
          }
        });
        if (opts.onInit) opts.onInit({}, actions);
        return Promise.resolve();
      },
    };
  },
};`;

/** Resets the palette tables and settings for one test. */
async function seed(page: Page, paypal: 'ready' | 'paused' = 'ready', tax: 'none' | 'ca' = 'none', ai: 'ready' | 'off' = 'ready') {
  const r = await page.request.get(`/__dev/seed?paypal=${paypal}&tax=${tax}&ai=${ai}`);
  expect(await r.text()).toBe('seeded');
}

/** Serves the fake SDK and records the SDK URLs the page asked for. */
async function fakePayPal(page: Page): Promise<string[]> {
  const urls: string[] = [];
  await page.route('https://www.paypal.com/sdk/js**', (route) => {
    urls.push(route.request().url());
    return route.fulfill({ contentType: 'text/javascript', body: FAKE_SDK });
  });
  return urls;
}

/** A stand-in for the chrome.runtime the extension's externally_connectable exposes; answers {ok:true}. */
async function fakeExtension(page: Page) {
  await page.addInitScript(() => {
    const w = window as unknown as { __sent: unknown[]; chrome: unknown };
    w.__sent = [];
    Object.defineProperty(window, 'chrome', {
      configurable: true,
      value: { runtime: { sendMessage(id: string, msg: unknown, cb: (r: unknown) => void) { w.__sent.push([id, msg]); setTimeout(() => cb({ ok: true }), 10); } } },
    });
  });
}

async function fillForm(page: Page, email: string) {
  await page.locator('#email').fill(email);
  await page.locator('#consent').check();
}

test('consent gates the button; approve shows the key and hands it to the extension', async ({ page, context }) => {
  await seed(page);
  const sdk = await fakePayPal(page);
  await fakeExtension(page);
  const res = await page.goto(`/palette/checkout?lang=en&ext=${EXT}`);
  const h = res!.headers();
  expect(h['content-security-policy']).toContain("script-src 'self' https://www.paypal.com https://*.paypal.com");
  expect(h['x-frame-options']).toBe('DENY');
  expect(h['referrer-policy']).toBe('strict-origin-when-cross-origin');

  const pay = page.getByTestId('fake-paypal');
  await expect(pay).toBeVisible();
  expect(sdk).toEqual([SDK]);
  await expect(pay).toBeDisabled();
  await page.locator('#email').fill('mai@studio.vn');
  await expect(pay).toBeDisabled();
  await page.locator('#consent').check();
  await expect(pay).toBeEnabled();
  await page.locator('#consent').uncheck();
  await expect(pay).toBeDisabled();
  await page.locator('#consent').check();

  const create = page.waitForRequest((r) => r.url().includes('action=create'));
  await pay.click();
  const body = (await create).postDataJSON() as Record<string, string>;
  expect(body).toMatchObject({ email: 'mai@studio.vn', consent: '1', lang: 'en' });
  expect(body.csrf).toMatch(/^[0-9a-f]{64}$/);

  await expect(page.locator('#done')).toBeVisible();
  await expect(page.locator('#buy')).toBeHidden();
  const key = (await page.locator('#key').textContent()) ?? '';
  expect(key).toMatch(KEY_RE);
  await expect(page.locator('#done-title')).toHaveText("You're Pro. Thank you.");
  await expect(page.locator('#copy-key')).toBeVisible();
  const link = page.getByRole('link', { name: 'Link to account' });
  await expect(link).toHaveAttribute('href', `/palette/account?key=${key}&lang=en`);
  await expect(page.locator('#ext-status')).toHaveText('Your extension is now Pro.');
  expect(await page.evaluate(() => (window as unknown as { __sent: unknown[] }).__sent)).toEqual([[EXT, { type: 'license', key }]]);
  await expect(page.locator('#emailed')).toContainText('m***@studio.vn');

  await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  await page.locator('#copy-key').click();
  await expect(page.locator('#copy-status')).toHaveText('Copied');
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(key);
});

test('without the extension the success view says to paste the key', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en');
  await fillForm(page, 'solo@studio.vn');
  await page.getByTestId('fake-paypal').click();
  await expect(page.locator('#done')).toBeVisible();
  await expect(page.locator('#ext-status')).toContainText('Paste this key into the extension');
});

test('an invalid email is refused before PayPal opens', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  let created = false;
  page.on('request', (r) => { if (r.url().includes('action=create')) created = true; });
  await page.goto('/palette/checkout?lang=en');
  await fillForm(page, 'not-an-email');
  await page.getByTestId('fake-paypal').click();
  await expect(page.locator('#buy-error')).toHaveText('Enter a valid email address.');
  expect(created).toBe(false);
  await expect(page.locator('#email')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('#email')).toHaveAttribute('aria-describedby', 'email-hint buy-error');
  await page.locator('#email').fill('ok@studio.vn');
  await expect(page.locator('#buy-error')).toBeHidden();
  await expect(page.locator('#email')).not.toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('#email')).toHaveAttribute('aria-describedby', 'email-hint');
});

test('consent unchecked at click time marks the checkbox invalid', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en');
  await page.locator('#email').fill('mai@studio.vn');
  await page.locator('#consent').check();
  // Real PayPal keeps onClick firing while disabled; reach that path by calling onClick through the fake.
  await page.locator('#consent').uncheck();
  await page.getByTestId('fake-paypal').evaluate((b: HTMLButtonElement) => { b.disabled = false; b.click(); });
  await expect(page.locator('#buy-error')).toHaveText('Please confirm that you understand purchases are final and not refundable.');
  await expect(page.locator('#consent')).toHaveAttribute('aria-invalid', 'true');
  await expect(page.locator('#consent')).toHaveAttribute('aria-describedby', 'buy-error');
});

test('a paid order whose key is not ready shows the retry message (503)', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.goto('/palette/checkout?lang=es');
  await fillForm(page, 'retry@studio.vn');
  const capture = page.waitForResponse((r) => r.url().includes('action=capture'));
  await page.getByTestId('fake-paypal').click();
  expect((await capture).status()).toBe(503);
  await expect(page.locator('#buy-error')).toHaveText('Recibimos el pago, pero tu clave aún no está lista. Revisa tu correo en unos minutos.');
  await expect(page.locator('#done')).toBeHidden();
  // No second payment: the button is gone and the form is locked, the message stays.
  await expect(page.locator('#paypal')).toBeHidden();
  await expect(page.locator('#email')).toBeDisabled();
  await expect(page.locator('#consent')).toBeDisabled();
  await expect(page.locator('#buy-error')).toBeVisible();
});

test('a paid order whose capture PayPal holds as PENDING locks the form with the confirm message (202)', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en');
  await fillForm(page, 'pending@studio.vn');
  const capture = page.waitForResponse((r) => r.url().includes('action=capture'));
  await page.getByTestId('fake-paypal').click();
  expect((await capture).status()).toBe(202);
  await expect(page.locator('#buy-error')).toHaveText("We couldn't confirm your payment. If you were charged, your key will arrive by email — or use 'Lost your key?' below.");
  await expect(page.locator('#paypal')).toBeHidden();
  await expect(page.locator('#email')).toBeDisabled();
  await expect(page.locator('#done')).toBeHidden();
});

test('a capture answered without a key (an order this session did not create) says the key was emailed', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await fakeExtension(page);
  await page.route('**/palette/api.php?action=capture', (r) => r.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({ ok: true, key: null, email_masked: 'm***@studio.vn', message: 'Payment received. Your key was emailed to m***@studio.vn.' }) }));
  await page.goto(`/palette/checkout?lang=en&ext=${EXT}`);
  await fillForm(page, 'mai@studio.vn');
  await page.getByTestId('fake-paypal').click();
  await expect(page.locator('#done')).toBeVisible();
  await expect(page.locator('#emailed')).toHaveText('Payment received. Your key was emailed to m***@studio.vn.');
  await expect(page.locator('.keybox')).toBeHidden();
  await expect(page.locator('#copy-key')).toBeHidden();
  expect(await page.evaluate(() => (window as unknown as { __sent: unknown[] }).__sent)).toEqual([]);
});

test('a network failure after approval says the payment could not be confirmed', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.route('**/palette/api.php?action=capture', (r) => r.abort('failed'));
  await page.goto('/palette/checkout?lang=en');
  await fillForm(page, 'mai@studio.vn');
  await page.getByTestId('fake-paypal').click();
  await expect(page.locator('#buy-error')).toHaveText("We couldn't confirm your payment. If you were charged, your key will arrive by email — or use 'Lost your key?' below.");
  await expect(page.locator('#paypal')).toBeHidden();
  await expect(page.locator('#done')).toBeHidden();
});

test('payments paused: no PayPal button, a clear message', async ({ page }) => {
  await seed(page, 'paused');
  const sdk = await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en');
  await expect(page.getByText('Payments are paused right now.')).toBeVisible();
  await expect(page.locator('#paypal')).toHaveCount(0);
  await page.waitForLoadState('networkidle');
  expect(sdk).toEqual([]);
});

test('California tax: country and state selects, total and order body', async ({ page }) => {
  await seed(page, 'ready', 'ca');
  await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en');
  await expect(page.locator('#total')).toHaveText('$1.49');
  await expect(page.locator('#region')).toBeHidden();
  await page.locator('#country').selectOption('US');
  await page.locator('#region').selectOption('CA');
  await expect(page.locator('#total')).toHaveText('$1.60');
  await expect(page.locator('#tax-row')).toBeVisible();
  await fillForm(page, 'cal@studio.vn');
  const create = page.waitForResponse((r) => r.url().includes('action=create'));
  await page.getByTestId('fake-paypal').click();
  const res = await create;
  expect(res.request().postDataJSON()).toMatchObject({ country: 'US', region: 'CA' });
  expect(await res.json()).toMatchObject({ ok: true, total: '1.60' });
  await expect(page.locator('#done')).toBeVisible();
});

test('no tax selects by default', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en');
  await expect(page.locator('#country')).toHaveCount(0);
  await expect(page.locator('#region')).toHaveCount(0);
});

test('Arabic is right-to-left and the language links keep the extension id', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.goto(`/palette/checkout?lang=ar&ext=${EXT}`);
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  await page.locator('details.lang summary').click();
  await page.getByRole('link', { name: 'Français' }).click();
  await expect(page).toHaveURL(`/palette/checkout?lang=fr&ext=${EXT}`);
  await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
  await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  expect(overflow).toBe(false);
});

test('lost key: resend always answers the same confirmation', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en');
  await page.locator('#resend-email').fill('nobody@studio.vn');
  await page.getByRole('button', { name: 'Send my keys' }).click();
  await expect(page.locator('#resend-status')).toHaveText('If a key exists for that address, we sent it.');
});

test('account: signed out, the key waits in the session, not in the sign-in URL', async ({ page }) => {
  await seed(page);
  const key = await (await page.request.get('/__dev/license?email=acct@studio.vn')).text();
  await page.request.get('/__dev/login?user_id=0');
  await page.goto(`/palette/account?key=${key}&lang=en`);
  await expect(page.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/companies/?auth=login&redirect=/palette/account');
  await expect(page.locator('#claim')).toHaveCount(0);
  // after signing in, the plain account URL prefills the key once
  await page.request.get('/__dev/login?user_id=7');
  await page.goto('/palette/account');
  await expect(page.locator('#claim-key')).toHaveValue(key);
  await page.reload();
  await expect(page.locator('#claim-key')).toHaveValue('');
  await page.request.get('/__dev/login?user_id=0');
});

test('account: signed in, the key from the success page is added and listed', async ({ page }) => {
  await seed(page);
  const key = await (await page.request.get('/__dev/license?email=acct@studio.vn')).text();
  expect(key).toMatch(KEY_RE);
  await page.request.get('/__dev/login?user_id=7');
  await page.goto(`/palette/account?key=${key}&lang=en`);
  await expect(page.locator('.keys')).toHaveCount(0);
  await expect(page.locator('#claim-key')).toHaveValue(key);
  const claim = page.waitForResponse((r) => r.url().includes('action=claim'));
  await page.getByRole('button', { name: 'Add key' }).click();
  expect((await claim).status()).toBe(200);
  await expect(page.locator('#claim-status')).toHaveText('Key added to your account.');
  await expect(page).toHaveURL('/palette/account?lang=en');
  await expect(page.locator('.keys code')).toHaveText(key);
  await expect(page.locator(`[data-copy="${key}"]`)).toBeVisible();
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
  await page.locator(`[data-copy="${key}"]`).click();
  await expect(page.locator('#copy-status')).toHaveText('Copied');
  await page.request.get('/__dev/login?user_id=0');
});

test('product page: free download link, Get Pro jumps to the buy card, install address is copyable', async ({ page, context }) => {
  await seed(page);
  await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en');
  const download = page.getByRole('link', { name: 'Download the extension (free)' });
  await expect(download).toHaveAttribute('href', '/palette/download/site-to-design-token-latest.zip');
  await expect(download).toHaveAttribute('download', '');
  await expect(page.getByText('Works in Chrome, Edge and Brave. Version 0.1.0.')).toBeVisible();
  for (const h of ['What it does', 'Free vs Pro', 'Install in 1 minute', 'Already bought Pro?']) {
    await expect(page.getByRole('heading', { name: h })).toBeVisible();
  }

  const getPro = page.getByRole('link', { name: 'Get Pro — $1.49' });
  await expect(getPro).toHaveAttribute('href', '#buy');
  await getPro.click();
  await expect(page).toHaveURL(/#buy$/);
  await expect(page.locator('#buy')).toBeInViewport();

  await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  const copy = page.locator('#install [data-copy="chrome://extensions"]');
  await copy.click();
  await expect(copy).toHaveText('Copied');
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('chrome://extensions');
  await expect(page.locator('#copy-status')).toHaveText('');
  await expect(page.locator('#install a[href^="chrome:"]')).toHaveCount(0);
});

test('product page: the dev router serves a zip put in server/download, and 404s otherwise', async ({ page }) => {
  const { mkdirSync, rmSync, writeFileSync } = await import('node:fs');
  const { resolve } = await import('node:path');
  const dir = resolve(import.meta.dirname, '../../../server/download');
  const name = `e2e-${Date.now()}.zip`;
  mkdirSync(dir, { recursive: true });
  writeFileSync(resolve(dir, name), 'PK-dummy');
  try {
    const ok = await page.request.get(`/palette/download/${name}`);
    expect(ok.status()).toBe(200);
    expect(ok.headers()['content-type']).toBe('application/zip');
    expect(await ok.text()).toBe('PK-dummy');
  } finally {
    rmSync(resolve(dir, name), { force: true });
  }
  expect((await page.request.get('/palette/download/nope-missing.zip')).status()).toBe(404);
  expect((await page.request.get('/palette/download/..%2Fbootstrap.php')).status()).toBe(404);
});

test('product page fits a phone in a long-script language and in Arabic', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.setViewportSize({ width: 360, height: 780 });
  for (const lang of ['bn', 'ar']) {
    await page.goto(`/palette/checkout?lang=${lang}`);
    await expect(page.locator('#plans table')).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    expect(overflow, lang).toBe(false);
  }
});

test('landing: download, Get Pro to the checkout buy card, screenshots load, terms in the footer', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  const res = await page.goto('/palette/?lang=en');
  expect(res!.headers()['content-security-policy']).toContain("default-src 'self'");
  await expect(page.getByRole('link', { name: 'Download the extension (free)' })).toHaveAttribute('href', '/palette/download/site-to-design-token-latest.zip');
  await expect(page.locator('img').first()).toHaveJSProperty('naturalWidth', 1280);
  const srcs = await page.locator('#shots img').evaluateAll((imgs) => imgs.map((i) => (i as HTMLImageElement).getAttribute('src') ?? ''));
  expect(srcs).toHaveLength(5);
  for (const src of srcs) {
    const r = await page.request.get(src);
    expect(r.status(), src).toBe(200);
    expect(r.headers()['content-type']).toBe('image/png');
  }
  const terms = page.locator('footer').getByRole('link', { name: 'Terms' });
  await expect(terms).toHaveAttribute('href', '/palette/terms?lang=en');

  const getPro = page.getByRole('link', { name: 'Get Pro — $1.49' });
  await expect(getPro).toHaveAttribute('href', '/palette/checkout?lang=en#buy');
  await getPro.click();
  await expect(page).toHaveURL('/palette/checkout?lang=en#buy');
  await expect(page.locator('#buy')).toBeInViewport();

  await page.goto('/palette/terms?lang=en');
  await expect(page.getByRole('heading', { level: 1, name: 'Terms of sale and use' })).toBeVisible();
  await expect(page.getByText('Operated by vietnamcos.com', { exact: false })).toBeVisible();
});

test('landing: Arabic at 360px has no horizontal overflow, FAQ opens', async ({ page }) => {
  await seed(page);
  await page.setViewportSize({ width: 360, height: 780 });
  await page.goto('/palette/?lang=ar');
  await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
  expect(overflow).toBe(false);
  const first = page.locator('#faq details').first();
  await first.locator('summary').click();
  await expect(first).toHaveAttribute('open', '');
});

test('plans table: the wrapper becomes a focusable labelled region only when it overflows', async ({ page }) => {
  await seed(page);
  await page.goto('/palette/?lang=en');
  const wrap = page.locator('#plans .plans-wrap');
  await expect(wrap).not.toHaveAttribute('tabindex', /.*/);
  await page.addStyleTag({ content: '.plans { min-inline-size: 2000px; }' });
  await page.evaluate(() => window.dispatchEvent(new Event('resize')));
  await expect(wrap).toHaveAttribute('tabindex', '0');
  await expect(wrap).toHaveAttribute('role', 'region');
  await expect(wrap).toHaveAttribute('aria-label', 'Free vs Pro');
});

test('landing at 360px: the DESIGN.md sample scrolls and is a keyboard-reachable labelled region', async ({ page }) => {
  await seed(page);
  await page.setViewportSize({ width: 360, height: 780 });
  await page.goto('/palette/?lang=en');
  const pre = page.locator('#ai pre.md-sample');
  expect(await pre.evaluate((el) => el.scrollWidth > el.clientWidth)).toBe(true);
  await expect(pre).toHaveAttribute('tabindex', '0');
  await expect(pre).toHaveAttribute('role', 'region');
  await expect(pre).toHaveAttribute('aria-label', 'Made for AI coding');
  await page.setViewportSize({ width: 1280, height: 900 });
  await expect(pre).not.toHaveAttribute('tabindex', /.*/);
});

// ---- Pro + AI: plan chooser, subscription checkout, account management

const AI_REFUND = 'All sales are final. Because Pro and AI unlock instantly, we do not offer refunds after payment.';
const AI_CANCEL = 'Cancel any time; Pro and AI stay on until the end of the month you paid for.';

test('plan chooser: ?plan=ai selects Pro + AI; switching swaps the URL, summary, copy and reloads the SDK', async ({ page }) => {
  await seed(page);
  const sdk = await fakePayPal(page);
  await page.goto(`/palette/checkout?lang=en&plan=ai&ext=${EXT}`);
  const ai = page.locator('.plan-card[data-plan="ai"]');
  const pro = page.locator('.plan-card[data-plan="pro"]');
  await expect(page.getByRole('radiogroup')).toBeVisible();
  await expect(ai).toHaveAttribute('aria-checked', 'true');
  await expect(pro).toHaveAttribute('aria-checked', 'false');
  await expect(page.getByTestId('fake-paypal')).toBeVisible();
  expect(sdk).toEqual([SDK_AI]);
  await expect(page.locator('#paypal-sdk')).toHaveAttribute('src', SDK_AI);
  await expect(page.locator('#sum-item')).toHaveText('Site to Design Token Pro + AI, monthly');
  await expect(page.locator('#total')).toHaveText('$3.99');
  await expect(page.locator('#refund')).toHaveText(AI_REFUND);
  await expect(page.locator('#pay-note')).toContainText(AI_CANCEL);
  await expect(ai).toContainText('100 AI analyses a month');
  await expect(ai).toContainText('/month');

  await pro.click();
  await expect(pro).toHaveAttribute('aria-checked', 'true');
  await expect(ai).toHaveAttribute('aria-checked', 'false');
  await expect(page).toHaveURL(`/palette/checkout?lang=en&plan=pro&ext=${EXT}`);
  await expect(page.locator('#paypal-sdk')).toHaveAttribute('src', SDK);
  await expect(page.locator('#paypal-sdk')).toHaveCount(1);
  await expect(page.getByTestId('fake-paypal')).toHaveCount(1);
  expect(sdk).toEqual([SDK_AI, SDK]);
  await expect(page.locator('#sum-item')).toHaveText('Site to Design Token Pro, lifetime');
  await expect(page.locator('#total')).toHaveText('$1.49');
  await expect(page.locator('#refund')).toHaveText('All sales are final. Because Pro unlocks instantly and costs $1.49, we do not offer refunds after purchase.');
  await expect(page.locator('#pay-note')).toHaveText('PayPal also takes debit and credit cards, no PayPal account needed.');

  // keyboard: arrows move and select, like a radio group; language links keep the plan
  await pro.focus();
  await page.keyboard.press('ArrowUp');
  await expect(ai).toHaveAttribute('aria-checked', 'true');
  await expect(ai).toBeFocused();
  await expect(page).toHaveURL(/plan=ai/);
  await expect(page.locator('#paypal-sdk')).toHaveAttribute('src', SDK_AI);
  await page.locator('details.lang summary').click();
  await expect(page.getByRole('link', { name: 'Français' })).toHaveAttribute('href', `/palette/checkout?lang=fr&ext=${EXT}&plan=ai`);
});

test('plan chooser: Pro is the default, and Pro + AI says coming soon when no PayPal plan exists', async ({ page }) => {
  await seed(page);
  const sdk = await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en');
  await expect(page.locator('.plan-card[data-plan="pro"]')).toHaveAttribute('aria-checked', 'true');
  await expect(page.getByTestId('fake-paypal')).toBeVisible();
  expect(sdk).toEqual([SDK]);

  await seed(page, 'ready', 'none', 'off');
  await page.goto('/palette/checkout?lang=en&plan=ai');
  const ai = page.locator('.plan-card[data-plan="ai"]');
  await expect(ai).toHaveAttribute('aria-disabled', 'true');
  await expect(ai).toContainText('Coming soon');
  await expect(page.locator('.plan-card[data-plan="pro"]')).toHaveAttribute('aria-checked', 'true');
  await expect(page.getByTestId('fake-paypal')).toBeVisible();
  await ai.click({ force: true });   // aria-disabled: Playwright would wait for it forever
  await expect(page.locator('.plan-card[data-plan="pro"]')).toHaveAttribute('aria-checked', 'true');
  await expect(page).not.toHaveURL(/plan=pro/);
  expect(sdk).toEqual([SDK, SDK]);
  const cfg = await (await page.request.get('/palette/api.php?action=config')).json();
  expect(cfg).toMatchObject({ ai_enabled: false, ai_price: '3.99' });
});

test('Pro + AI: subscribing shows the key and hands it to the extension', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await fakeExtension(page);
  await page.goto(`/palette/checkout?lang=en&plan=ai&ext=${EXT}`);
  const pay = page.getByTestId('fake-paypal');
  await expect(pay).toBeDisabled();
  await page.locator('#email').fill('sub@studio.vn');
  await page.locator('#consent').check();
  await expect(pay).toBeEnabled();

  const start = page.waitForRequest((r) => r.url().includes('action=subscribe_start'));
  const confirm = page.waitForResponse((r) => r.url().includes('action=subscribe_confirm'));
  await pay.click();
  expect((await start).postDataJSON()).toMatchObject({ email: 'sub@studio.vn', consent: '1', lang: 'en' });
  expect((await confirm).status()).toBe(200);
  const created = await page.evaluate(() => (window as unknown as { __subCreate: { plan_id: string; custom_id: string } }).__subCreate);
  expect(created.plan_id).toBe('P-DEVPLAN001');
  expect(created.custom_id).toMatch(/^palette-sub:\d+$/);

  await expect(page.locator('#done')).toBeVisible();
  await expect(page.locator('#buy')).toBeHidden();
  const key = (await page.locator('#key').textContent()) ?? '';
  expect(key).toMatch(KEY_RE);
  await expect(page.locator('#done-title')).toHaveText("You're Pro + AI. Thank you.");
  await expect(page.locator('#ext-status')).toHaveText('Your extension is now Pro.');
  expect(await page.evaluate(() => (window as unknown as { __sent: unknown[] }).__sent)).toEqual([[EXT, { type: 'license', key }]]);
  await expect(page.locator('#emailed')).toContainText('s***@studio.vn');
});

test('Pro + AI: a first payment PayPal has not reported yet shows the pending message and locks the form', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en&plan=ai');
  await fillForm(page, 'pending@studio.vn');
  const confirm = page.waitForResponse((r) => r.url().includes('action=subscribe_confirm'));
  await page.getByTestId('fake-paypal').click();
  expect((await confirm).status()).toBe(202);
  await expect(page.locator('#pending')).toBeVisible();
  await expect(page.locator('#pending-text')).toHaveText('Payment received — your key will arrive by email in a few minutes.');
  await expect(page.locator('#done')).toBeHidden();
  await expect(page.locator('#paypal')).toBeHidden();
  await expect(page.locator('#email')).toBeDisabled();
  // no second subscription: the plan cards no longer react
  await page.locator('.plan-card[data-plan="pro"]').click({ force: true });
  await expect(page.locator('.plan-card[data-plan="ai"]')).toHaveAttribute('aria-checked', 'true');
});

test('Pro + AI: PayPal 503 while confirming says the key is not ready yet', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en&plan=ai');
  await fillForm(page, 'retry@studio.vn');
  const confirm = page.waitForResponse((r) => r.url().includes('action=subscribe_confirm'));
  await page.getByTestId('fake-paypal').click();
  expect((await confirm).status()).toBe(503);
  await expect(page.locator('#buy-error')).toHaveText("Payment received but your key isn't ready yet. Check your email in a few minutes.");
  await expect(page.locator('#paypal')).toBeHidden();
  await expect(page.locator('#done')).toBeHidden();
});

test('Pro + AI: the consent checkbox gates the subscribe button too', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en&plan=ai');
  const pay = page.getByTestId('fake-paypal');
  await expect(pay).toBeDisabled();
  await page.locator('#consent').check();
  await expect(pay).toBeEnabled();
  let started = false;
  page.on('request', (r) => { if (r.url().includes('action=subscribe_start')) started = true; });
  await page.locator('#email').fill('not-an-email');
  await pay.click();
  await expect(page.locator('#buy-error')).toHaveText('Enter a valid email address.');
  expect(started).toBe(false);
});

test('Pro + AI with California tax on: the subscription shows no tax lines, Pro gets them back', async ({ page }) => {
  await seed(page, 'ready', 'ca');
  await fakePayPal(page);
  await page.goto('/palette/checkout?lang=en&plan=ai');
  await expect(page.locator('#tax-fields')).toBeHidden();
  await expect(page.locator('#total')).toHaveText('$3.99');
  await page.locator('.plan-card[data-plan="pro"]').click();
  await expect(page.locator('#tax-fields')).toBeVisible();
  await page.locator('#country').selectOption('US');
  await page.locator('#region').selectOption('CA');
  await expect(page.locator('#total')).toHaveText('$1.60');
  await page.locator('.plan-card[data-plan="ai"]').click();
  await expect(page.locator('#total')).toHaveText('$3.99');
  await expect(page.locator('#tax-row')).toBeHidden();
  await expect(page.locator('#sum-note')).toBeHidden();
});

test('Pro + AI fits a phone in Arabic, Bengali and French', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.setViewportSize({ width: 360, height: 780 });
  for (const lang of ['ar', 'bn', 'fr']) {
    await page.goto(`/palette/checkout?lang=${lang}&plan=ai`);
    await expect(page.locator('.plan-card[data-plan="ai"]')).toHaveAttribute('aria-checked', 'true');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
    expect(overflow, lang).toBe(false);
  }
  await expect(page.locator('html')).toHaveAttribute('lang', 'fr');
  await expect(page.locator('#total')).toHaveText('3,99 $ US');
});

test('landing: Get Pro + AI goes to the checkout with the Pro + AI plan selected', async ({ page }) => {
  await seed(page);
  await fakePayPal(page);
  await page.goto('/palette/?lang=en');
  const link = page.getByRole('link', { name: 'Get Pro + AI — $3.99/month' });
  await expect(link).toHaveAttribute('href', '/palette/checkout?lang=en&plan=ai#buy');
  await link.click();
  await expect(page.locator('.plan-card[data-plan="ai"]')).toHaveAttribute('aria-checked', 'true');
  await seed(page, 'ready', 'none', 'off');
  await page.goto('/palette/?lang=en');
  await expect(page.getByRole('link', { name: /Get Pro \+ AI/ })).toHaveCount(0);
});

test('account: an AI subscription shows plan, status and renewal; cancelling asks in the page and posts sub_cancel', async ({ page }) => {
  await seed(page);
  const key = await (await page.request.get('/__dev/license?email=acct@studio.vn&plan=ai')).text();
  expect(key).toMatch(KEY_RE);
  await page.request.get('/__dev/login?user_id=7');
  await page.goto(`/palette/account?key=${key}&lang=en`);
  await page.getByRole('button', { name: 'Add key' }).click();
  await expect(page).toHaveURL('/palette/account?lang=en');

  page.on('dialog', (d) => { throw new Error('unexpected native dialog: ' + d.message()); });
  const row = page.locator(`.keyrow[data-key="${key}"]`);
  await expect(row.locator('.plan-chip')).toHaveText('Pro + AI');
  await expect(row.locator('[data-sub-status]')).toHaveText('Active');
  await expect(row.locator('[data-sub-renews]')).toContainText(/^Renews \d{4}-\d{2}-\d{2}$/);

  const open = row.getByRole('button', { name: 'Cancel subscription' });
  await open.click();
  const box = row.locator('.cancel-confirm');
  await expect(box).toBeVisible();
  await expect(box).toContainText('Cancel this subscription? Pro and AI stay on until');
  await expect(row.getByRole('button', { name: 'Keep subscription' })).toBeFocused();
  // keep: nothing is sent, the button comes back
  let cancels = 0;
  page.on('request', (r) => { if (r.url().includes('action=sub_cancel')) cancels++; });
  await row.getByRole('button', { name: 'Keep subscription' }).click();
  await expect(box).toBeHidden();
  await expect(open).toBeVisible();
  expect(cancels).toBe(0);

  await open.click();
  const cancel = page.waitForRequest((r) => r.url().includes('action=sub_cancel'));
  const reply = page.waitForResponse((r) => r.url().includes('action=sub_cancel'));
  await row.getByRole('button', { name: 'Yes, cancel subscription' }).click();
  const body = (await cancel).postDataJSON() as Record<string, string>;
  expect(body).toMatchObject({ key, lang: 'en' });
  expect(body.csrf).toMatch(/^[0-9a-f]{64}$/);
  expect(body.email).toBeUndefined();
  expect((await reply).status()).toBe(200);
  await expect(row.locator('.sub-msg')).toContainText('Your subscription is cancelled. Pro and AI stay on until');
  await expect(row.locator('[data-sub-status]')).toContainText('Cancelled, ends');
  await expect(row.locator('[data-sub-renews]')).toHaveCount(0);
  await expect(open).toBeHidden();
  // the server agrees after a reload
  await page.reload();
  await expect(row.locator('[data-sub-status]')).toContainText('Cancelled, ends');
  await expect(row.getByRole('button', { name: 'Cancel subscription' })).toHaveCount(0);
  await page.request.get('/__dev/login?user_id=0');
});

test('account: a lapsed subscription is shown as expired with no Cancel button', async ({ page }) => {
  await seed(page);
  const key = await (await page.request.get('/__dev/license?email=old@studio.vn&plan=ai&ended=1')).text();
  await page.request.get('/__dev/login?user_id=7');
  await page.goto(`/palette/account?key=${key}&lang=en`);
  await page.getByRole('button', { name: 'Add key' }).click();
  await expect(page).toHaveURL('/palette/account?lang=en');
  const row = page.locator(`.keyrow[data-key="${key}"]`);
  await expect(row.locator('[data-sub-status]')).toHaveText('Expired');
  await expect(row.getByRole('button', { name: 'Cancel subscription' })).toHaveCount(0);
  await page.request.get('/__dev/login?user_id=0');
});

test('account: cancel by key while signed out needs the subscribing email', async ({ page }) => {
  await seed(page);
  const key = await (await page.request.get('/__dev/license?email=who@studio.vn&plan=ai')).text();
  await page.request.get('/__dev/login?user_id=0');
  await page.goto('/palette/account?lang=en');
  const form = page.locator('#cancel-by');
  await expect(form.getByRole('heading', { name: 'Cancel by key' })).toBeVisible();
  await form.locator('input[name="key"]').fill(key);
  await form.locator('input[name="email"]').fill('someone-else@studio.vn');
  await form.getByRole('button', { name: 'Cancel subscription' }).click();
  await expect(page.locator('#cancel-by-status')).toContainText('Only the owner of this key can cancel its subscription');
  await expect(page.locator('#cancel-by-status')).toHaveClass(/is-error/);

  await form.locator('input[name="email"]').fill('WHO@studio.vn');
  const body = page.waitForRequest((r) => r.url().includes('action=sub_cancel'));
  await form.getByRole('button', { name: 'Cancel subscription' }).click();
  expect(((await body).postDataJSON() as Record<string, string>).csrf).toMatch(/^[0-9a-f]{64}$/);
  await expect(page.locator('#cancel-by-status')).toContainText('Your subscription is cancelled. Pro and AI stay on until');
  await expect(page.locator('#cancel-by-status')).toHaveClass(/is-ok/);
});
