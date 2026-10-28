import type { Page } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { createAppUpdateRealtime } from './app-update-realtime';
import { createMobileRealtime } from './mobile-realtime';

// Refresh every server-provided mobile property, including future screens.
// These properties belong to the active interaction rather than live data.
const preservedProps = new Set(['errors', 'flash', 'filters', 'quote', 'ziggy']);
const refreshHeader = 'X-Mobile-Refresh';
let started = false;
let currentPage: Page;
let timer: ReturnType<typeof setTimeout> | undefined;
let cancelRefresh: (() => void) | undefined;
let refreshing = false;
let visits = 0;
let queued = false;
let restoring = false;
let connected = false;
let lastRefresh = 0;
const guards = new Set<() => boolean>();

function refresh(): void {
    queued = false;
    const page = currentPage;
    if (!page.props.auth || !(page.props.auth as { user?: unknown }).user || !page.component?.startsWith('absensi/absensi-mobile/')) return;
    if (document.hidden || navigator.onLine === false) return;
    if (visits || refreshing || [...guards].some((canRefresh) => !canRefresh())) {
        queued = true;
        return;
    }
    // popstate changes location before Inertia finishes restoring its page.
    const target = new URL(page.url, window.location.origin);
    if (target.pathname !== window.location.pathname || target.search !== window.location.search) return;
    const only = Object.keys(page.props).filter((key) => !preservedProps.has(key) && key !== 'locationChallenge');
    refreshing = true;
    lastRefresh = Date.now();
    router.reload({
        only,
        replace: true,
        showProgress: false,
        headers: { [refreshHeader]: '1' },
        onCancelToken: ({ cancel }) => (cancelRefresh = cancel),
        onFinish: () => {
            cancelRefresh = undefined;
            refreshing = false;
            if (queued) refreshMobilePage();
        },
    });
    // A before-visit guard can reject the request without firing finish.
    if (!cancelRefresh) refreshing = false;
}

/** A screen can defer updates while collecting GPS or submitting a verification. */
export function registerMobileRefreshGuard(canRefresh: () => boolean): () => void {
    guards.add(canRefresh);
    return () => {
        guards.delete(canRefresh);
        if (queued) refreshMobilePage();
    };
}

export function resumeMobileRefresh(): void {
    if (queued) refreshMobilePage();
}

export function mobilePollingInterval(): number {
    return connected ? 60000 : 10000;
}

/** Coalesce foreground, push, and history events into one background update. */
export function refreshMobilePage(): void {
    if (!started || timer) return;
    timer = setTimeout(() => {
        timer = undefined;
        refresh();
    }, 50);
}

/** Register before mounting Inertia so back/swipe restoration is detected. */
export function startMobileRefresh(initialPage: Page): void {
    if (started || typeof window === 'undefined') return;
    started = true;
    currentPage = initialPage;
    const realtime = createMobileRealtime(
        () => {
            refreshMobilePage();
            window.dispatchEvent(new Event('rsarsy:mobile-data'));
        },
        (active) => (connected = active),
    );
    const releases = createAppUpdateRealtime(() => window.dispatchEvent(new Event('rsarsy:app-release')));
    realtime.update(initialPage);
    releases.update(initialPage);
    router.on('before', ({ detail: { visit } }) => {
        if (visit.headers[refreshHeader]) return;
        queued = false;
        cancelRefresh?.();
    });
    router.on('start', ({ detail: { visit } }) => {
        if (!visit.headers[refreshHeader]) visits++;
    });
    router.on('finish', ({ detail: { visit } }) => {
        if (!visit.headers[refreshHeader]) visits = Math.max(0, visits - 1);
        if (queued && !visits) refreshMobilePage();
    });
    const update = (page: Page) => {
        currentPage = page;
        realtime.update(page);
        releases.update(page);
        if (restoring) {
            restoring = false;
            refreshMobilePage();
        }
    };
    router.on('navigate', ({ detail: { page } }) => update(page));
    router.on('success', ({ detail: { page } }) => update(page));
    window.addEventListener(
        'popstate',
        () => {
            cancelRefresh?.();
            restoring = true;
        },
        { capture: true },
    );
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            cancelRefresh?.();
            realtime.pause();
            releases.pause();
        } else {
            realtime.resume();
            releases.resume();
            refreshMobilePage();
        }
    });
    window.addEventListener('focus', refreshMobilePage);
    window.addEventListener('online', () => {
        realtime.resume();
        releases.resume();
        refreshMobilePage();
    });
    window.addEventListener('offline', () => {
        cancelRefresh?.();
        realtime.pause();
        releases.pause();
    });
    window.addEventListener('pagehide', () => {
        realtime.pause();
        releases.pause();
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            realtime.resume();
            releases.resume();
            refreshMobilePage();
        }
    });
    setInterval(() => {
        releases.resume();
        if (Date.now() - lastRefresh >= mobilePollingInterval()) refreshMobilePage();
    }, 10000);
}
