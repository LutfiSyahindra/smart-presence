import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

const source = await readFile(new URL('../../resources/js/pages/absensi/absensi-mobile/CutiApprovals.vue', import.meta.url), 'utf8');
const script = compileScript(parse(source).descriptor, { id: 'mobile-cuti-approval-test' }).content;
const compiled = ts.transpileModule(script, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

function approvalPage() {
    const calls = [];
    const visits = [];
    const props = vue.reactive({
        filters: { search: '', month: '', sort: 'oldest' },
        totalPending: 2,
        requests: { data: [{ id: 1, can_decide: true }, { id: 2, can_decide: true }, { id: 3, can_decide: false }], current_page: 1 },
    });
    const exports = {};
    vm.runInNewContext(compiled, {
        exports,
        setTimeout: () => 1,
        clearTimeout() {},
        require(name) {
            if (name === 'vue') return { ...vue, onBeforeUnmount() {} };
            if (name === '@inertiajs/vue3') return {
                usePage: () => ({ props: { flash: {} } }),
                router: { get: (...args) => visits.push(args) },
                useForm(initial) {
                    const form = vue.reactive({
                        ...initial,
                        processing: false,
                        errors: {},
                        reset() {
                            for (const [key, value] of Object.entries(initial)) form[key] = Array.isArray(value) ? [] : value;
                        },
                        clearErrors() { form.errors = {}; },
                        post(url, options) {
                            calls.push({ url, ids: [...(form.request_ids ?? [])], options });
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
    const state = scope.run(() => exports.default.setup(props, { expose() {} }));
    return { props, state, calls, visits, close: () => scope.stop() };
}

test('individual marking and select all include only requests awaiting this approver', () => {
    const app = approvalPage();
    try {
        app.state.toggleSelection(1);
        app.state.toggleSelection(3);
        app.state.toggleSelection(999);
        assert.deepEqual([...app.state.selectedIds.value], [1]);
        assert.equal(app.state.someSelected.value, true);
        assert.equal(app.state.allSelected.value, false);
        app.state.selectAll();
        assert.deepEqual([...app.state.selectedIds.value], [1, 2]);
        assert.equal(app.state.allSelected.value, true);
        assert.equal(app.state.someSelected.value, false);
        app.state.selectAll();
        assert.equal(app.state.selectedRequests.value.length, 0);
    } finally { app.close(); }
});

test('reviewing a detail preserves marked requests and approval requires confirmation', () => {
    const app = approvalPage();
    try {
        app.state.reviewSelection();
        assert.equal(app.state.confirmingBulk.value, false);
        app.state.toggleSelection(2);
        app.state.openDetail(app.props.requests.data[0]);
        assert.equal(app.state.selected.value.id, 1);
        assert.deepEqual([...app.state.selectedIds.value], [2]);
        app.state.approveSelected();
        assert.equal(app.calls.length, 0);
        app.state.reviewSelection();
        assert.equal(app.state.confirmingBulk.value, true);
    } finally { app.close(); }
});

test('bulk approval submits marked IDs once, freezes selection, and clears after success', () => {
    const app = approvalPage();
    try {
        app.state.selectAll();
        app.state.reviewSelection();
        app.state.approveSelected();
        app.state.approveSelected();
        app.state.toggleSelection(1);
        app.state.selectAll();
        assert.equal(app.calls.length, 1);
        assert.equal(app.calls[0].url, '/absensi/mobile/notifikasi/cuti/approve-many');
        assert.deepEqual(app.calls[0].ids, [1, 2]);
        assert.deepEqual([...app.state.selectedIds.value], [1, 2]);
        app.calls[0].options.onSuccess();
        assert.equal(app.state.confirmingBulk.value, false);
        assert.equal(app.state.selectedRequests.value.length, 0);
        assert.equal(app.state.bulk.request_ids.length, 0);
    } finally { app.close(); }
});

test('pagination, refreshed requests, and filters clear marks and close confirmation', async () => {
    const app = approvalPage();
    try {
        app.state.selectAll();
        app.state.reviewSelection();
        app.props.requests = { data: [{ id: 4, can_decide: true }], current_page: 2 };
        await vue.nextTick();
        assert.equal(app.state.selectedIds.value.length, 0);
        assert.equal(app.state.confirmingBulk.value, false);
        app.state.selectAll();
        assert.deepEqual([...app.state.selectedIds.value], [4]);
        app.props.filters = { search: 'Nadia', month: '', sort: 'oldest' };
        await vue.nextTick();
        assert.equal(app.state.selectedIds.value.length, 0);
        assert.equal(app.state.search.value, 'Nadia');
        app.state.selectAll();
        app.state.reviewSelection();
        app.state.applyFilters({ month: '2026-12' });
        assert.equal(app.state.confirmingBulk.value, false);
        assert.equal(app.state.selectedIds.value.length, 0);
        assert.equal(app.visits[0][1].month, '2026-12');
        app.state.approveSelected();
        assert.equal(app.calls.length, 0);
    } finally { app.close(); }
});

test('requests that lose approval eligibility cannot be included in the submitted batch', () => {
    const app = approvalPage();
    try {
        app.state.selectAll();
        app.props.requests.data[0].can_decide = false;
        app.state.reviewSelection();
        app.state.approveSelected();
        assert.deepEqual(app.calls[0].ids, [2]);
    } finally { app.close(); }
});

test('server errors stay visible after the refreshed queue clears the previous selection', async () => {
    const app = approvalPage();
    try {
        app.state.selectAll();
        app.state.reviewSelection();
        app.state.approveSelected();
        app.state.bulk.processing = false;
        app.state.bulk.errors = { decision: 'Pengajuan ini sudah selesai diproses.' };
        app.calls[0].options.onError();
        app.props.requests = { data: [{ id: 2, can_decide: true }], current_page: 1 };
        await vue.nextTick();
        assert.equal(app.state.confirmingBulk.value, false);
        assert.equal(app.state.selectedIds.value.length, 0);
        assert.equal(app.state.bulkErrors.value[0], 'Pengajuan ini sudah selesai diproses.');
        app.state.toggleSelection(2);
        assert.equal(app.state.bulkErrors.value.length, 0);
        app.state.reviewSelection();
        app.state.approveSelected();
        assert.deepEqual(app.calls[1].ids, [2]);
    } finally { app.close(); }
});

test('loading another result page prevents marking and submitting old requests', () => {
    const app = approvalPage();
    try {
        app.state.selectAll();
        app.state.reviewSelection();
        app.state.loading.value = true;
        app.state.toggleSelection(1);
        app.state.selectAll();
        app.state.approveSelected();
        assert.deepEqual([...app.state.selectedIds.value], [1, 2]);
        assert.equal(app.calls.length, 0);
    } finally { app.close(); }
});
