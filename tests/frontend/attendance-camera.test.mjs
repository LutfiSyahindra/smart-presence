import assert from 'node:assert/strict';
import { File } from 'node:buffer';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/lib/attendance-camera.ts', import.meta.url), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;

function pixels(low, high = low) {
    const data = new Uint8ClampedArray(128 * 128 * 4);
    for (let y = 0; y < 128; y += 1) {
        for (let x = 0; x < 128; x += 1) {
            const offset = (y * 128 + x) * 4;
            data.fill((Math.floor(x / 3) + Math.floor(y / 3)) % 2 ? high : low, offset, offset + 3);
            data[offset + 3] = 255;
        }
    }
    return data;
}

function runtime({ width = 1920, height = 1080, paused = false, cancelAt = Infinity, cancelEncoding = false, invalidBlob = false } = {}) {
    const exports = {};
    const canvases = [];
    const encoded = [];
    const fullDraws = [];
    const frames = [pixels(128), pixels(95, 160), pixels(0, 8), pixels(250, 254)];
    const video = { videoWidth: width, videoHeight: height, readyState: 2, paused, ended: false };
    let frame = -1;
    let active = true;
    vm.runInNewContext(compiled, {
        exports,
        File,
        DOMException,
        HTMLMediaElement: { HAVE_CURRENT_DATA: 2 },
        window: {
            setTimeout(callback) {
                queueMicrotask(() => {
                    frame += 1;
                    if (frame >= cancelAt) active = false;
                    callback();
                });
            },
        },
        document: {
            createElement() {
                const canvas = {
                    width: 0, height: 0, frame: -1,
                    getContext: () => ({
                        drawImage(input, ...args) {
                            canvas.frame = input === video ? frame : input.frame;
                            if (input === video) fullDraws.push(args);
                        },
                        getImageData: () => ({ data: frames[canvas.frame] }),
                    }),
                    toBlob(callback, type, quality) {
                        encoded.push({ frame: canvas.frame, width: canvas.width, height: canvas.height, type, quality });
                        if (cancelEncoding) active = false;
                        callback(invalidBlob ? null : new Blob([String(canvas.frame)], { type }));
                    },
                };
                canvases.push(canvas);
                return canvas;
            },
        },
    });
    return { ...exports, video, canvases, encoded, fullDraws, capture: () => exports.captureAttendancePhoto(video, 'clock-in.jpg', () => active) };
}

test('image ranking favours visible detail over blur and clipped exposure', () => {
    const { cameraFrameScore } = runtime();
    const sharp = cameraFrameScore(pixels(95, 160), 128, 128);
    for (const poor of [pixels(128), pixels(0, 8), pixels(250, 254)]) {
        assert.ok(sharp > cameraFrameScore(poor, 128, 128));
    }
});

test('one press uploads the clearest full frame rather than the initial blurred frame', async () => {
    const state = runtime();
    const file = await state.capture();
    assert.equal(file.name, 'clock-in.jpg');
    assert.equal(file.type, 'image/jpeg');
    assert.equal(await file.text(), '1');
    assert.equal(state.fullDraws.length, 4);
    assert.ok(state.fullDraws.every((args) => args.join(',') === '0,0,1280,720'));
    assert.equal(state.encoded.length, 1);
    assert.deepEqual(state.encoded[0], { frame: 1, width: 1280, height: 720, type: 'image/jpeg', quality: 0.95 });
    assert.ok(state.canvases.every((canvas) => canvas.width === 0 && canvas.height === 0));
});

test('portrait and smaller cameras retain their aspect ratio without upscaling', async () => {
    for (const [width, height, expected] of [[1080, 1920, [720, 1280]], [480, 640, [480, 640]]]) {
        const state = runtime({ width, height });
        await state.capture();
        assert.deepEqual([state.encoded[0].width, state.encoded[0].height], expected);
    }
});

test('closing or replacing the camera during sampling never returns stale evidence', async () => {
    const state = runtime({ cancelAt: 1 });
    await assert.rejects(state.capture(), { name: 'AbortError' });
    assert.equal(state.encoded.length, 0);
    assert.ok(state.canvases.every((canvas) => canvas.width === 0));
});

test('closing the camera during JPEG encoding also cancels the result', async () => {
    await assert.rejects(runtime({ cancelEncoding: true }).capture(), { name: 'AbortError' });
});

test('paused and low-resolution cameras cannot submit an unusable frame', async () => {
    for (const options of [{ paused: true }, { width: 120, height: 160 }]) {
        const state = runtime(options);
        await assert.rejects(state.capture(), /kamera/);
        assert.equal(state.encoded.length, 0);
        assert.ok(state.canvases.every((canvas) => canvas.width === 0));
    }
});

test('encoding failure produces an actionable error and releases canvas buffers', async () => {
    const state = runtime({ invalidBlob: true });
    await assert.rejects(state.capture(), /gagal diambil/);
    assert.ok(state.canvases.every((canvas) => canvas.width === 0));
});
