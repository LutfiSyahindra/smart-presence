<script setup lang="ts">
import { ChevronLeft, ChevronRight, Download, FileText, LoaderCircle, ZoomIn, ZoomOut } from 'lucide-vue-next';
import type { PDFDocumentLoadingTask, PDFDocumentProxy, RenderTask } from 'pdfjs-dist';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import type { LemburAttachment } from '../lembur/types';
import CutiSheet from './CutiSheet.vue';

const props = defineProps<{ file: File | LemburAttachment }>();
const emit = defineEmits<{ close: [] }>();
const extension = props.file.name.split('.').pop()?.toLowerCase();
const kind = ['jpg', 'jpeg', 'png'].includes(extension ?? '')
    ? 'image'
    : extension === 'pdf'
      ? 'pdf'
      : ['txt', 'csv'].includes(extension ?? '')
        ? 'text'
        : 'unsupported';
const downloadUrl = computed(() => ('url' in props.file ? props.file.url : null));
const loading = ref(kind !== 'unsupported');
const error = ref('');
const imageUrl = ref('');
const text = ref('');
const canvas = ref<HTMLCanvasElement | null>(null);
const pdf = shallowRef<PDFDocumentProxy | null>(null);
const page = ref(1);
const zoom = ref(1);
const rendering = ref(false);
const controller = new AbortController();
let loadingTask: PDFDocumentLoadingTask | undefined;
let renderTask: RenderTask | undefined;
let disposed = false;

async function renderPage(number: number) {
    if (!pdf.value || rendering.value || disposed) return;
    rendering.value = true;
    error.value = '';
    try {
        const pdfPage = await pdf.value.getPage(number);
        if (disposed) return;
        await nextTick();
        const target = canvas.value;
        if (!target) return;
        const context = target.getContext('2d');
        if (!context) throw new Error('Canvas tidak tersedia.');
        const size = pdfPage.getViewport({ scale: 1 });
        const viewport = pdfPage.getViewport({ scale: Math.min(1.5, Math.sqrt(4_000_000 / (size.width * size.height))) });
        target.width = Math.ceil(viewport.width);
        target.height = Math.ceil(viewport.height);
        renderTask = pdfPage.render({ canvas: target, canvasContext: context, viewport });
        await renderTask.promise;
        if (!disposed) page.value = number;
    } catch {
        if (!disposed) error.value = 'Halaman PDF belum dapat ditampilkan. Silakan coba kembali.';
    } finally {
        rendering.value = false;
        renderTask = undefined;
    }
}

async function load() {
    if (kind === 'unsupported') return;
    loading.value = true;
    error.value = '';
    try {
        if (imageUrl.value) {
            URL.revokeObjectURL(imageUrl.value);
            imageUrl.value = '';
        }
        await loadingTask?.destroy();
        loadingTask = undefined;
        if (disposed) return;
        let blob: Blob;
        if ('url' in props.file) {
            if (!props.file.preview_url) throw new Error('Pratinjau tidak tersedia.');
            const response = await fetch(props.file.preview_url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/pdf, image/jpeg, image/png, text/plain' },
                signal: controller.signal,
            });
            if (!response.ok || response.redirected || response.headers.get('Content-Type')?.includes('text/html')) {
                throw new Error('Lampiran tidak dapat dimuat.');
            }
            blob = await response.blob();
        } else {
            blob = props.file;
        }
        if (disposed) return;
        if (kind === 'image') {
            imageUrl.value = URL.createObjectURL(blob);
        } else if (kind === 'text') {
            text.value = await blob.text();
            loading.value = false;
        } else {
            const [pdfjs, worker, buffer] = await Promise.all([
                import('pdfjs-dist/legacy/build/pdf.mjs'),
                import('pdfjs-dist/legacy/build/pdf.worker.min.mjs?url'),
                blob.arrayBuffer(),
            ]);
            if (disposed) return;
            pdfjs.GlobalWorkerOptions.workerSrc = worker.default;
            loadingTask = pdfjs.getDocument({ data: new Uint8Array(buffer), isEvalSupported: false });
            const document = await loadingTask.promise;
            if (disposed) return;
            pdf.value = document;
            loading.value = false;
            await nextTick();
            await renderPage(1);
        }
    } catch {
        if (!disposed) {
            error.value = 'Lampiran belum dapat ditampilkan. Pastikan koneksi Anda aktif lalu coba kembali.';
            loading.value = false;
        }
    }
}

function imageFailed() {
    loading.value = false;
    error.value = 'Gambar belum dapat ditampilkan. File mungkin rusak atau tidak tersedia.';
}

