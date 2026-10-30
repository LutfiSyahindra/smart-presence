<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowRight, ArrowUp, GitPullRequestArrow, Layers3, Pencil, Plus, Search, Trash2, UsersRound, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import '../approval-workflow.css';
import ConfirmDelete from '../setting/components/ConfirmDelete.vue';
import CrudModal from '../setting/components/CrudModal.vue';
import FlashMessage from '../setting/components/FlashMessage.vue';
import Pagination from '../setting/components/Pagination.vue';
import type { Paginated } from '../setting/types';
import SearchableMultiSelect from './components/SearchableMultiSelect.vue';

type ApprovalType = 'cuti' | 'lembur' | 'perubahan-shift' | 'presensi';

interface LineRow {
    id: number;
    type: ApprovalType;
    jabatans: { id: number; nama: string; unit: string | null; aktif: boolean }[];
    steps: { user_id: number; name: string; email: string | null; level: number; available: boolean }[];
    updated_at: string;
}

const props = defineProps<{
    lines: Paginated<LineRow>;
    jabatanOptions: { id: number; nama: string; unit: string; configured: boolean }[];
    userOptions: { id: number; name: string; email: string }[];
    overview: { configured: number; positions: number; approvers: number };
    filters: { type: ApprovalType; search: string };
    typeOptions: { value: ApprovalType; label: string }[];
    can: { create: boolean; update: boolean; delete: boolean };
}>();

const baseUrl = '/absensi/admin-web/approval-line';
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/absensi/admin-web/dashboard' },
    { title: 'Aproval Line', href: baseUrl },
];
const search = ref(props.filters.search);
const open = ref(false);
const editing = ref<LineRow | null>(null);
const deleting = ref<LineRow | null>(null);
const deleteProcessing = ref(false);
const typeLabel = (type: ApprovalType) => props.typeOptions.find((option) => option.value === type)?.label ?? '';
const activeLabel = computed(() => typeLabel(props.filters.type));
const form = useForm({ type: props.filters.type, jabatan_ids: [] as number[], approver_ids: [] as number[] });
const formLabel = computed(() => typeLabel(form.type));
const availablePositions = computed(() =>
    props.jabatanOptions.filter((position) => !position.configured || editing.value?.jabatans.some((selected) => selected.id === position.id)),
);
const positionOptions = computed(() =>
    availablePositions.value.map((position) => ({
        value: position.id,
        label: position.nama,
        description: position.unit,
    })),
);
const selectedPositions = computed(() =>
    form.jabatan_ids.map((id) => {
        const position =
            props.jabatanOptions.find((position) => position.id === id) ?? editing.value?.jabatans.find((position) => position.id === id);
        return { id, nama: position?.nama ?? 'Jabatan tidak tersedia', unit: position?.unit ?? 'Tanpa unit' };
    }),
);
const lineLabel = (line: LineRow | null) => line?.jabatans.map((position) => position.nama).join(', ') || 'jabatan yang dipilih';
const setPositions = (ids: number[]) => {
    form.jabatan_ids = ids;
    form.clearErrors();
};
const removePosition = (id: number) => {
    form.jabatan_ids = form.jabatan_ids.filter((positionId) => positionId !== id);
    form.clearErrors();
};
const approverOptions = computed(() => {
    const options = props.userOptions.map((user) => ({
        value: user.id,
        label: user.name,
        description: user.email,
        disabled: false,
    }));
    for (const userId of form.approver_ids) {
        if (userId && !options.some((option) => option.value === userId)) {
            options.unshift({
                value: userId,
                label: 'Pengguna tidak tersedia — pilih pengganti',
                description: editing.value?.steps.find((step) => step.user_id === userId)?.name ?? '',
                disabled: true,
            });
        }
    }
    return options;
});
const selectedApprovers = computed(() => form.approver_ids.map((id) => ({ id, ...approverOptions.value.find((option) => option.value === id) })));
const setApprovers = (ids: number[]) => {
    form.approver_ids = ids;
    form.clearErrors();
};
const errors = computed(() => form.errors as Record<string, string | undefined>);
const positionErrors = computed(() => Object.entries(errors.value).filter(([key]) => key === 'jabatan_ids' || key.startsWith('jabatan_ids.')));
const approverErrors = computed(() => Object.entries(errors.value).filter(([key]) => key === 'approver_ids' || key.startsWith('approver_ids.')));
let timer: ReturnType<typeof setTimeout> | undefined;

