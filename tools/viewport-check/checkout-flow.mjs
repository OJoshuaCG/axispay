// Checkout end-to-end flow in the SANDBOX (ADR-0051): real browser, real
// pages, the Stripe.js stub and the sandbox gateway. Not part of CI; run it
// against a local server started with AXISPAY_CHECKOUT_SANDBOX=true (see
// docs/development.md, "Checkout sandbox").
//
//   URLS='{"active":"http://pay.localhost:8000/l/...", ...}' node checkout-flow.mjs [--shots ./shots]
//
// URLS is the JSON printed by `php artisan axispay:checkout:demo --json`.
// Flows: decline → Turnstile → 3D Secure approved → paid; processing → paid
// after polling; expired; canceled; already paid from another session;
// 3D Secure failed. Exit code 1 on the first failed expectation.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const URLS = JSON.parse(process.env.URLS ?? '{}');
const shotsIndex = process.argv.indexOf('--shots');
const SHOTS_DIR = shotsIndex > -1 ? process.argv[shotsIndex + 1] : null;
let failures = 0;

if (SHOTS_DIR) mkdirSync(SHOTS_DIR, { recursive: true });

function check(condition, message) {
    console.log(`${condition ? 'ok  ' : 'FAIL'} ${message}`);
    if (!condition) failures++;
}

async function shot(page, name) {
    if (SHOTS_DIR) await page.screenshot({ path: join(SHOTS_DIR, `checkout-${name}.png`), fullPage: true });
}

async function newPage(browser, { scheme = 'light', width = 390, locale = null } = {}) {
    const context = await browser.newContext({ viewport: { width, height: 900 }, colorScheme: scheme });
    const page = await context.newPage();
    page.on('pageerror', (error) => check(false, `no page error (${error.message})`));
    page.on('console', (message) => {
        if (process.env.DEBUG) console.log('console:', message.type(), message.text().slice(0, 200));
        if (message.type() === 'error' && /Content Security Policy|Refused to/i.test(message.text())) {
            check(false, `no CSP violation (${message.text().slice(0, 160)})`);
        }
    });
    page.localeParam = locale;

    return page;
}

async function open(page, url) {
    const target = page.localeParam ? `${url}?lang=${page.localeParam}` : url;
    await page.goto(target, { waitUntil: 'networkidle' });
}

async function turnstileToken(page) {
    // Test site key: always passes; wait for the token in the hidden input it adds.
    await page.waitForFunction(() => {
        const input = document.querySelector('[data-turnstile] input[name="cf-turnstile-response"]');

        return input !== null && input.value !== '';
    }, null, { timeout: 30000 });
}

async function pay(page, scenario) {
    await page.waitForSelector('#sandbox-scenario');
    await page.selectOption('#sandbox-scenario', scenario);

    if (await page.locator('[data-turnstile-slot]').isVisible()) {
        await turnstileToken(page);
    }

    await page.click('[data-pay-button]');
}

