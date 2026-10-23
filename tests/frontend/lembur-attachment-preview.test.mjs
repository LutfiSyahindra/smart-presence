import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';
import ts from 'typescript';

const canvasModule = await import('@napi-rs/canvas').catch(() => null);
const source = await readFile(
    new URL('../../resources/js/pages/absensi/absensi-mobile/components/LemburAttachmentPreview.vue', import.meta.url),
    'utf8',
);
const script = source.match(/<script setup lang="ts">([\s\S]*?)<\/script>/)[1];
const { outputText } = ts.transpileModule(
    `${script}\nglobalThis.preview = { load, renderPage, page, pdf, canvas, loading, rendering, text, error, imageUrl };`,
    { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } },
);

function runtime(
    file,
    fetch = async () => {
        throw new Error('Unexpected network request');
    },
) {
    let mounted;
    let cleanup;
    const revoked = [];
    const urls = [];
    const context = {
        exports: {},
        defineProps: () => ({ file }),
        defineEmits: () => () => {},
        AbortController,
        Uint8Array,
        fetch,
        URL: {
            createObjectURL(blob) {
                urls.push(blob);
                return `blob:preview-${urls.length}`;
            },
            revokeObjectURL: (url) => revoked.push(url),
        },
        require(name) {
            if (name === 'vue')
                return {
                    ref: (value) => ({ value }),
                    shallowRef: (value) => ({ value }),
                    computed: (get) => ({
                        get value() {
                            return get();
                        },
                    }),
                    nextTick: async () => {},
                    onMounted: (callback) => {
                        mounted = callback;
                    },
                    onBeforeUnmount: (callback) => {
                        cleanup = callback;
                    },
                };
            if (name === 'pdfjs-dist/legacy/build/pdf.mjs') return pdfjs;
            if (name.endsWith('pdf.worker.min.mjs?url'))
                return {
                    default: new URL('../../node_modules/pdfjs-dist/legacy/build/pdf.worker.min.mjs', import.meta.url).href,
                };
            throw new Error(`Unexpected import: ${name}`);
        },
    };
    vm.runInNewContext(outputText, context);
    if (canvasModule) context.preview.canvas.value = canvasModule.createCanvas(1, 1);
    return { ...context.preview, mount: () => mounted(), close: () => cleanup(), urls, revoked };
}

function twoPagePdf() {
    const streams = ['0 0 1 rg 20 20 120 120 re f\n', '1 0 0 rg 20 20 120 120 re f\n'];
    const objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> /Contents 4 0 R >>',
        `<< /Length ${streams[0].length} >>\nstream\n${streams[0]}endstream`,
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Resources << >> /Contents 6 0 R >>',
        `<< /Length ${streams[1].length} >>\nstream\n${streams[1]}endstream`,
    ];
    let content = '%PDF-1.4\n';
    const offsets = [0];
    for (const [index, object] of objects.entries()) {
        offsets.push(content.length);
        content += `${index + 1} 0 obj\n${object}\nendobj\n`;
    }
    const xref = content.length;
    content += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
    content += offsets
        .slice(1)
        .map((offset) => `${String(offset).padStart(10, '0')} 00000 n \n`)
        .join('');
    content += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`;
    return new File([content], 'surat.pdf', { type: 'application/pdf' });
}

test('selected text files preview locally without uploading and preserve literal markup', async () => {
    const preview = runtime(new File(['<script>alert("lampiran")</script>'], 'catatan.TXT', { type: 'text/plain' }));
    await preview.mount();
    assert.equal(preview.text.value, '<script>alert("lampiran")</script>');
    assert.equal(preview.loading.value, false);
    assert.equal(preview.error.value, '');
    preview.close();
});

test('saved attachments fetch the authenticated preview endpoint without visiting the download URL', async () => {
    const requests = [];
    const preview = runtime({ name: 'rekap.csv', url: '/lampiran/0', preview_url: '/lampiran/0?preview=1' }, async (url, options) => {
        requests.push({ url, options });
        return new Response('Nama,Jam\nNadia,2', { headers: { 'Content-Type': 'text/plain' } });
    });
    await preview.mount();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/lampiran/0?preview=1');
    assert.equal(requests[0].options.credentials, 'same-origin');
    assert.equal(preview.text.value, 'Nama,Jam\nNadia,2');
    preview.close();
});

test('denied access and expired sessions show a load error instead of rendering the server response', async () => {
    for (const response of [
        new Response('Forbidden', { status: 403 }),
        new Response('<html>Login</html>', { headers: { 'Content-Type': 'text/html' } }),
    ]) {
        const preview = runtime({ name: 'catatan.txt', url: '/download', preview_url: '/preview' }, async () => response);
        await preview.mount();
        assert.ok(preview.error.value);
        assert.equal(preview.text.value, '');
        assert.equal(preview.loading.value, false);
        preview.close();
    }
});

test('closing a preview aborts its pending request without displaying an error', async () => {
    let signal;
    const preview = runtime({ name: 'catatan.txt', url: '/download', preview_url: '/preview' }, async (_, options) => {
        signal = options.signal;
        return new Promise((_, reject) => signal.addEventListener('abort', () => reject(new Error('Aborted'))));
    });
    const loading = preview.mount();
    await new Promise((resolve) => setImmediate(resolve));
    preview.close();
    await loading;
    assert.equal(signal.aborted, true);
    assert.equal(preview.error.value, '');
});

test('image retries and closing release the previous local blob URLs', async () => {
    const preview = runtime(new File(['image bytes'], 'foto.png', { type: 'image/png' }));
    await preview.mount();
    assert.equal(preview.imageUrl.value, 'blob:preview-1');
    await preview.load();
    assert.equal(preview.imageUrl.value, 'blob:preview-2');
    assert.deepEqual(preview.revoked, ['blob:preview-1']);
    preview.close();
    assert.deepEqual(preview.revoked, ['blob:preview-1', 'blob:preview-2']);
});

test('unsupported Office files do not fetch or trigger a download automatically', async () => {
    const preview = runtime({ name: 'surat.docx', url: '/download', preview_url: null });
    await preview.mount();
    assert.equal(preview.loading.value, false);
    assert.equal(preview.error.value, '');
    assert.equal(preview.urls.length, 0);
    preview.close();
});

test('PDF pages render with PDF.js and navigation changes the rendered page', { skip: !canvasModule }, async () => {
    const preview = runtime(twoPagePdf());
    try {
        await preview.mount();
        assert.equal(preview.error.value, '');
        assert.equal(preview.pdf.value.numPages, 2);
        assert.equal(preview.page.value, 1);
        const context = preview.canvas.value.getContext('2d');
        assert.deepEqual([...context.getImageData(45, 150, 1, 1).data], [0, 0, 255, 255]);
        await preview.renderPage(2);
        assert.equal(preview.page.value, 2);
        assert.deepEqual([...context.getImageData(45, 150, 1, 1).data], [255, 0, 0, 255]);
    } finally {
        preview.close();
    }
});

test('a damaged PDF stops loading and offers an error state', async () => {
    const preview = runtime(new File(['%PDF-1.4\ninvalid'], 'rusak.pdf', { type: 'application/pdf' }));
    try {
        await preview.mount();
        assert.ok(preview.error.value);
        assert.equal(preview.loading.value, false);
        assert.equal(preview.pdf.value, null);
    } finally {
        preview.close();
    }
});