onMounted(load);
onBeforeUnmount(() => {
    disposed = true;
    controller.abort();
    renderTask?.cancel();
    void loadingTask?.destroy().catch(() => {});
    if (imageUrl.value) URL.revokeObjectURL(imageUrl.value);
});
</script>

<template>
    <CutiSheet :open="true" title="Pratinjau lampiran" :description="file.name" @close="emit('close')">
        <div class="attachment-preview" :aria-busy="loading || rendering">
            <p v-if="loading" class="preview-message" role="status"><LoaderCircle class="spinner" />Memuat lampiran…</p>
            <div v-if="error" class="preview-message" role="alert">
                <FileText />
                <p>{{ error }}</p>
                <button type="button" @click="pdf ? renderPage(page) : load()">Coba kembali</button>
            </div>
            <div v-if="kind === 'unsupported'" class="preview-message">
                <FileText />
                <p>Pratinjau tersedia untuk PDF, JPG, JPEG, PNG, TXT, dan CSV. Format file ini belum dapat ditampilkan di aplikasi.</p>
            </div>
            <img v-if="imageUrl && !error" :src="imageUrl" :alt="file.name" class="preview-image" @load="loading = false" @error="imageFailed" />
            <pre v-if="kind === 'text' && !loading && !error" class="preview-text">{{ text || 'File ini kosong.' }}</pre>
            <template v-if="pdf">
                <div class="pdf-controls" aria-label="Navigasi halaman PDF">
                    <button type="button" aria-label="Halaman sebelumnya" :disabled="page <= 1 || rendering" @click="renderPage(page - 1)">
                        <ChevronLeft />
                    </button>
                    <span role="status">{{ rendering ? 'Memuat…' : `Halaman ${page} dari ${pdf.numPages}` }}</span>
                    <button type="button" aria-label="Halaman berikutnya" :disabled="page >= pdf.numPages || rendering" @click="renderPage(page + 1)">
                        <ChevronRight />
                    </button>
                </div>
                <div class="pdf-controls" aria-label="Ukuran pratinjau PDF">
                    <button type="button" aria-label="Perkecil PDF" :disabled="zoom <= 1" @click="zoom -= 0.25"><ZoomOut /></button>
                    <span>{{ Math.round(zoom * 100) }}%</span>
                    <button type="button" aria-label="Perbesar PDF" :disabled="zoom >= 3" @click="zoom += 0.25"><ZoomIn /></button>
                </div>
                <div class="pdf-page">
                    <canvas ref="canvas" role="img" :aria-label="`Halaman ${page} dari ${file.name}`" :style="{ width: `${zoom * 100}%` }"></canvas>
                </div>
            </template>
        </div>
        <template #footer>
            <div class="preview-actions">
                <button type="button" @click="emit('close')">Tutup pratinjau</button>
                <a v-if="downloadUrl" :href="downloadUrl"><Download />Unduh</a>
            </div>
        </template>
    </CutiSheet>
</template>

<style scoped>
.attachment-preview {
    min-height: 180px;
}
.preview-message {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    margin: 0;
    padding: 24px 12px;
    text-align: center;
    font-size: 12px;
    line-height: 1.8;
    color: #60746b;
}
.preview-message p {
    margin: 0;
}
.preview-message > svg {
    width: 28px;
    height: 28px;
}
.preview-image {
    display: block;
    width: 100%;
    height: auto;
    border-radius: 10px;
}
.preview-text {
    margin: 0;
    padding: 12px;
    border: 1px solid #e1e8d5;
    border-radius: 10px;
    background: #f7faf0;
    color: #345344;
    font-size: 12px;
    line-height: 1.8;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.pdf-controls,
.preview-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}
.pdf-controls {
    margin-bottom: 12px;
    font-size: 12px;
    color: #60746b;
}
button,
.preview-actions a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 44px;
    border: 1px solid #dce5d4;
    border-radius: 12px;
    padding: 10px 12px;
    background: #f7faf0;
    color: #345344;
    font: inherit;
    font-size: 12px;
    text-decoration: none;
    cursor: pointer;
}
button:disabled {
    opacity: 0.45;
    cursor: default;
}
button:focus-visible,
a:focus-visible {
    outline: 3px solid #bc9a56;
    outline-offset: 3px;
}
button svg,
a svg {
    width: 16px;
    height: 16px;
}
.pdf-page {
    overflow-x: auto;
    border: 1px solid #e1e8d5;
    border-radius: 8px;
    background: #fff;
}
.pdf-page canvas {
    display: block;
    width: 100%;
    height: auto;
}
@media (prefers-reduced-motion: no-preference) {
    .spinner {
        animation: spin 1s linear infinite;
    }
    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }
}
</style>
