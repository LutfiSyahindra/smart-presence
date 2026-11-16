import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';
import { computed, ref } from 'vue';

const source = await readFile(new URL('../../resources/js/pages/absensi/admin-web/lembur/LemburDetailModal.vue', import.meta.url), 'utf8');
const script = source.match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1];
const { outputText } = ts.transpileModule(`${script}\nglobalThis.modal = { detail, loading, error, load, close, openCancel, cancelOpen, cancelForm, cancel, decisionForm, decide, openSource };`, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
});
const payload = (overrides = {}) => ({
    lembur: { id: 17, status: 'pending', request_kind: 'usage', sources: [{ id: 4, minutes: 45 }] },
    balance: { earned_minutes: 180, reserved_minutes: 45, used_minutes: 0, available_minutes: 135 },
    can: { cancel: true, decide: true },
    allowApprovedCancellation: false,
    ...overrides,
});
const response = (data = payload(), status = 200) => ({
    ok: status === 200, status, redirected: false,
    headers: { get: () => 'application/json' },
    json: async () => data,
});

function runtime(initialDetail) {
    const requests = [];
    const posts = [];
    const events = [];
    let unmount;
    let responder = async () => response();
    const context = {
        exports: {}, AbortController,
        defineProps: () => ({ requestId: 17, initialDetail }),
        defineEmits: () => (...args) => events.push(args),
        fetch: (url, options) => {
            requests.push({ url, options });
            return responder(url, options);
        },
        require(name) {
            if (name === 'vue') return { computed, ref, onMounted() {}, onBeforeUnmount(fn) { unmount = fn; } };
            if (name === '@inertiajs/vue3') return {
                useForm(data) {
                    const form = ref({
                        ...data, errors: {}, processing: false,
                        reset() { Object.assign(this, data); },
                        clearErrors() { this.errors = {}; },
                        post(url, options) {
                            posts.push({ url, options, data: { decision: this.decision, note: this.note } });
                            this.processing = true;
                        },
                    }).value;
                    return form;
                },
            };
            return {};
        },
    };
    vm.createContext(context);
    vm.runInContext(outputText, context);
    return { ...context.modal, requests, posts, events, unmount: () => unmount(), respond: (fn) => { responder = fn; } };
}

test('details load with the current session and JSON without navigating away from the table', async () => {
    const app = runtime();
    await app.load();
    assert.equal(app.requests[0].url, '/absensi/admin-web/lembur/17');
    assert.equal(app.requests[0].options.headers.Accept, 'application/json');
    assert.equal(app.requests[0].options.credentials, 'same-origin');
    assert.equal(app.requests[0].options.cache, 'no-store');
    assert.equal(app.detail.value.lembur.id, 17);
    assert.equal(app.detail.value.balance.available_minutes, 135);
    assert.equal(app.loading.value, false);
    app.openSource(4);
    assert.deepEqual(app.events[0], ['open', 4]);
});

test('failed requests show an error and can be retried; closing aborts unfinished loading', async () => {
    const app = runtime();
    app.respond(async () => response(null, 404));
    await app.load();
    assert.match(app.error.value, /tidak ditemukan/);
    assert.equal(app.detail.value, null);
    app.respond(async () => response());
    await app.load();
    assert.equal(app.error.value, '');
    assert.equal(app.detail.value.lembur.id, 17);
    app.respond((_url, options) => new Promise((_resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('aborted')))));
    const pending = app.load();
    app.unmount();
    await pending;
    assert.equal(app.requests.at(-1).options.signal.aborted, true);
    assert.equal(app.error.value, '');
});

test('an older response cannot overwrite a newer detail refresh', async () => {
    const app = runtime();
    let completeOld;
    app.respond(() => new Promise((resolve) => { completeOld = resolve; }));
    const old = app.load();
    app.respond(async () => response(payload({ lembur: { id: 17, status: 'approved' } })));
    await app.load();
    completeOld(response());
    await old;
    assert.equal(app.detail.value.lembur.status, 'approved');
});

test('actions respect permissions and block duplicate submissions or closing while processing', async () => {
    const viewer = runtime(payload({ can: { cancel: false, decide: false } }));
    viewer.openCancel();
    viewer.cancel();
    viewer.decide('approved');
    assert.equal(viewer.cancelOpen.value, false);
    assert.equal(viewer.posts.length, 0);
    const app = runtime(payload());
    app.decisionForm.note = 'Diperiksa';
    app.decide('approved');
    app.decide('rejected');
    app.openCancel();
    app.close();
    app.openSource(4);
    assert.equal(app.posts.length, 1);
    assert.equal(app.posts[0].url, '/absensi/admin-web/lembur/17/decision');
    assert.equal(app.posts[0].data.note, 'Diperiksa');
    assert.equal(app.posts[0].options.preserveScroll, true);
    assert.equal(app.events.length, 0);
    app.decisionForm.processing = false;
    app.respond(async () => response(payload({ lembur: { id: 17, status: 'approved' } })));
    app.posts[0].options.onSuccess();
    await new Promise(setImmediate);
    assert.equal(app.detail.value.lembur.status, 'approved');
});

test('cancellation stays in the same modal and refreshes its status after success', async () => {
    const app = runtime(payload());
    app.openCancel();
    app.close();
    assert.equal(app.cancelOpen.value, false);
    assert.equal(app.events.length, 0);
    app.openCancel();
    app.cancelForm.note = 'Koreksi jadwal';
    app.cancel();
    app.cancel();
    app.close();
    assert.equal(app.posts.length, 1);
    assert.equal(app.posts[0].url, '/absensi/admin-web/lembur/17/cancel');
    assert.equal(app.posts[0].data.note, 'Koreksi jadwal');
    assert.equal(app.events.length, 0);
    app.cancelForm.processing = false;
    app.respond(async () => response(payload({ lembur: { id: 17, status: 'cancelled' }, can: { cancel: false, decide: false } })));
    app.posts[0].options.onSuccess();
    await new Promise(setImmediate);
    assert.equal(app.cancelOpen.value, false);
    assert.equal(app.cancelForm.note, '');
    assert.equal(app.detail.value.lembur.status, 'cancelled');
    app.close();
    assert.deepEqual(app.events, [['close']]);
});
