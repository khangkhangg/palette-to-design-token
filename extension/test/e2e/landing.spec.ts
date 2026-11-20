import { expect, test, type Page } from '@playwright/test';

/**
 * Landing hero stage (docs/directory/hero-motion-mockup.html) and "Try it on a site", against the real PHP
 * page under `php -S` (playwright.checkout.config.ts). Screenshots of each step go to extension/test-results/hero-build/.
 */
const SHOTS = 'test-results/hero-build';
const current = (page: Page) => page.locator('#steps button[aria-current="step"]');

async function openStage(page: Page, lang = 'en') {
    await page.goto(`/palette/?lang=${lang}`);
    await page.locator('#stage').scrollIntoViewIfNeeded();
    await page.mouse.move(0, 0);
}

test('landing stage: autoplays from step 1 to step 2', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await openStage(page);
    await expect(current(page)).toHaveAttribute('data-i', '0');
    await expect(page.locator('#cap-n')).toHaveText('1 of 6');
    await expect(current(page)).toHaveAttribute('data-i', '1', { timeout: 9_000 });
    await expect(page.locator('#cap-n')).toHaveText('2 of 6');
});

test('landing stage: clicking a step selects it and pauses autoplay', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await openStage(page);
    await page.locator('#steps button[data-i="2"]').click();
    await page.mouse.move(0, 0);
    await expect(current(page)).toHaveAttribute('data-i', '2');
    await expect(page.locator('#play')).toHaveAttribute('aria-label', 'Play the demo');
    await expect(page.locator('#stage')).toHaveClass(/is-paused/);
    await page.waitForTimeout(6_500);
    await expect(current(page)).toHaveAttribute('data-i', '2');
});

test('landing stage: arrow keys move between steps', async ({ page }) => {
    await openStage(page);
    await page.locator('#steps button[data-i="0"]').focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('#steps button[data-i="1"]')).toBeFocused();
    await page.keyboard.press('ArrowLeft');
    await page.keyboard.press('ArrowLeft');
    await expect(page.locator('#steps button[data-i="5"]')).toBeFocused();
});

test('landing stage: reduced motion means no autoplay and finished steps', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await openStage(page);
    await expect(page.locator('#play')).toHaveAttribute('aria-label', 'Play the demo');
    await expect(page.locator('#panel')).not.toHaveClass(/closed/);
    await page.waitForTimeout(6_000);
    await expect(current(page)).toHaveAttribute('data-i', '0');
    for (let i = 0; i < 6; i++) {
        await page.locator(`#steps button[data-i="${i}"]`).click();
        await expect(page.locator('#cap-n')).toHaveText(`${i + 1} of 6`);
        await page.locator('#stage').screenshot({ path: `${SHOTS}/step-${i + 1}.png` });
    }
});

for (const width of [375, 768, 1280]) {
    test(`landing: no horizontal overflow at ${width} px, in en and ar`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        for (const lang of ['en', 'ar']) {
            await openStage(page, lang);
            const [scroll, client] = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
            expect(scroll, `${lang} at ${width}`).toBeLessThanOrEqual(client);
        }
    });
}

test('landing: the try-it site switch changes the CSS output', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/palette/?lang=en');
    const code = page.locator('#try-side pre');
    await expect(code).toContainText('--color-primary: #1F5E4B');
    await page.locator('#try-pick button', { hasText: 'Copperkettle' }).click();
    await expect(page.locator('#try-pick button[aria-pressed="true"]')).toHaveText('Copperkettle');
    await expect(code).toContainText('--color-primary: #6B3A2A');
    await page.locator('#try-side [data-f="tw"]').click();
    await expect(code).toContainText("primary: '#6B3A2A'");
});
