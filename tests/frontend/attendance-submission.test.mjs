import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const component = await readFile(new URL('../../resources/js/pages/absensi/absensi-mobile/AttendanceSubmission.vue', import.meta.url), 'utf8');
const source = component.split('<script setup lang="ts">')[1].split('</script>')[0];
const compiled = ts.transpileModule(`${source}\nexport { refresh, resume, submission, connectionMessage };`, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;

function runtime(status = 'pending') {
    const exports = {};
    const timers = new Map();
    const listeners = new Map();
    const calls = [];
    const responses = [];
    let unmount;
    const document = {
        hidden: false,
        addEventListener: (key, value) => listeners.set(key, value),
        removeEventListener: (key) => listeners.delete(key),
    };
    const context = {
        exports,
        defineProps: () => ({
            submission: {
                id: 'private-id',
                action: 'clock-in',
                status,
                time: '06:58',
                received_at: '2026-10-26T06:58:00+07:00',
                status_url: '/absensi/mobile/presensi/proses/private-id',
            },
        }),
        require: (name) =>
            name === 'vue'
                ? {
                      ref: (value) => ({ value }),
                      computed: (getter) => ({
                          get value() {
                              return getter();
                          },
                      }),
                      watch: () => {},
                      onMounted: () => {},
                      onUnmounted: (callback) => {
                          unmount = callback;
                      },
                  }
                : {},
        document,
        window: { addEventListener: (key, value) => listeners.set(key, value), removeEventListener: (key) => listeners.delete(key) },
        AbortController,
        Intl,
        Date,
        Error,
        setTimeout: (callback, delay) => {
            const id = Symbol();
            timers.set(id, { callback, delay });
            return id;
        },
        clearTimeout: (id) => timers.delete(id),
        fetch: async (url, options) => {
            calls.push({ url, options });
            const response = responses.shift();
            if (response instanceof Error) throw response;
            return response ?? { ok: true, json: async () => ({ data: { ...exports.submission.value, status: 'processing' } }) };
        },
    };
    vm.runInNewContext(compiled, context);
    return { ...exports, calls, responses, timers, document, listeners, unmount: () => unmount() };
}

test('status polling uses authenticated JSON, disables caching, and preserves the received time', async () => {
    const app = runtime();
    await app.refresh();
    assert.equal(app.calls[0].url, '/absensi/mobile/presensi/proses/private-id');
    assert.equal(app.calls[0].options.credentials, 'same-origin');
    assert.equal(app.calls[0].options.cache, 'no-store');
    assert.equal(app.submission.value.status, 'processing');
    assert.equal(app.submission.value.time, '06:58');
    assert.equal([...app.timers.values()][0].delay, 3000);
});

for (const status of ['succeeded', 'failed']) {
    test(`polling stops on ${status} and does not turn acceptance into success`, async () => {
        const app = runtime();
        app.responses.push({ ok: true, json: async () => ({ data: { ...app.submission.value, status } }) });
        await app.refresh();
        assert.equal(app.submission.value.status, status);
        assert.equal(app.timers.size, 0);
    });
}

test('a connection failure keeps the persisted submission and retries with a longer interval', async () => {
    const app = runtime();
    app.responses.push(new Error('offline'));
    await app.refresh();
    assert.equal(app.submission.value.status, 'pending');
    assert.match(app.connectionMessage.value, /tersimpan/);
    assert.equal([...app.timers.values()][0].delay, 10000);
});

test('a hidden screen pauses polling and unmount cancels the outstanding request', async () => {
    const app = runtime();
    await app.refresh();
    app.document.hidden = true;
    app.resume();
    assert.equal(app.timers.size, 0);
    app.unmount();
    assert.equal(app.calls[0].options.signal.aborted, true);
    await app.refresh();
    assert.equal(app.calls.length, 1);
});
