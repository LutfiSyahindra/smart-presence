import assert from 'node:assert/strict';
import { File } from 'node:buffer';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const canvasModule = await import('@napi-rs/canvas').catch(() => null);
const helperSource = await readFile(new URL('../../resources/js/lib/profile-photo.ts', import.meta.url), 'utf8');
const componentSource = await readFile(new URL('../../resources/js/pages/absensi/absensi-mobile/Account.vue', import.meta.url), 'utf8');
const compile = (source) =>
    ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;

function photoRuntime({ width = 4000, height = 3000, sizes = [150_000], corrupt = false, noContext = false } = {}) {
    const revoked = [];
    const qualities = [];
    const draws = [];
    const fills = [];
    const canvas = {
        getContext: () => (noContext ? null : { fillRect: (...args) => fills.push(args), drawImage: (...args) => draws.push(args) }),
        toBlob(callback, type, quality) {
            qualities.push(quality);
            const size = sizes[Math.min(qualities.length - 1, sizes.length - 1)];
            callback(size === null ? null : new Blob([new Uint8Array(size)], { type }));
        },
    };
    const exports = {};
    vm.runInNewContext(compile(helperSource), {
        exports,
        File,
        URL: { createObjectURL: () => 'blob:source', revokeObjectURL: (url) => revoked.push(url) },
        document: { createElement: () => canvas },
        Image: class {
            naturalWidth = width;
            naturalHeight = height;
            set src(_) {
                queueMicrotask(() => (corrupt ? this.onerror() : this.onload()));
            }
        },
    });
    return { prepare: exports.prepareProfilePhoto, revoked, qualities, draws, fills, canvas };
}

for (const [width, height, expected, canvasSize] of [
    [8000, 6000, [0, 0, 8000, 6000, 0, 0, 1024, 768], [1024, 768]],
    [6000, 8000, [0, 0, 6000, 8000, 0, 0, 768, 1024], [768, 1024]],
    [480, 640, [0, 0, 480, 640, 0, 0, 480, 640], [480, 640]],
    [240, 240, [0, 0, 240, 240, 0, 0, 480, 480], [480, 480]],
    [240, 320, [0, 0, 240, 320, 0, 0, 480, 640], [480, 640]],
    [160, 320, [0, 0, 160, 320, 0, 0, 480, 960], [480, 960]],
    [320, 160, [0, 0, 320, 160, 0, 0, 960, 480], [960, 480]],
    [200, 1000, [0, 0, 200, 1000, 137.5, 0, 205, 1024], [480, 1024]],
    [480, 6000, [0, 0, 480, 6000, 199, 0, 82, 1024], [480, 1024]],
    [6000, 480, [0, 0, 6000, 480, 0, 199, 1024, 82], [1024, 480]],
]) {
    test(`${width} x ${height} photos retain the entire image and meet the upload dimensions, including files over 5 MB`, async () => {
        const runtime = photoRuntime({ width, height });
        const input = new File([new Uint8Array(6 * 1024 * 1024)], 'wajah.png', { type: 'image/png' });
        const result = await runtime.prepare(input);
        assert.equal(result.name, 'wajah.jpg');
        assert.equal(result.type, 'image/jpeg');
        assert.ok(result.size <= 1024 * 1024);
        assert.deepEqual(runtime.draws[0].slice(1), expected);
        assert.deepEqual(runtime.fills[0], [0, 0, ...canvasSize]);
        assert.deepEqual(runtime.revoked, ['blob:source']);
        assert.equal(runtime.canvas.width, 0);
    });
}

test('compression retries until the output fits within 1 MB', async () => {
    const runtime = photoRuntime({ sizes: [1_500_000, 1_100_000, 900_000] });
    const result = await runtime.prepare(new File(['photo'], 'wajah.webp', { type: 'image/webp' }));
    assert.equal(result.size, 900_000);
    assert.deepEqual(runtime.qualities, [0.92, 0.85, 0.78]);
});

