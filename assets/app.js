import './stimulus_bootstrap.js';
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/app.css';

// Bootstrap's JS (dropdowns in the navbar, tooltips) — Popper comes along via the importmap.
import 'bootstrap';

/*
 * Light/dark toggle. Bootstrap 5.3 switches theme from [data-bs-theme] on <html>;
 * the page follows the OS setting until the user pins a side, which is remembered.
 */
const STORAGE_KEY = 'siab.theme';

function prefersDark() {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function effectiveTheme() {
    return localStorage.getItem(STORAGE_KEY) ?? (prefersDark() ? 'dark' : 'light');
}

function applyTheme(theme) {
    document.documentElement.dataset.bsTheme = theme;
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.textContent = theme === 'dark' ? '☀' : '☾';
        button.setAttribute('aria-label', theme === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro');
    });
}

applyTheme(effectiveTheme());

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-theme-toggle]')) {
        return;
    }

    const next = effectiveTheme() === 'dark' ? 'light' : 'dark';
    localStorage.setItem(STORAGE_KEY, next);
    applyTheme(next);
});

// Turbo replaces <body>, so re-assert the theme (and the toggle's icon) after navigating.
document.addEventListener('turbo:load', () => applyTheme(effectiveTheme()));

/*
 * An article's citing works are fetched the first time it is expanded, not shipped
 * with the page: one run holds ~1,470 of them across 165 articles, and almost
 * nobody opens more than a couple.
 *
 * Listening in the capture phase because `toggle` does not bubble — delegation on
 * document would never see it otherwise. Without JavaScript the slot keeps the
 * plain link it was rendered with, which serves the same list on its own page.
 */
document.addEventListener('toggle', (event) => {
    const details = event.target;

    if (!details.matches?.('details[data-citas-url]') || !details.open || details.dataset.loaded) {
        return;
    }

    const slot = details.querySelector('[data-citas-slot]');
    if (!slot) {
        return;
    }

    details.dataset.loaded = '1';
    slot.textContent = 'Cargando…';

    fetch(details.dataset.citasUrl, { headers: { Accept: 'text/html' } })
        .then((response) => (response.ok ? response.text() : Promise.reject(new Error(response.status))))
        .then((html) => { slot.innerHTML = html; })
        .catch(() => {
            // Cleared so closing and reopening tries again, rather than leaving the
            // article looking like it has no citations.
            delete details.dataset.loaded;
            slot.innerHTML = '<span class="small text-danger-emphasis">No se pudieron cargar las citas. Ciérralo y vuelve a abrirlo.</span>';
        });
}, true);

/*
 * Foldable sections ([data-fold]) remember whether they were left open.
 *
 * Not a nicety here: a run page reloads itself every 5 s while the analysis runs
 * and every 10 s while a report is being written (the meta refresh in
 * analysis/show.html.twig), so a section folded away would spring back open a few
 * seconds later. Remembering the choice is what makes folding work at all on the
 * one page that has it.
 */
const FOLD_KEY = 'siab.folds';

function readFolds() {
    try {
        return JSON.parse(localStorage.getItem(FOLD_KEY)) ?? {};
    } catch {
        // Corrupt or unavailable storage is not worth breaking the page over; the
        // sections just open in the state the server rendered them.
        return {};
    }
}

function applyFolds() {
    const folds = readFolds();
    // A link straight to a section (the history's "ver informe" points at #informe)
    // must not land on a fold the reader closed last week: the fragment wins.
    const targeted = decodeURIComponent(window.location.hash.slice(1));

    document.querySelectorAll('details[data-fold]').forEach((details) => {
        if (details.id && details.id === targeted) {
            details.open = true;

            return;
        }

        const remembered = folds[details.dataset.fold];
        if (remembered !== undefined) {
            details.open = remembered;
        }
    });
}

applyFolds();
document.addEventListener('turbo:load', applyFolds);

document.addEventListener('toggle', (event) => {
    const details = event.target;

    if (!details.matches?.('details[data-fold]')) {
        return;
    }

    const folds = readFolds();
    folds[details.dataset.fold] = details.open;
    localStorage.setItem(FOLD_KEY, JSON.stringify(folds));
}, true);

/*
 * Buttons that take a while (starting the engine waits for its /health): show the
 * wait instead of leaving the page looking unresponsive. Turbo re-enables the
 * button itself when the response arrives.
 */
document.addEventListener('submit', (event) => {
    const button = event.target.querySelector('[data-pending]');
    if (!button) {
        return;
    }

    // Deferred: a button disabled inside its own submit handler can drop the
    // submitter from the request in some browsers.
    setTimeout(() => {
        button.disabled = true;
        button.textContent = button.dataset.pending;
    });
});

/*
 * Show/hide the password on the login form. Delegated like the theme toggle, and
 * kept out of an inline onclick so the page stays clean under a CSP.
 */
const EYE = 'M8 3c3.2 0 5.9 2.1 7 5-1.1 2.9-3.8 5-7 5S2.1 10.9 1 8c1.1-2.9 3.8-5 7-5zm0 1.5A3.5 3.5 0 1 0 8 11.5 3.5 3.5 0 0 0 8 4.5zm0 1.5a2 2 0 1 1 0 4 2 2 0 0 1 0-4z';
const EYE_OFF = 'M2.7 2 2 2.7l2 2C2.6 5.6 1.6 6.7 1 8c1.1 2.9 3.8 5 7 5 1.2 0 2.4-.3 3.4-.9l1.9 1.9.7-.7L2.7 2zm3 3.7 1.2 1.2a2 2 0 0 0 2.5 2.5l1.2 1.2A3.5 3.5 0 0 1 5.7 5.7zM8 3c-.7 0-1.3.1-1.9.3l1.3 1.3A3.5 3.5 0 0 1 11.4 9l2.2 2.2c.6-.9 1.1-1.9 1.4-3.2-1.1-2.9-3.8-5-7-5z';

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) {
        return;
    }

    const field = document.getElementById(button.dataset.passwordToggle);
    if (!field) {
        return;
    }

    const revealed = field.type === 'text';
    field.type = revealed ? 'password' : 'text';
    button.setAttribute('aria-pressed', String(!revealed));
    button.setAttribute('aria-label', revealed ? 'Mostrar contraseña' : 'Ocultar contraseña');
    button.querySelector('path')?.setAttribute('d', revealed ? EYE : EYE_OFF);
});
