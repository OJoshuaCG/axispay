/**
 * Theme preference handling (light | dark | system).
 *
 * The pre-paint script in the layout <head> applies the saved theme before first
 * paint. This module syncs the theme radio groups, handles keyboard navigation
 * and persists changes.
 *
 * "system" is expressed by removing data-theme from <html>, which lets
 * `color-scheme: light dark` follow the OS preference.
 *
 * Browser UI color (<meta name="theme-color">): with a manual theme, every
 * theme-color tag is set to the computed page background, so the browser
 * chrome matches the page instead of the OS preference. With "system", the
 * server-rendered values (one per prefers-color-scheme media query) are
 * restored. No color is hardcoded here.
 *
 * This module announces no text: all labels are server-rendered and translated.
 */

export const STORAGE_KEY = 'theme';
export const THEMES = ['light', 'dark', 'system'];

const OPTION = '[data-theme-option]';
const GROUP = '[data-theme-toggle]';
const THEME_COLOR_META = 'meta[data-theme-color]';

/** Server-rendered theme-color values, keyed by meta element. */
const defaultThemeColors = new Map();

function rememberThemeColorDefaults() {
    document.querySelectorAll(THEME_COLOR_META).forEach((meta) => {
        if (!defaultThemeColors.has(meta)) {
            // For a manual theme the pre-paint script already overwrote
            // `content` and kept the server value in data-default-content.
            defaultThemeColors.set(meta, meta.getAttribute('data-default-content') ?? meta.getAttribute('content'));
        }
    });
}

function syncThemeColor(preference) {
    const metas = document.querySelectorAll(THEME_COLOR_META);

    if (preference === 'light' || preference === 'dark') {
        // Resolved against the data-theme just applied (forces style recalc).
        const pageColor = getComputedStyle(document.documentElement).backgroundColor;
        metas.forEach((meta) => meta.setAttribute('content', pageColor));
        return;
    }

    metas.forEach((meta) => {
        const original = defaultThemeColors.get(meta);

        if (original) {
            meta.setAttribute('content', original);
        }
    });
}

export function readPreference() {
    try {
        const value = window.localStorage.getItem(STORAGE_KEY);
        return THEMES.includes(value) ? value : 'system';
    } catch {
        return 'system';
    }
}

function savePreference(preference) {
    try {
        window.localStorage.setItem(STORAGE_KEY, preference);
    } catch {
        // Storage can be unavailable (privacy mode, blocked storage). The theme
        // still applies for the current page.
    }
}

export function applyTheme(preference) {
    const root = document.documentElement;

    if (preference === 'light' || preference === 'dark') {
        root.dataset.theme = preference;
    } else {
        delete root.dataset.theme;
    }

    syncThemeColor(preference);
}

/** Mark the checked option in every group; it becomes the group's tab stop. */
function syncToggles(preference) {
    document.querySelectorAll(OPTION).forEach((option) => {
        const checked = option.dataset.themeOption === preference;
        option.setAttribute('aria-checked', String(checked));
        option.tabIndex = checked ? 0 : -1;
    });
}

export function setTheme(preference) {
    if (!THEMES.includes(preference)) {
        return;
    }

    applyTheme(preference);
    savePreference(preference);
    syncToggles(preference);
}

function onKeydown(event) {
    const option = event.target instanceof Element ? event.target.closest(OPTION) : null;
    const group = option?.closest(GROUP);

    if (!option || !group) {
        return;
    }

    const options = [...group.querySelectorAll(OPTION)];
    const index = options.indexOf(option);
    const last = options.length - 1;

    const next = {
        ArrowRight: index === last ? 0 : index + 1,
        ArrowDown: index === last ? 0 : index + 1,
        ArrowLeft: index === 0 ? last : index - 1,
        ArrowUp: index === 0 ? last : index - 1,
        Home: 0,
        End: last,
    }[event.key];

    if (next === undefined) {
        return;
    }

    event.preventDefault();
    setTheme(options[next].dataset.themeOption);
    options[next].focus();
}

export function initThemeToggle() {
    rememberThemeColorDefaults();

    const preference = readPreference();
    syncToggles(preference);
    syncThemeColor(preference);

    document.addEventListener('click', (event) => {
        const option = event.target instanceof Element ? event.target.closest(OPTION) : null;

        if (option) {
            setTheme(option.dataset.themeOption);
        }
    });

    document.addEventListener('keydown', onKeydown);

    // Keep other open tabs in sync.
    window.addEventListener('storage', (event) => {
        if (event.key === STORAGE_KEY) {
            const preference = readPreference();
            applyTheme(preference);
            syncToggles(preference);
        }
    });
}
