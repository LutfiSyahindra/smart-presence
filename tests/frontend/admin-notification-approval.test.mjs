import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

const pageSource = await readFile(new URL('../../resources/js/pages/absensi/admin-web/notifikasi/Index.vue', import.meta.url), 'utf8');
const compiledPage = compileScript(parse(pageSource).descriptor, { id: 'approval-test' }).content;
const countsSource = await readFile(new URL('../../resources/js/composables/useAdminNotificationCounts.ts', import.meta.url), 'utf8');
const compile = (source) => ts.transpileModule(source, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

function approvalPage(category = 'butuh-approval', rows = [{ id: 1, can_decide: true }, { id: 2, can_decide: true }, { id: 3, can_decide: false }]) {
    const calls = [];
    const visits = [];
    const props = vue.reactive({
        category,
        requests: { data: rows },
        filters: { search: '', request_type: 'all' },
        overview: { inbox: 2, approved: 0 },
        requestTypeCounts: { presensi: 0, cuti: 1, lembur: 1, 'perubahan-shift': 0 },
    });
    const exports = {};
    vm.runInNewContext(compile(compiledPage), {
        exports,
        setTimeout: () => 1,
        clearTimeout() {},
        require(name) {
            if (name === 'vue') return { ...vue, onBeforeUnmount() {} };
            if (name === '@inertiajs/vue3') return {
                usePage: () => ({ props: { portal: { can_access_admin: true } } }),
                router: { get(url, data, options) { visits.push({ url, data, options }); } },
                useForm(initial) {
                    const form = vue.reactive({
                        ...initial,
                        processing: false,
                        errors: {},
                        reset() { form.request_ids = []; form.lembur_request_ids = []; form.perubahan_shift_request_ids = []; form.presensi_request_ids = []; },
                        clearErrors() { form.errors = {}; },
                        post(url, options) {
                            calls.push({ url, ids: [...form.request_ids], lemburIds: [...form.lembur_request_ids], shiftIds: [...form.perubahan_shift_request_ids], presensiIds: [...form.presensi_request_ids], options });
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

test('request sections show separate counts and keep the selected type when searching', () => {
    const app = approvalPage();
    try {
        assert.deepEqual(Array.from(app.state.requestTypes.value, (item) => [item.key, item.count]), [
            ['all', 2], ['presensi', 0], ['cuti', 1], ['lembur', 1], ['perubahan-shift', 0],
        ]);
        app.props.filters.request_type = 'lembur';
        app.state.search.value = '  Nadia  ';
        app.state.applySearch();
        assert.equal(app.visits[0].url, '/absensi/admin-web/notifikasi/butuh-approval');
        assert.equal(app.visits[0].data.request_type, 'lembur');
        assert.equal(app.visits[0].data.search, 'Nadia');
        assert.equal(app.visits[0].data.page, undefined);
        assert.equal(app.state.sectionTitle.value, 'Pengajuan Lembur');
        app.state.visitRequestType('perubahan-shift');
        assert.equal(app.visits[1].data.request_type, 'perubahan-shift');
        assert.equal(app.visits[1].data.search, 'Nadia');
        app.state.bulkForm.processing = true;
        app.state.visitRequestType('cuti');
        assert.equal(app.visits.length, 2);
    } finally { app.close(); }
});

test('switching request sections clears selections, open reviews, and stale errors', async () => {
    const app = approvalPage();
    try {
        app.state.toggleAll({ target: { checked: true } });
        app.state.bulkReviewOpen.value = true;
        app.state.selected.value = { id: 1 };
        app.state.bulkForm.errors = { decision: 'Pengajuan sudah diproses.' };
        app.props.filters.request_type = 'perubahan-shift';
        app.props.requests = { data: [] };
        await vue.nextTick();
        assert.equal(app.state.bulkForm.request_ids.length, 0);
        assert.equal(app.state.bulkForm.lembur_request_ids.length, 0);
        assert.equal(app.state.bulkReviewOpen.value, false);
        assert.equal(app.state.selected.value, null);
        assert.equal(app.state.bulkError.value, undefined);
        assert.equal(app.state.activeRequestSection.value.name, 'pengajuan perubahan shift');
        app.state.approveSelected();
        assert.equal(app.calls.length, 0);
    } finally { app.close(); }
});

test('select all includes only requests available for approval on the current page', () => {
    const app = approvalPage();
    try {
        assert.equal(app.state.selectedRequests.value.length, 0);
        app.state.approveSelected();
        assert.equal(app.calls.length, 0);
        app.state.toggleAll({ target: { checked: true } });
        assert.deepEqual([...app.state.bulkForm.request_ids], [1, 2]);
        assert.equal(app.state.allSelected.value, true);
        assert.equal(app.state.someSelected.value, false);
        app.state.bulkForm.request_ids = [2];
        assert.equal(app.state.allSelected.value, false);
        assert.equal(app.state.someSelected.value, true);
        app.state.toggleAll({ target: { checked: false } });
        assert.equal(app.state.selectedRequests.value.length, 0);
    } finally { app.close(); }
});

test('bulk submission sends selected IDs once and clears selection after success', () => {
    const app = approvalPage();
    try {
        app.state.bulkForm.request_ids = [2];
        app.state.bulkReviewOpen.value = true;
        app.state.approveSelected();
        app.state.approveSelected();
        assert.equal(app.calls.length, 1);
        assert.equal(app.calls[0].url, '/absensi/admin-web/notifikasi/approve-many');
        assert.deepEqual(app.calls[0].ids, [2]);
        app.state.closeBulkReview();
        assert.equal(app.state.bulkReviewOpen.value, true);
        app.calls[0].options.onSuccess();
        assert.equal(app.state.bulkReviewOpen.value, false);
        assert.equal(app.state.selectedRequests.value.length, 0);
    } finally { app.close(); }
});

test('cuti and lembur with the same ID are selected and submitted independently', () => {
    const app = approvalPage('butuh-approval', [
        { id: 1, request_type: 'cuti', can_decide: true },
        { id: 1, request_type: 'lembur', can_decide: true },
        { id: 2, request_type: 'lembur', can_decide: false },
    ]);
    try {
        app.state.bulkForm.lembur_request_ids = [1];
        assert.equal(app.state.selectedRequests.value.length, 1);
        assert.equal(app.state.selectedRequests.value[0].request_type, 'lembur');
        assert.equal(app.state.someSelected.value, true);
        app.state.toggleAll({ target: { checked: true } });
        assert.deepEqual([...app.state.bulkForm.request_ids], [1]);
        assert.deepEqual([...app.state.bulkForm.lembur_request_ids], [1]);
        assert.equal(app.state.allSelected.value, true);
        app.state.approveSelected();
        assert.deepEqual(app.calls[0].ids, [1]);
        assert.deepEqual(app.calls[0].lemburIds, [1]);
        app.calls[0].options.onSuccess();
        assert.equal(app.state.selectedRequests.value.length, 0);
        assert.equal(app.state.bulkForm.lembur_request_ids.length, 0);
    } finally { app.close(); }
});

test('shift, cuti, and lembur with identical IDs keep separate selections and clear them after approval', () => {
    const app = approvalPage('butuh-approval', [
        { id: 1, request_type: 'cuti', can_decide: true },
        { id: 1, request_type: 'lembur', can_decide: true },
        { id: 1, request_type: 'perubahan-shift', can_decide: true },
        { id: 2, request_type: 'perubahan-shift', can_decide: false },
    ]);
    try {
        app.state.bulkForm.perubahan_shift_request_ids = [1];
        assert.equal(app.state.selectedRequests.value.length, 1);
        assert.equal(app.state.selectedRequests.value[0].request_type, 'perubahan-shift');
        app.state.toggleAll({ target: { checked: true } });
        assert.equal(app.state.selectedRequests.value.length, 3);
        app.state.approveSelected();
        assert.deepEqual(app.calls[0].ids, [1]);
        assert.deepEqual(app.calls[0].lemburIds, [1]);
        assert.deepEqual(app.calls[0].shiftIds, [1]);
        app.calls[0].options.onSuccess();
        assert.equal(app.state.selectedRequests.value.length, 0);
        assert.equal(app.state.bulkForm.perubahan_shift_request_ids.length, 0);
    } finally { app.close(); }
});

test('pagination, filtering, and category changes clear previous selections', async () => {
    const app = approvalPage();
    try {
        app.state.toggleAll({ target: { checked: true } });
        app.state.bulkReviewOpen.value = true;
        app.props.requests = { data: [{ id: 4, can_decide: true }] };
        await vue.nextTick();
        assert.equal(app.state.bulkForm.request_ids.length, 0);
        assert.equal(app.state.bulkReviewOpen.value, false);
        app.state.toggleAll({ target: { checked: true } });
        assert.deepEqual([...app.state.bulkForm.request_ids], [4]);
        app.props.category = 'disetujui';
        await vue.nextTick();
        assert.equal(app.state.bulkForm.request_ids.length, 0);
        assert.equal(app.state.selectableRequests.value.length, 0);
        app.state.approveSelected();
        assert.equal(app.calls.length, 0);
    } finally { app.close(); }
});

test('failed bulk submission closes the review and leaves the error available', () => {
    const app = approvalPage();
    try {
        app.state.bulkForm.request_ids = [1];
        app.state.bulkReviewOpen.value = true;
        app.state.approveSelected();
        app.state.bulkForm.errors = { decision: 'Pengajuan ini sudah selesai diproses.' };
        app.calls[0].options.onError();
        assert.equal(app.state.bulkReviewOpen.value, false);
        assert.equal(app.state.bulkError.value, 'Pengajuan ini sudah selesai diproses.');
    } finally { app.close(); }
});

test('sidebar counts share live feed updates and reset from page props on navigation', async () => {
    const page = vue.reactive({ props: {
        auth: { user: { id: 1 } },
        adminNotificationCounts: { unread_count: 4, pending_approval_count: 2 },
    } });
    const provided = new Map();
    const exports = {};
    vm.runInNewContext(compile(countsSource), {
        exports,
        require(name) {
            if (name === '@inertiajs/vue3') return { usePage: () => page };
            if (name === 'vue') return {
                ...vue,
                provide: (key, value) => provided.set(key, value),
                inject: (key) => provided.get(key),
            };
            throw new Error(`Unexpected import: ${name}`);
        },
    });
    const scope = vue.effectScope();
    try {
        scope.run(() => exports.provideAdminNotificationCounts());
        const sidebar = exports.useAdminNotificationCounts();
        const bell = exports.useAdminNotificationCounts();
        assert.equal(sidebar.counts.value.unread_count, 4);
        assert.equal(sidebar.counts.value.pending_approval_count, 2);
        bell.updateCounts({ unread_count: 7, pending_approval_count: 3 });
        assert.equal(sidebar.counts.value.unread_count, 7);
        assert.equal(sidebar.counts.value.pending_approval_count, 3);
        page.props.adminNotificationCounts = { unread_count: 0, pending_approval_count: 1 };
        await vue.nextTick();
        assert.equal(sidebar.counts.value.unread_count, 0);
        assert.equal(sidebar.counts.value.pending_approval_count, 1);
        page.props.auth.user = { id: 2 };
        page.props.adminNotificationCounts = null;
        await vue.nextTick();
        assert.equal(sidebar.counts.value.unread_count, 0);
        assert.equal(sidebar.counts.value.pending_approval_count, 0);
    } finally { scope.stop(); }
});

test('presensi and cuti with identical IDs remain independently selected', () => {
    const app = approvalPage('butuh-approval', [{ id: 1, request_type: 'cuti', can_decide: true }, { id: 1, request_type: 'presensi', can_decide: true }]);
    try {
        app.state.toggleAll({ target: { checked: true } });
        assert.equal(app.state.selectedRequests.value.length, 2);
        app.state.approveSelected();
        assert.deepEqual(app.calls[0].ids, [1]);
        assert.deepEqual(app.calls[0].presensiIds, [1]);
        app.calls[0].options.onSuccess();
        assert.equal(app.state.bulkForm.presensi_request_ids.length, 0);
    } finally { app.close(); }
});
