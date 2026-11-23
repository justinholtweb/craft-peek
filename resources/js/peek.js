/*
 * Peek — control panel behaviour. Craft 6 loads this in every CP page's <head>, before Vue renders,
 * and screens change through Inertia visits, so everything is delegated from `document` and works
 * whenever the diff screen's markup appears. No jQuery and no Craft.* helpers: neither exists here.
 */
(() => {
    'use strict';

    // Field Diff / Visual Preview tabs
    document.addEventListener('click', (event) => {
        const tab = event.target.closest('.peek-tab[data-tab]');
        if (!tab) {
            return;
        }

        const root = tab.closest('[data-peek-diff]');
        if (!root) {
            return;
        }

        root.querySelectorAll('.peek-tab').forEach((t) => t.classList.toggle('is-active', t === tab));
        root.querySelectorAll('[data-tab-content]').forEach((panel) => {
            panel.hidden = panel.dataset.tabContent !== tab.dataset.tab;
        });
    });

    // Collapse and expand a changed field
    document.addEventListener('click', (event) => {
        const header = event.target.closest('.peek-field-diff-header');
        if (!header) {
            return;
        }

        const block = header.closest('.peek-field-diff');
        if (!block || !block.classList.contains('has-changes')) {
            return;
        }

        const collapsed = block.classList.toggle('is-collapsed');
        header.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });

    // Scroll the two preview panes together. Only works when the site is on the CP's origin;
    // a cross-origin frame can't be read, and each pane then scrolls on its own.
    let syncing = false;

    const sync = (source) => {
        const container = source.closest('[data-peek-preview]');
        if (!container || syncing) {
            return;
        }

        const other = container.querySelector(
            `[data-peek-iframe="${source.dataset.peekIframe === 'draft' ? 'canonical' : 'draft'}"]`,
        );

        try {
            const from = source.contentDocument.scrollingElement;
            const to = other.contentDocument.scrollingElement;
            syncing = true;
            to.scrollTop = from.scrollTop;
            to.scrollLeft = from.scrollLeft;
        } catch (e) {
            // Cross-origin
        } finally {
            syncing = false;
        }
    };

    // `load` doesn't bubble, but it can be caught on the way down.
    document.addEventListener('load', (event) => {
        const frame = event.target;
        if (!(frame instanceof HTMLIFrameElement) || !frame.dataset.peekIframe) {
            return;
        }

        try {
            frame.contentWindow.addEventListener('scroll', () => sync(frame), { passive: true });
        } catch (e) {
            // Cross-origin
        }
    }, true);
})();

/*
 * Peek's dashboard keeps itself current. With Laravel Echo on the page and broadcasting set up, it
 * listens on the private `peek.dashboard` channel; otherwise it refetches every `data-refresh`
 * seconds while the tab is visible. Either way it asks the server for its own content, so what it
 * shows is always filtered by the viewer's permissions.
 */
(() => {
    'use strict';

    let timer = null;
    let channel = null;

    const root = () => document.querySelector('[data-peek-dashboard]');

    const refresh = async () => {
        const el = root();
        if (!el || document.hidden) {
            return;
        }

        try {
            const response = await fetch(el.dataset.fragmentUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (response.ok) {
                const { html } = await response.json();
                el.innerHTML = html;
            }
        } catch (e) {
            // Offline or signed out: try again next time.
        }
    };

    const stop = () => {
        clearInterval(timer);
        timer = null;
        if (channel && window.Echo) {
            window.Echo.leave('peek.dashboard');
            channel = null;
        }
    };

    const start = () => {
        stop();
        const el = root();
        if (!el) {
            return;
        }

        if (el.dataset.live === '1' && window.Echo && typeof window.Echo.private === 'function') {
            channel = window.Echo.private('peek.dashboard').listen('.peek.dashboard.changed', refresh);
            return;
        }

        const seconds = parseInt(el.dataset.refresh || '0', 10);
        if (seconds > 0) {
            timer = setInterval(refresh, seconds * 1000);
        }
    };

    // The script runs before Vue renders the screen, and screens change by Inertia visits.
    document.addEventListener('inertia:navigate', () => setTimeout(start, 0));
    document.addEventListener('visibilitychange', () => { if (!document.hidden && root()) { refresh(); } });
    let tries = 0;
    const wait = setInterval(() => {
        if (root() || ++tries > 20) {
            clearInterval(wait);
            start();
        }
    }, 250);
})();