(async () => {
    const browser = await chromium.launch();

    // 1. Decline → Turnstile → 3D Secure approved → paid (Spanish, light, phone width).
    {
        const page = await newPage(browser, { width: 375 });
        await open(page, URLS.active);
        await shot(page, 'active-375-es-light');
        await page.fill('#payer-email', 'ana@example.com');
        await pay(page, 'decline');
        const alert = page.locator('[data-checkout-alert="error"]');
        await alert.waitFor({ state: 'visible' });
        check((await alert.textContent()).includes('La tarjeta fue rechazada'), 'decline shows the generic message');
        await shot(page, 'declined-375-es-light');

        await page.locator('[data-turnstile-slot]').waitFor({ state: 'visible', timeout: 15000 });
        check(true, 'Turnstile appears after a decline');
        await turnstileToken(page);
        await shot(page, 'turnstile-375-es-light');

        await pay(page, 'threeds');
        const dialog = page.locator('dialog');
        await dialog.waitFor({ state: 'visible' });
        await shot(page, '3ds-375-es-light');
        await page.click('[data-sandbox-bank="approve"]');
        await page.waitForURL(/\/complete$/, { timeout: 20000 });
        check((await page.textContent('h1')).includes('Pago realizado'), '3D Secure approved ends on "Pago realizado"');
        await shot(page, 'paid-375-es-light');
        await page.context().close();
    }

    // 2. The same (paid) link from another session: "already paid" (dark, 320).
    {
        const page = await newPage(browser, { scheme: 'dark', width: 320 });
        await open(page, URLS.active);
        check((await page.textContent('h1')).includes('Este cobro ya fue pagado'), 'another session sees "already paid"');
        await shot(page, 'already-paid-320-es-dark');
        await page.context().close();
    }

    // 3. Processing → polling → paid (English, dark, desktop).
    {
        const page = await newPage(browser, { scheme: 'dark', width: 1280 });
        await open(page, URLS.active_en);
        await shot(page, 'active-1280-en-dark');
        await pay(page, 'processing');
        await page.waitForURL(/\/complete$/, { timeout: 20000 });
        check((await page.textContent('h1')).includes('processing'), 'processing panel shown');
        await shot(page, 'processing-1280-en-dark');
        await page.waitForFunction(() => document.querySelector('h1')?.textContent.includes('Payment complete'), null, { timeout: 30000 });
        check(true, 'polling reaches "Payment complete"');
        await page.context().close();
    }

    // 4. 3D Secure failed, with every payer field (Spanish, light, 1440).
    {
        const page = await newPage(browser, { width: 1440 });
        await open(page, URLS.all_fields);
        await shot(page, 'all-fields-1440-es-light');
        await page.click('[data-pay-button]');
        await page.locator('#payer-email-error:not(.hidden)').waitFor({ timeout: 10000 }).catch(() => {});
        check(await page.locator('[data-field-error]:not(.hidden)').count() > 0, 'missing required fields are reported inline');
        await shot(page, 'field-errors-1440-es-light');
        await page.fill('#payer-email', 'ana@example.com');
        await page.fill('#payer-full_name', 'Ana López');
        await page.fill('#payer-phone', '5512345678');
        await page.fill('#payer-billing_address-line1', 'Av. Reforma 1');
        await page.fill('#payer-billing_address-city', 'Ciudad de México');
        await page.fill('#payer-billing_address-state', 'CDMX');
        await page.fill('#payer-billing_address-postal_code', '06600');
        await pay(page, 'threeds');
        await page.locator('dialog').waitFor({ state: 'visible' });
        await page.click('[data-sandbox-bank="fail"]');
        const alert = page.locator('[data-checkout-alert="error"]');
        await alert.waitFor({ state: 'visible', timeout: 15000 });
        check((await alert.textContent()).includes('No pudimos completar la verificación'), 'failed 3D Secure shows its message');
        await shot(page, '3ds-failed-1440-es-light');
        await page.context().close();
    }

    // 5. Expired and canceled.
    for (const [name, text] of [['expired', 'expiró'], ['canceled', 'ya no está disponible']]) {
        const page = await newPage(browser, { width: 375 });
        await open(page, URLS[name]);
        check((await page.textContent('h1')).includes(text), `${name} link shows its state`);
        await shot(page, `${name}-375-es-light`);
        await page.context().close();
    }

    // 6. Unknown token: 404 page.
    {
        const page = await newPage(browser, { width: 375 });
        const response = await page.goto(URLS.active.replace(/\/l\/.*/, '/l/doesNotExist000000000000000'));
        check(response.status() === 404, 'unknown token answers 404');
        await shot(page, 'not-found-375');
        await page.context().close();
    }

    await browser.close();
    console.log(failures === 0 ? '\nAll checkout flows passed.' : `\n${failures} check(s) failed.`);
    process.exit(failures === 0 ? 0 : 1);
})();