for (const [label, options, message] of [
    ['unreadable image', { corrupt: true }, /tidak dapat dibaca/],
    ['invalid dimensions', { width: 0 }, /Dimensi foto tidak valid/],
    ['missing canvas context', { noContext: true }, /belum dapat disesuaikan/],
    ['failed encoding', { sizes: [null] }, /belum dapat disesuaikan/],
    ['output still too large', { sizes: [2_000_000] }, /Ukuran foto belum berhasil/],
]) {
    test(`${label} gives a useful error and releases its source URL`, async () => {
        const runtime = photoRuntime(options);
        await assert.rejects(runtime.prepare(new File(['photo'], 'wajah.jpg', { type: 'image/jpeg' })), message);
        assert.deepEqual(runtime.revoked, ['blob:source']);
    });
}

test('unsupported files are rejected before decoding', async () => {
    const runtime = photoRuntime();
    await assert.rejects(runtime.prepare(new File(['text'], 'file.txt', { type: 'text/plain' })), /JPG, PNG, atau WebP/);
    assert.equal(runtime.draws.length, 0);
});

test('a real portrait retains its top and bottom edges, with transparent areas rendered white', { skip: !canvasModule }, async () => {
    const urls = new Map();
    const exports = {};
    const source = canvasModule.createCanvas(640, 1280);
    const sourceContext = source.getContext('2d');
    // The blue top and bottom bands must survive resizing around the red center.
    sourceContext.fillStyle = '#2020e0';
    sourceContext.fillRect(0, 0, 640, 1280);
    sourceContext.fillStyle = '#e02020';
    sourceContext.fillRect(0, 256, 640, 768);
    sourceContext.clearRect(240, 560, 160, 160);
    function BrowserImage() {
        const image = new canvasModule.Image();
        const src = Object.getOwnPropertyDescriptor(canvasModule.Image.prototype, 'src');
        Object.defineProperty(image, 'src', {
            set(url) {
                urls.get(url)
                    .arrayBuffer()
                    .then((bytes) => src.set.call(image, new Uint8Array(bytes)))
                    .catch((error) => image.onerror(error));
            },
        });
        return image;
    }
    vm.runInNewContext(compile(helperSource), {
        exports,
        File,
        Image: BrowserImage,
        URL: {
            createObjectURL(file) {
                urls.set('blob:source', file);
                return 'blob:source';
            },
            revokeObjectURL(url) {
                urls.delete(url);
            },
        },
        document: { createElement: () => canvasModule.createCanvas(1, 1) },
    });
    const result = await exports.prepareProfilePhoto(new File([source.toBuffer('image/png')], 'wajah.png', { type: 'image/png' }));
    const decoded = await canvasModule.loadImage(new Uint8Array(await result.arrayBuffer()));
    assert.equal(decoded.width, 512);
    assert.equal(decoded.height, 1024);
    assert.ok(result.size < 1024 * 1024);
    const output = canvasModule.createCanvas(512, 1024).getContext('2d');
    output.drawImage(decoded, 0, 0);
    const pixel = (x, y) => [...output.getImageData(x, y, 1, 1).data];
    for (const [x, y] of [
        [0, 0],
        [511, 0],
        [0, 1023],
        [511, 1023],
        [256, 100],
    ]) {
        const [red, green, blue] = pixel(x, y);
        assert.ok(blue > 180 && red < 60 && green < 60, `Expected the original blue photo edge at ${x}, ${y}`);
    }
    const [red, green, blue] = pixel(256, 300);
    assert.ok(red > 200 && green < 60 && blue < 60);
    assert.deepEqual(pixel(256, 512), [255, 255, 255, 255]);
    assert.equal(urls.size, 0);
});

