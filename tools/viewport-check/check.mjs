// Viewport check: loads pages in every locale x scheme x width and reports
// horizontal overflow, clipped text, small touch targets and <16px inputs.
// A manual browser check, not a test suite. See docs/frontend/responsive.md.
//
//   cd tools/viewport-check && npm install && npx playwright install chromium
//   BASE_URL=http://127.0.0.1:8000 node check.mjs [--shots ./shots]
//
// PATHS=/,/checkout limits the pages. Exit code 1 when any issue is found.
//
// Filament panels (signed-in pages): set LOGIN_EMAIL and LOGIN_PASSWORD and,
// when the account has 2FA, LOGIN_TOTP_SECRET (base32). The script signs in
// through the real login form (and 2FA challenge) once per browser context.
// There is no auth bypass: use a local account, e.g. the DevelopmentSeeder
// owner after enrolling 2FA with a secret you control.
//
//   BASE_URL=http://app.localhost:8000 PATHS=/login SKIP_LOGIN=1 node check.mjs
//   BASE_URL=http://app.localhost:8000 PATHS=/users LOGIN_EMAIL=... LOGIN_PASSWORD=... LOGIN_TOTP_SECRET=... node check.mjs
import { chromium } from 'playwright';
import { createHmac } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.BASE_URL ?? 'http://127.0.0.1:8000';
const PATHS = (process.env.PATHS ?? '/,/design-system').split(',');
const LOCALES = ['en', 'es'];
const SCHEMES = ['light', 'dark'];
const WIDTHS = [320, 375, 414, 768, 1024, 1280, 1440];
const MIN_TARGET = 44;
const shotsIndex = process.argv.indexOf('--shots');
const SHOTS_DIR = shotsIndex > -1 ? process.argv[shotsIndex + 1] : null;
const SHOTS = new Set(['320-es-dark', '375-en-light', '1440-es-light']);
const LOGIN = process.env.LOGIN_EMAIL && !process.env.SKIP_LOGIN
    ? { email: process.env.LOGIN_EMAIL, password: process.env.LOGIN_PASSWORD ?? '', totp: process.env.LOGIN_TOTP_SECRET ?? '' }
    : null;

// RFC 6238 TOTP (SHA-1, 30 s, 6 digits) from a base32 secret.
function totp(secret) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const c of secret.replace(/=+$/, '').toUpperCase()) bits += alphabet.indexOf(c).toString(2).padStart(5, '0');
    const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const hmac = createHmac('sha1', key).update(counter).digest();
    const offset = hmac[hmac.length - 1] & 0xf;
    return String((hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

// Signs in through the Filament login form (and TOTP challenge when asked).
async function login(page) {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
    await page.fill('input[type=email]', LOGIN.email);
    await page.fill('input[type=password]', LOGIN.password);
    await page.click('form[wire\\:submit] button[type=submit]');
    const otp = page.locator('input[autocomplete="one-time-code"]').first();
    await Promise.race([page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 }), otp.waitFor({ timeout: 15000 })]);
    if (new URL(page.url()).pathname.startsWith('/login')) {
        await otp.fill(totp(LOGIN.totp));
        await page.click('form[wire\\:submit] button[type=submit]');
        await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 });
    }
}

