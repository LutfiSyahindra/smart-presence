import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';

const home = '/absensi/mobile';
const stateKey = 'rsarsy.mobile.navigation';
const backEvent = 'rsarsy:back';
type NavigationState = { previous: string | null; owner: unknown };
type BackHandler = () => boolean;

let started = false;
let current: string | null = null;
let previous: string | null = null;
let owner: unknown;
let restoring = false;
let pending = false;
const handlers: BackHandler[] = [];

function mobileUrl(value: unknown): string | null {
    if (typeof value !== 'string' || typeof window === 'undefined') return null;
    try {
        const url = new URL(value, window.location.origin);
        return url.origin === window.location.origin && (url.pathname === home || url.pathname.startsWith(`${home}/`))
            ? `${url.pathname}${url.search}${url.hash}`
            : null;
    } catch {
        return null;
    }
}

function pageOwner(page: Page): unknown {
    return (page.props.auth as { user?: { id?: unknown } } | undefined)?.user?.id;
}

function remember(): void {
    if (current) router.remember({ previous, owner } satisfies NavigationState, stateKey);
    try {
        if (current) {
            window.sessionStorage.setItem(stateKey, JSON.stringify({ current, previous, owner, length: window.history.length }));
        } else {
            window.sessionStorage.removeItem(stateKey);
        }
    } catch {
        // Back navigation still works when the browser disables storage.
    }
}

/** Initialize before mounting Inertia; keep mobile navigation in its encrypted/ordinary history. */
export function startMobileNavigation(initialPage: Page): void {
    if (started || typeof window === 'undefined') return;
    started = true;
    current = mobileUrl(initialPage.url);
    owner = pageOwner(initialPage);

    // Inertia intentionally clears remembered state on a full reload.
    try {
        const saved = JSON.parse(window.sessionStorage.getItem(stateKey) ?? 'null');
        if (!initialPage.clearHistory && saved?.current === current && saved?.owner === owner && saved?.length === window.history.length) {
            previous = mobileUrl(saved.previous);
        }
    } catch {
        // A direct link without saved history falls back to the mobile home.
    }

    const restore = (page: Page) => {
        const state = page.rememberedState?.[stateKey] as NavigationState | undefined;
        if (state && state.owner === owner) previous = mobileUrl(state.previous);
        else if (restoring) previous = null;
    };
    restore(initialPage);

    // Register before Inertia initializes: the browser can flush its async
    // history restoration between listeners on the same popstate event.
    window.addEventListener(
        'popstate',
        () => {
            restoring = true;
        },
        { capture: true },
    );
    router.on('navigate', ({ detail: { page } }) => {
        const next = mobileUrl(page.url);
        const nextOwner = pageOwner(page);
        if (!next || nextOwner !== owner || page.clearHistory) {
            previous = null;
        } else if (restoring || next === current) {
            restore(page);
        } else {
            previous = current;
        }
        current = next;
        owner = nextOwner;
        restoring = false;
        pending = false;
        remember();
    });
    // Inertia does not emit "navigate" for replace visits (filters and redirects).
    router.on('success', ({ detail: { page } }) => {
        const nextOwner = pageOwner(page);
        if (nextOwner !== owner || page.clearHistory) previous = null;
        current = mobileUrl(page.url);
        if (!current) previous = null;
        owner = nextOwner;
        pending = false;
        remember();
    });
    router.on('finish', () => {
        pending = false;
    });
    window.addEventListener(backEvent, (event) => {
        if (!mobileUrl(window.location.href)) return;
        if (requestMobileBack()) event.preventDefault();
    });
}

/** Return through mobile history; direct links fall back without adding a loop. */
export function mobileGoBack(fallback = home): boolean {
    if (typeof window === 'undefined') return false;
    const url = mobileUrl(window.location.href);
    if (!url) return false;
    if (pending) return true;
    if (previous && window.history.length > 1) {
        pending = true;
        window.history.back();
        return true;
    }
    const destination = mobileUrl(fallback) ?? home;
    if (destination === url || (destination === home && window.location.pathname === home)) return false;
    router.visit(destination, { replace: true });
    return true;
}

export function requestMobileBack(): boolean {
    for (const handler of [...handlers].reverse()) {
        if (handler()) return true;
    }
    return mobileGoBack();
}

/** Let an active screen handle its own steps before leaving the page. */
export function registerMobileBackHandler(handler: BackHandler): () => void {
    handlers.push(handler);
    return () => {
        const index = handlers.indexOf(handler);
        if (index !== -1) handlers.splice(index, 1);
    };
}
