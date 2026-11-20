import './bootstrap';

import Alpine from 'alpinejs';
import formBuilder from './form-builder';
import userHrisPicker from './user-hris-picker';
import permissionTree from './permission-tree';
import initHrisMapping from './hris-mapping';
import combobox from './combobox';
import dialog from './dialog';
import { lockScroll, unlockScroll } from './scroll-lock';
import initConditionalFields from './conditional-fields';

window.Alpine = Alpine;

Alpine.data('formBuilder', formBuilder);
Alpine.data('userHrisPicker', userHrisPicker);
Alpine.data('permissionTree', permissionTree);
Alpine.data('combobox', combobox);
Alpine.data('dialog', dialog);
// Page scroll lock without the sideways jump of a disappearing scrollbar (modals).
Alpine.magic('scrollLock', () => ({ lock: lockScroll, unlock: unlockScroll }));

Alpine.start();

// After Alpine: the observation modal has been moved into <body> by now.
initConditionalFields();

initHrisMapping();

/*
 * Loading feedback shared by every page:
 * - submit buttons show a spinner and cannot be pressed twice;
 * - a thin progress bar runs while the next page loads.
 * Forms or links marked data-no-loading / data-no-progress are skipped
 * (e.g. file downloads, which never leave the page).
 */
const progress = () => document.getElementById('page-progress');

function startProgress() {
    progress()?.classList.add('is-active');
}

function setLoading(button) {
    if (!button || button.dataset.loading) {
        return;
    }

    button.dataset.loading = '1';
    button.dataset.originalHtml = button.innerHTML;
    // Keep the width so the layout does not jump.
    button.style.minWidth = `${button.offsetWidth}px`;
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = `<span class="spinner" aria-hidden="true"></span><span>${button.dataset.loadingText || 'Memproses…'}</span>`;
    button.disabled = true;
}

function resetLoading() {
    document.querySelectorAll('[data-loading]').forEach((button) => {
        button.innerHTML = button.dataset.originalHtml;
        button.disabled = false;
        button.style.minWidth = '';
        button.removeAttribute('aria-busy');
        delete button.dataset.loading;
    });
    document.querySelectorAll('form[data-submitting]').forEach((form) => delete form.dataset.submitting);
    progress()?.classList.remove('is-active');
}

document.addEventListener('submit', (event) => {
    const form = event.target;

    // A confirm() that was cancelled, or a form that opts out.
    if (event.defaultPrevented || form.hasAttribute('data-no-loading')) {
        return;
    }

    if (form.dataset.submitting) {
        event.preventDefault();
        return;
    }

    form.dataset.submitting = '1';
    const button = event.submitter ?? form.querySelector('[type="submit"]');

    // Disable after the browser has captured the submitter's value.
    setTimeout(() => setLoading(button), 0);
    startProgress();
});

document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href]');

    if (
        !link ||
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ||
        link.target === '_blank' ||
        link.hasAttribute('download') ||
        link.hasAttribute('data-no-progress') ||
        link.origin !== window.location.origin ||
        (link.pathname === window.location.pathname && link.hash)
    ) {
        return;
    }

    startProgress();
});

/*
 * Theme (light/dark). The saved choice is applied before first paint by
 * components/theme-script.blade.php; this part handles the toggle button,
 * the cross-fade, and keeping other tabs and the device setting in sync.
 */
const THEME_KEY = 'theme';
const systemDark = window.matchMedia('(prefers-color-scheme: dark)');

function storedTheme() {
    try {
        const value = localStorage.getItem(THEME_KEY);
        return value === 'dark' || value === 'light' ? value : null;
    } catch {
        return null;
    }
}

function applyTheme(dark, animate = true) {
    const root = document.documentElement;

    if (root.classList.contains('dark') === dark) {
        syncThemeToggles();
        return;
    }

    if (animate) {
        root.classList.add('theme-transition');
        clearTimeout(applyTheme.timer);
        applyTheme.timer = setTimeout(() => root.classList.remove('theme-transition'), 350);
    }

    root.classList.toggle('dark', dark);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#0f172a' : '#172a46');
    syncThemeToggles();
}

function syncThemeToggles() {
    const dark = document.documentElement.classList.contains('dark');
    const label = dark ? 'Ganti ke mode terang' : 'Ganti ke mode gelap';

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.setAttribute('aria-pressed', String(dark));
    });
}

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-theme-toggle]')) {
        return;
    }

    const dark = !document.documentElement.classList.contains('dark');

    try {
        localStorage.setItem(THEME_KEY, dark ? 'dark' : 'light');
    } catch {
        // Storage blocked (private mode): the theme still applies to this page.
    }

    applyTheme(dark);
});

// Another tab changed the theme.
window.addEventListener('storage', (event) => {
    if (event.key === THEME_KEY) {
        applyTheme(storedTheme() ? storedTheme() === 'dark' : systemDark.matches);
    }
});

// Follow the device setting until the user picks a theme here.
systemDark.addEventListener('change', (event) => {
    if (!storedTheme()) {
        applyTheme(event.matches);
    }
});

syncThemeToggles();

// Pages restored from the back/forward cache must not stay "loading".
window.addEventListener('pageshow', resetLoading);

// ...and must show the theme chosen since they were cached.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        applyTheme(storedTheme() ? storedTheme() === 'dark' : systemDark.matches, false);
    }
});

/*
 * Date and time fields: a click anywhere in the field opens the browser's
 * picker, not only a click on its calendar icon. Typing still works.
 */
const PICKER_TYPES = ['date', 'time', 'datetime-local', 'month', 'week'];

document.addEventListener('click', (event) => {
    const input = event.target;

    if (
        !(input instanceof HTMLInputElement) ||
        !PICKER_TYPES.includes(input.type) ||
        input.disabled ||
        input.readOnly ||
        typeof input.showPicker !== 'function'
    ) {
        return;
    }

    try {
        input.showPicker();
    } catch {
        // Not allowed here (e.g. inside a cross-origin frame): the icon still works.
    }
});