const edit = (line: LineRow | null = null) => {
    editing.value = line;
    form.defaults({
        type: line?.type ?? props.filters.type,
        jabatan_ids: line?.jabatans.map((position) => position.id) ?? [],
        approver_ids: line?.steps.map((step) => step.user_id) ?? [],
    });
    form.reset();
    form.clearErrors();
    open.value = true;
};
const close = () => {
    if (!form.processing) open.value = false;
};
const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (editing.value) form.put(`${baseUrl}/${editing.value.id}`, options);
    else form.post(baseUrl, options);
};
const removeStep = (index: number) => {
    form.approver_ids.splice(index, 1);
    form.clearErrors();
};
const moveStep = (index: number, offset: number) => {
    const nextIndex = index + offset;
    if (nextIndex < 0 || nextIndex >= form.approver_ids.length) return;
    const ids = [...form.approver_ids];
    [ids[index], ids[nextIndex]] = [ids[nextIndex], ids[index]];
    setApprovers(ids);
};
const destroy = () => {
    if (!deleting.value) return;
    deleteProcessing.value = true;
    router.delete(`${baseUrl}/${deleting.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (deleting.value = null),
        onFinish: () => (deleteProcessing.value = false),
    });
};
const applySearch = () => {
    if (timer) clearTimeout(timer);
    router.get(
        baseUrl,
        { type: props.filters.type, search: search.value.trim() || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};
const selectType = (type: ApprovalType) => {
    if (type === props.filters.type || form.processing || deleteProcessing.value) return;
    if (timer) clearTimeout(timer);
    open.value = false;
    editing.value = null;
    deleting.value = null;
    router.get(baseUrl, { type, search: search.value.trim() || undefined }, { preserveState: true, preserveScroll: true });
};
watch(search, () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(applySearch, 400);
});
watch(
    () => props.filters.search,
    (value) => {
        if (search.value !== value) search.value = value;
    },
);
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});
</script>

<template>
    <Head title="Aproval Line" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="al-page">
            <FlashMessage />
            <section class="al-hero">
                <div>
                    <span class="al-eyebrow"><GitPullRequestArrow /> Alur persetujuan</span>
                    <h1>Aproval Line</h1>
                    <p>Atur siapa yang menyetujui pengajuan cuti, lembur, dan perubahan shift untuk setiap jabatan hingga tiga tingkat.</p>
                    <span class="al-module">Pengajuan {{ activeLabel.toLowerCase() }} <span>•</span> Approval berurutan</span>
                </div>
                <button v-if="can.create" type="button" class="btn btn-primary al-button" @click="edit()"><Plus /> Tambah approval line</button>
            </section>

            <div class="al-stats">
                <article>
                    <span class="al-stat-icon"><GitPullRequestArrow /></span>
                    <div>
                        <strong>{{ overview.configured }}</strong
                        ><span>Jabatan memiliki alur {{ activeLabel.toLowerCase() }}</span>
                    </div>
                </article>
                <article>
                    <span class="al-stat-icon cyan"><UsersRound /></span>
                    <div>
                        <strong>{{ overview.positions }}</strong
                        ><span>Jabatan aktif</span>
                    </div>
                </article>
                <article>
                    <span class="al-stat-icon amber"><Layers3 /></span>
                    <div><strong>3 tingkat</strong><span>Batas maksimal approval</span></div>
                </article>
            </div>

            <section class="al-panel">
                <div class="al-tabs" role="group" aria-label="Kategori approval line">
                    <button
                        v-for="option in typeOptions"
                        :key="option.value"
                        type="button"
                        :class="{ active: filters.type === option.value }"
                        :aria-pressed="filters.type === option.value"
                        :disabled="form.processing || deleteProcessing"
                        @click="selectType(option.value)"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <header class="al-panel-header">
                    <div>
                        <h2>Daftar approval line {{ activeLabel.toLowerCase() }}</h2>
                        <p>Satu alur dapat digunakan oleh beberapa jabatan pemohon. Setiap kategori memiliki pengaturan sendiri.</p>
                    </div>
                    <form class="al-search" role="search" @submit.prevent="applySearch">
                        <Search /><input
                            v-model="search"
                            type="search"
                            aria-label="Cari approval line"
                            placeholder="Cari unit, jabatan, atau approver..."
                            maxlength="150"
                        />
                    </form>
                </header>
                <div v-if="lines.data.length" class="al-table-wrap">
                    <table class="al-table">
                        <thead>
                            <tr>
                                <th>Jabatan & unit kerja</th>
                                <th>Urutan persetujuan</th>
                                <th>Diperbarui</th>
                                <th v-if="can.update || can.delete" class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in lines.data" :key="line.id">
                                <td>
                                    <div v-for="position in line.jabatans" :key="position.id" class="al-position">
                                        <strong>{{ position.nama }}</strong>
                                        <small>{{ position.unit ?? 'Tanpa unit' }}</small>
                                        <span v-if="!position.aktif" class="al-status rejected">Jabatan / unit tidak aktif</span>
                                    </div>
                                    <small v-if="!line.jabatans.length">Jabatan tidak tersedia</small>
                                </td>
                                <td>
                                    <div class="al-flow">
                                        <template v-for="(step, index) in line.steps" :key="step.level">
                                            <ArrowRight v-if="index" class="al-flow-arrow" aria-hidden="true" />
                                            <div class="al-flow-person" :class="{ 'al-unavailable': !step.available }">
                                                <span>{{ step.level }}</span>
                                                <div>
                                                    <strong>{{ step.name }}</strong
                                                    ><small>{{ step.available ? `Tingkat ${step.level}` : 'Pengguna tidak tersedia' }}</small>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </td>
                                <td>
                                    <small>{{ line.updated_at }}</small>
                                </td>
                                <td v-if="can.update || can.delete">
                                    <div class="al-actions">
                                        <button
                                            v-if="can.update"
                                            type="button"
                                            class="al-icon-button"
                                            :aria-label="`Ubah alur ${lineLabel(line)}`"
                                            @click="edit(line)"
                                        >
                                            <Pencil /></button
                                        ><button
                                            v-if="can.delete"
                                            type="button"
                                            class="al-icon-button danger"
                                            :aria-label="`Hapus alur ${lineLabel(line)}`"
                                            @click="deleting = line"
                                        >
                                            <Trash2 />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="al-empty">
                    <GitPullRequestArrow />
                    <h3>{{ search ? 'Approval line tidak ditemukan' : `Belum ada approval line ${activeLabel.toLowerCase()}` }}</h3>
                    <p>
                        {{
                            search
                                ? 'Coba kata pencarian lain.'
                                : `Tambahkan jabatan dan pilih approver pertama untuk mulai mengatur alur ${activeLabel.toLowerCase()}.`
                        }}
                    </p>
                    <button v-if="can.create && !search" type="button" class="btn btn-primary" @click="edit()">Tambah approval line</button>
                </div>
                <Pagination :links="lines.links" :from="lines.from" :to="lines.to" :total="lines.total" />
            </section>
            <p class="al-footnote">
                Pengajuan baru mengikuti pengaturan terbaru. Pengajuan yang sudah dikirim tetap menggunakan urutan approver saat diajukan.
            </p>
        </div>

        <CrudModal
            :open="open"
            :title="`${editing ? 'Ubah' : 'Tambah'} approval line ${formLabel.toLowerCase()}`"
            :description="`Pilih satu atau beberapa jabatan pemohon dan pengguna yang menyetujui ${formLabel.toLowerCase()}.`"
            @close="close"
        >
            <form class="al-form" @submit.prevent="submit">
                <div class="al-form-body">
                    <div class="al-info">
                        Jenis pengajuan: <strong>{{ formLabel }}</strong
                        >. Persetujuan dilakukan mulai tingkat 1 hingga tingkat terakhir.
                    </div>
                    <small v-if="form.errors.type" class="al-error">{{ form.errors.type }}</small>
                    <div class="al-field">
                        <span>Jabatan pemohon <b>*</b></span>
                        <div v-if="selectedPositions.length" class="al-position-chips" aria-label="Jabatan pemohon terpilih">
                            <span v-for="position in selectedPositions" :key="position.id" class="al-position-chip">
                                <span
                                    ><strong>{{ position.nama }}</strong
                                    ><small>{{ position.unit }}</small></span
                                >
                                <button
                                    type="button"
                                    :disabled="form.processing"
                                    :aria-label="`Hapus jabatan ${position.nama}`"
                                    @click="removePosition(position.id)"
                                >
                                    <X aria-hidden="true" />
                                </button>
                            </span>
                        </div>
                        <SearchableMultiSelect
                            :model-value="form.jabatan_ids"
                            :options="positionOptions"
                            label="Jabatan pemohon"
                            search-placeholder="Cari unit atau jabatan..."
                            :disabled="form.processing"
                            :class="{ invalid: positionErrors.length > 0 }"
                            @update:model-value="setPositions"
                        />
                        <small v-for="[key, message] in positionErrors" :key="key" class="al-error">{{ message }}</small>
                        <small class="al-help">Centang beberapa jabatan sekaligus atau gunakan Pilih semua hasil.</small>
                    </div>
                    <p v-if="!availablePositions.length && !editing" class="al-help">
                        Semua jabatan aktif sudah memiliki alur {{ formLabel.toLowerCase() }}, atau master jabatan belum tersedia.
                    </p>
                    <div class="al-field">
                        <span>Approver <b>*</b></span>
                        <SearchableMultiSelect
                            :model-value="form.approver_ids"
                            :options="approverOptions"
                            label="Approver"
                            search-placeholder="Cari nama atau email pengguna..."
                            :max-selected="3"
                            show-order
                            :disabled="form.processing"
                            :class="{ invalid: approverErrors.length > 0 }"
                            @update:model-value="setApprovers"
                        />
                        <small v-for="[key, message] in approverErrors" :key="key" class="al-error">{{ message }}</small>
                        <small class="al-help"
                            >Centang beberapa pengguna sekaligus. Urutan pilihan menjadi tingkat persetujuan dan dapat diubah di bawah.</small
                        >
                    </div>
                    <div v-if="selectedApprovers.length" class="al-section-label">
                        <strong>Urutan approver</strong><span>{{ selectedApprovers.length }} / 3 tingkat</span>
                    </div>
                    <div v-for="(approver, index) in selectedApprovers" :key="approver.id" class="al-step-input">
                        <span class="al-step-number">{{ index + 1 }}</span>
                        <div class="al-approver-summary">
                            <strong>{{ approver.label }}</strong>
                            <small>{{ approver.description }}</small>
                            <small>Tingkat {{ index + 1 }}</small>
                        </div>
                        <div class="al-actions">
                            <button
                                type="button"
                                class="al-icon-button"
                                :aria-label="`Naikkan urutan ${approver.label}`"
                                :disabled="form.processing || index === 0"
                                @click="moveStep(index, -1)"
                            >
                                <ArrowUp />
                            </button>
                            <button
                                type="button"
                                class="al-icon-button"
                                :aria-label="`Turunkan urutan ${approver.label}`"
                                :disabled="form.processing || index === selectedApprovers.length - 1"
                                @click="moveStep(index, 1)"
                            >
                                <ArrowDown />
                            </button>
                            <button
                                type="button"
                                class="al-icon-button danger"
                                :aria-label="`Hapus approver ${approver.label}`"
                                :disabled="form.processing"
                                @click="removeStep(index)"
                            >
                                <Trash2 />
                            </button>
                        </div>
                    </div>
                    <p class="al-help">
                        Semua pengguna dapat dipilih sebagai approver, tanpa batasan jabatan, unit, atau role. Setiap tingkat harus diisi pengguna
                        yang berbeda. Pemohon tidak boleh menyetujui pengajuannya sendiri.
                    </p>
                    <p v-if="!userOptions.length" class="al-error">Belum ada pengguna yang dapat dipilih. Tambahkan pengguna terlebih dahulu.</p>
                </div>
                <footer class="al-form-footer">
                    <button type="button" class="btn btn-outline-secondary" :disabled="form.processing" @click="close">Batal</button
                    ><button
                        type="submit"
                        class="btn btn-primary"
                        :disabled="form.processing || !form.jabatan_ids.length || !form.approver_ids.length"
                    >
                        {{ form.processing ? 'Menyimpan...' : 'Simpan approval line' }}
                    </button>
                </footer>
            </form>
        </CrudModal>
        <ConfirmDelete
            :open="!!deleting"
            title="Hapus approval line?"
            :description="`Alur ${typeLabel(deleting?.type ?? filters.type).toLowerCase()} untuk ${lineLabel(deleting)} akan dihapus. Pengajuan yang sedang berjalan tetap diproses dengan urutan sebelumnya. Pengajuan baru memerlukan alur pengganti.`"
            :processing="deleteProcessing"
            @close="!deleteProcessing && (deleting = null)"
            @confirm="destroy"
        />
    </AppLayout>
</template>
