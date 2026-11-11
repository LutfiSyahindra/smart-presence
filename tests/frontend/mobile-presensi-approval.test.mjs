import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

async function compile(path) {
    const source = await readFile(new URL(path, import.meta.url), 'utf8');
    return ts.transpileModule(compileScript(parse(source).descriptor, { id: 'presensi-approval-test' }).content, {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText;
}
const pageCode = await compile('../../resources/js/pages/absensi/absensi-mobile/PresensiApprovals.vue');
const reviewCode = await compile('../../resources/js/pages/absensi/absensi-mobile/components/PresensiApprovalReview.vue');

function mount(code, initialProps) {
    const props = vue.reactive(initialProps);
    const visits = [];
    const posts = [];
    const events = [];
    const timers = new Map();
    let timerId = 0;
    let back;
    const exports = {};
    vm.runInNewContext(code, {
        exports,
        setTimeout(callback) { timers.set(++timerId, callback); return timerId; },
        clearTimeout(id) { timers.delete(id); },
        require(name) {
            if (name === 'vue') return { ...vue, onBeforeUnmount() {} };
            if (name === '@/lib/mobile-navigation') return { registerMobileBackHandler(handler) { back = handler; return () => {}; } };
            if (name === '@inertiajs/vue3') return {
                usePage: () => ({ props: { flash: {} } }),
                router: { get: (...args) => visits.push(args) },
                useForm(initial) {
                    let transform = (data) => data;
                    const form = vue.reactive({
                        ...initial, processing: false, errors: {},
                        clearErrors() { form.errors = {}; },
                        setError(key, value) { form.errors[key] = value; },
                        reset() { Object.assign(form, initial); },
                        transform(callback) { transform = callback; return form; },
                        post(url, options) {
                            posts.push({ url, data: transform({ decision: form.decision, note: form.note }), options });
                            form.processing = true;
                        },
                    });
                    return form;
                },
            };
            return {};
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() => exports.default.setup(props, { expose() {}, emit: (...args) => events.push(args) }));
    return { props, state, visits, posts, events, timers, back: () => back(), close: () => scope.stop() };
}
function approvalPage() {
    return mount(pageCode, {
        scope: 'delegation', filters: { search: '', month: '', sort: 'oldest' }, totalRequests: 1,
        requests: { data: [{ id: 1, can_decide: true, status: 'pending' }], total: 1, current_page: 1 },
    });
}

test('search debounce is cancelled by applying filters and scope changes preserve search and period', () => {
    const app = approvalPage();
    try {
        app.state.search.value = ' IGD ';
        app.state.scheduleSearch();
        app.state.scheduleSearch();
        assert.equal(app.timers.size, 1);
        app.state.applyFilters({ month: '2026-11' });
        assert.equal(app.timers.size, 0);
        assert.deepEqual({ ...app.visits[0][1] }, { scope: 'delegation', search: 'IGD', month: '2026-11', sort: 'oldest' });
        app.props.filters.month = '2026-11';
        app.state.changeScope('mine');
        assert.deepEqual({ ...app.visits[1][1] }, { scope: 'mine', search: 'IGD', month: '2026-11', sort: 'newest' });
    } finally { app.close(); }
});

test('detail follows refreshed approval eligibility and mobile back cannot dismiss a pending decision', async () => {
    const app = approvalPage();
    try {
        app.state.openDetail(app.props.requests.data[0]);
        app.props.requests.data = [{ id: 1, can_decide: false, status: 'approved' }];
        await vue.nextTick();
        assert.equal(app.state.selected.value.can_decide, false);
        app.state.deciding.value = true;
        app.state.applyFilters();
        assert.equal(app.visits.length, 0);
        assert.equal(app.back(), true);
        assert.equal(app.state.selected.value.id, 1);
        app.state.deciding.value = false;
        assert.equal(app.back(), true);
        assert.equal(app.state.selected.value, null);
        app.state.openFilter();
        assert.equal(app.back(), true);
        assert.equal(app.state.filterOpen.value, false);
        app.state.loading.value = true;
        app.state.openDetail(app.props.requests.data[0]);
        assert.equal(app.state.selected.value, null);
    } finally { app.close(); }
});

test('rejection requires a reason and submits the trimmed decision once', () => {
    const app = mount(reviewCode, { item: { id: 42, can_decide: true } });
    try {
        app.state.toggleDecision();
        app.state.decision.note = '   ';
        app.state.decide();
        assert.equal(app.posts.length, 0);
        assert.equal(app.state.decision.errors.note, 'Alasan penolakan wajib diisi.');
        app.state.decision.note = ' Lokasi perlu diperiksa. ';
        app.state.decide();
        app.state.decide();
        app.state.toggleDecision();
        assert.equal(app.posts.length, 1);
        assert.equal(app.posts[0].url, '/absensi/mobile/presensi/persetujuan/42/decision');
        assert.deepEqual({ ...app.posts[0].data }, { decision: 'rejected', note: 'Lokasi perlu diperiksa.' });
        assert.equal(app.state.decision.decision, 'rejected');
        assert.equal(app.events.some(([name, value]) => name === 'processing' && value === true), true);
        app.posts[0].options.onSuccess();
        assert.equal(app.events.some(([name]) => name === 'decided'), true);
        assert.equal(app.state.decision.note, '');
    } finally { app.close(); }
});

test('approval permits an optional note and cannot be sent after eligibility is lost', () => {
    const app = mount(reviewCode, { item: { id: 42, can_decide: false } });
    try {
        app.state.decide();
        assert.equal(app.posts.length, 0);
        app.props.item.can_decide = true;
        app.state.decide();
        assert.equal(app.posts.length, 1);
        assert.equal(app.posts[0].data.decision, 'approved');
    } finally { app.close(); }
});