function accountRuntime() {
    const pending = [];
    const previews = [];
    const revoked = [];
    const posts = [];
    const callbacks = {};
    const exports = {};
    vm.runInNewContext(
        compile(`${componentSource.split('<script setup lang="ts">')[1].split('</script>')[0]}
        export { selectPhoto, uploadPhoto, clearPreview, form, preparingPhoto, previewUrl, localError };`),
        {
            exports,
            Error,
            defineProps: () => ({ profile: { name: 'Pegawai', photo_url: null } }),
            route: (name) => name,
            URL: {
                createObjectURL(file) {
                    previews.push(file);
                    return `blob:preview-${previews.length}`;
                },
                revokeObjectURL: (url) => revoked.push(url),
            },
            require(name) {
                if (name === 'vue')
                    return {
                        ref: (value) => ({ value }),
                        computed: (get) => ({
                            get value() {
                                return get();
                            },
                        }),
                        onUnmounted: (callback) => {
                            callbacks.unmount = callback;
                        },
                    };
                if (name === '@/composables/usePermission') return { usePermission: () => ({ can: () => true }) };
                if (name === '@/lib/profile-photo')
                    return {
                        prepareProfilePhoto: (file) => new Promise((resolve, reject) => pending.push({ file, resolve, reject })),
                    };
                if (name === '@inertiajs/vue3')
                    return {
                        usePage: () => ({ props: { flash: {} } }),
                        useForm: (initial) => ({
                            ...initial,
                            processing: false,
                            clearErrors() {},
                            post: (url, options) => posts.push({ url, options, file: exports.form.photo }),
                        }),
                    };
                return {};
            },
        },
    );
    const select = (name = 'wajah.png') => {
        const input = { files: [new File(['original'], name, { type: 'image/png' })], value: name };
        return { input, done: exports.selectPhoto({ target: input }) };
    };
    return { ...exports, pending, previews, revoked, posts, callbacks, select };
}

test('only the prepared file is previewed and uploaded, and saving waits for preparation', async () => {
    const account = accountRuntime();
    const selection = account.select();
    account.uploadPhoto();
    assert.equal(account.posts.length, 0);
    assert.equal(account.preparingPhoto.value, true);
    const prepared = new File(['prepared'], 'wajah.jpg', { type: 'image/jpeg' });
    account.pending[0].resolve(prepared);
    await selection.done;
    assert.equal(account.form.photo, prepared);
    assert.equal(account.previews[0], prepared);
    assert.equal(account.preparingPhoto.value, false);
    assert.equal(selection.input.value, '');
    account.uploadPhoto();
    assert.equal(account.posts[0].file, prepared);
    account.posts[0].options.onSuccess();
    assert.equal(account.form.photo, null);
    assert.deepEqual(account.revoked, ['blob:preview-1']);
});

test('an earlier selection cannot overwrite the latest photo', async () => {
    const account = accountRuntime();
    const first = account.select('first.png');
    const second = account.select('second.png');
    const latest = new File(['latest'], 'second.jpg');
    account.pending[1].resolve(latest);
    await second.done;
    account.pending[0].resolve(new File(['earlier'], 'first.jpg'));
    await first.done;
    assert.equal(account.form.photo, latest);
    assert.equal(account.previews.length, 1);
});

for (const action of ['clearPreview', 'unmount']) {
    test(`${action} discards a photo that is still being prepared`, async () => {
        const account = accountRuntime();
        const selection = account.select();
        if (action === 'unmount') account.callbacks.unmount();
        else account.clearPreview();
        account.pending[0].resolve(new File(['prepared'], 'wajah.jpg'));
        await selection.done;
        assert.equal(account.form.photo, null);
        assert.equal(account.previews.length, 0);
    });
}

test('preparation errors reset the picker so the same photo can be tried again', async () => {
    const account = accountRuntime();
    const selection = account.select();
    account.pending[0].reject(new Error('Foto tidak dapat dibaca.'));
    await selection.done;
    assert.equal(account.localError.value, 'Foto tidak dapat dibaca.');
    assert.equal(account.preparingPhoto.value, false);
    assert.equal(selection.input.value, '');
    assert.equal(account.form.photo, null);
});