function audit(minTarget) {
    const describe = (el) => {
        const id = el.id ? `#${el.id}` : '';
        const cls = typeof el.className === 'string' && el.className ? `.${el.className.trim().split(/\s+/).slice(0, 3).join('.')}` : '';
        const text = (el.getAttribute('aria-label') || el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40);
        return `${el.tagName.toLowerCase()}${id}${cls} "${text}"`;
    };
    const isHidden = (el) => {
        const cs = getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') return true;
        const r = el.getBoundingClientRect();
        if (r.width <= 1 && r.height <= 1) return true; // sr-only
        return el.closest('.sr-only') !== null && !el.matches(':focus');
    };

    const vw = window.innerWidth;
    const result = {
        lang: document.documentElement.lang,
        pageOverflow: document.documentElement.scrollWidth > vw,
        scrollWidth: document.documentElement.scrollWidth,
        escaping: [],
        clipped: [],
        smallTargets: [],
        smallInputs: [],
    };

    for (const el of document.body.querySelectorAll('*')) {
        if (isHidden(el) || el.closest('svg')) continue;
        const r = el.getBoundingClientRect();
        // Elements that stick out of the viewport horizontally.
        if (r.right > vw + 0.5 || r.left < -0.5) {
            // Skip if an ancestor clips it inside its own scroll container.
            let clippedByScroller = false;
            for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
                const ox = getComputedStyle(p).overflowX;
                if (ox === 'auto' || ox === 'scroll' || ox === 'hidden' || ox === 'clip') { clippedByScroller = true; break; }
            }
            if (!clippedByScroller) result.escaping.push(`${describe(el)} [${Math.round(r.left)}..${Math.round(r.right)}]`);
        }
        // Content wider than its box that is neither scrollable nor ellipsized.
        const cs = getComputedStyle(el);
        if (el.scrollWidth > el.clientWidth + 1 && el.clientWidth > 0) {
            const before = getComputedStyle(el, '::before');
            // An absolute ::before hit-area extension (sm buttons) is not content.
            const hitArea = before.content !== 'none' && before.position === 'absolute' && el.scrollWidth - el.clientWidth <= 8;
            const intended = ['auto', 'scroll'].includes(cs.overflowX) || cs.textOverflow === 'ellipsis' || hitArea;
            if (!intended) result.clipped.push(`${describe(el)} (${el.scrollWidth}>${el.clientWidth})`);
        }
    }

    const interactive = 'a[href], button, input:not([type=hidden]), select, textarea, [role=radio], [role=button], [tabindex="0"]';
    for (const el of document.querySelectorAll(interactive)) {
        if (isHidden(el)) continue;
        // Inline prose links are exempt (WCAG 2.5.8 "inline" exception).
        if (el.tagName === 'A' && !el.getAttribute('class') && getComputedStyle(el).display === 'inline') continue;
        const r = el.getBoundingClientRect();
        let w = r.width, h = r.height;
        // A transparent absolutely positioned ::before extends the hit area.
        const b = getComputedStyle(el, '::before');
        if (b.content !== 'none' && b.position === 'absolute') {
            const n = (v) => (v.endsWith('px') ? parseFloat(v) : 0);
            w += Math.max(0, -n(b.left)) + Math.max(0, -n(b.right));
            h += Math.max(0, -n(b.top)) + Math.max(0, -n(b.bottom));
        }
        if (w < minTarget - 0.5 || h < minTarget - 0.5) result.smallTargets.push(`${describe(el)} ${Math.round(w)}x${Math.round(h)}`);
        if (['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName) && parseFloat(getComputedStyle(el).fontSize) < 16) {
            result.smallInputs.push(`${describe(el)} ${getComputedStyle(el).fontSize}`);
        }
    }
    return result;
}

const browser = await chromium.launch();
let problems = 0;

// Sign in once and share the session: a TOTP code can only be used once.
let storageState;
if (LOGIN) {
    const context = await browser.newContext();
    const page = await context.newPage();
    await login(page);
    storageState = await context.storageState();
    await context.close();
}
const summary = [];

for (const path of PATHS) {
    for (const lang of LOCALES) {
        for (const scheme of SCHEMES) {
            const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: scheme, storageState });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', (e) => errors.push(String(e)));
            page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
            const sep = path.includes('?') ? '&' : '?';
            const response = await page.goto(`${BASE}${path}${sep}lang=${lang}`, { waitUntil: 'networkidle' });

            for (const width of WIDTHS) {
                await page.setViewportSize({ width, height: 900 });
                await page.waitForTimeout(50);
                const r = await page.evaluate(audit, MIN_TARGET);
                const key = `${width}-${lang}-${scheme}`;
                const issues = r.escaping.length + r.clipped.length + r.smallTargets.length + r.smallInputs.length + (r.pageOverflow ? 1 : 0);
                problems += issues;
                const line = `${path} ${key} status=${response.status()} lang=${r.lang} overflow=${r.pageOverflow ? `YES(${r.scrollWidth})` : 'no'} escaping=${r.escaping.length} clipped=${r.clipped.length} small=${r.smallTargets.length} inputs<16=${r.smallInputs.length}`;
                summary.push(line);
                console.log(line);
                for (const [label, list] of [['escaping', r.escaping], ['clipped', r.clipped], ['small', r.smallTargets], ['inputs<16', r.smallInputs]]) {
                    for (const item of list.slice(0, 12)) console.log(`    ${label}: ${item}`);
                }
                if (SHOTS_DIR && SHOTS.has(key)) {
                    mkdirSync(SHOTS_DIR, { recursive: true });
                    const name = `${new URL(BASE).hostname.split('.')[0]}-${path === '/' ? 'root' : path.replace(/\W+/g, '')}-${key}.png`;
                    await page.screenshot({ path: join(SHOTS_DIR, name), fullPage: true });
                }
            }
            if (errors.length) { problems += errors.length; console.log(`    JS errors (${path} ${lang} ${scheme}):`, errors); }
            await context.close();
        }
    }
}

await browser.close();
console.log(`\nTotal issues: ${problems}`);
process.exit(problems ? 1 : 0);
