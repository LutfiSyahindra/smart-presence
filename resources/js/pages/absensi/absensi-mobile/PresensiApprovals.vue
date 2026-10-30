<script setup lang="ts">
import type { PresensiApprovalItem } from '@/components/PresensiApprovalContent.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import MobileBackLink from './components/MobileBackLink.vue';
const props = defineProps<{
    scope: 'mine' | 'delegation';
    search: string;
    requests: { data: PresensiApprovalItem[]; total: number; prev_page_url: string | null; next_page_url: string | null };
}>();
const search = ref(props.search);
const labels: Record<string, string> = { pending: 'Menunggu persetujuan', approved: 'Disetujui', rejected: 'Ditolak' };
const base = '/absensi/mobile/notifikasi/presensi';
const apply = () => router.get(base, { scope: props.scope, search: search.value.trim() }, { preserveScroll: true });
</script>
<template>
    <Head title="Persetujuan presensi" />
    <main class="min-h-screen bg-emerald-50/50 px-4 pb-12 pt-5 text-emerald-950">
        <div class="mx-auto max-w-2xl">
            <MobileBackLink href="/absensi/mobile/notifikasi?tab=approvals">Kembali ke Inbox</MobileBackLink>
            <h1 class="mb-2 mt-6 text-2xl font-bold">Presensi di luar radius</h1>
            <p class="mb-5 text-sm text-slate-600">Tinjau bukti lokasi dan alasan presensi.</p>
            <nav class="mb-5 flex gap-3">
                <Link
                    :href="`${base}?scope=delegation`"
                    class="rounded-full border px-4 py-2 text-sm"
                    :class="scope === 'delegation' ? 'bg-emerald-800 text-white' : 'bg-white'"
                    >Butuh persetujuan</Link
                ><Link
                    :href="`${base}?scope=mine`"
                    class="rounded-full border px-4 py-2 text-sm"
                    :class="scope === 'mine' ? 'bg-emerald-800 text-white' : 'bg-white'"
                    >Presensi saya</Link
                >
            </nav>
            <form class="mb-5 flex gap-2" @submit.prevent="apply">
                <input
                    v-model="search"
                    aria-label="Cari nama atau unit"
                    placeholder="Cari nama atau unit..."
                    maxlength="150"
                    class="min-w-0 flex-1 rounded-xl border border-emerald-200 bg-white px-3 py-2"
                /><button class="rounded-xl bg-emerald-800 px-4 text-white">Cari</button>
            </form>
            <p class="mb-3 text-sm">{{ requests.total }} pengajuan</p>
            <Link
                v-for="item in requests.data"
                :key="item.id"
                :href="`/absensi/mobile/presensi/persetujuan/${item.id}`"
                class="mb-3 block rounded-2xl border border-emerald-100 bg-white p-5 shadow-sm"
            >
                <div class="flex items-center justify-between gap-2">
                    <strong>{{ item.name }}</strong
                    ><span class="rounded-full bg-slate-100 px-3 py-1 text-xs">{{ labels[item.status] }}</span>
                </div>
                <p class="mt-2 text-sm text-slate-600">{{ item.unit }} · {{ item.action === 'clock-in' ? 'Clock-in' : 'Clock-out' }}</p>
                <p class="mt-2 text-sm">{{ item.recorded_at }} WIB</p>
                <p v-if="item.current_level" class="mt-2 text-xs text-amber-800">Tingkat {{ item.current_level }} dari {{ item.approvals.length }}</p>
            </Link>
            <p v-if="!requests.data.length" class="rounded-2xl bg-white p-8 text-center text-slate-500">
                Belum ada pengajuan presensi di bagian ini.
            </p>
            <nav class="mt-6 flex justify-between">
                <Link v-if="requests.prev_page_url" :href="requests.prev_page_url">&larr; Sebelumnya</Link
                ><Link v-if="requests.next_page_url" :href="requests.next_page_url" class="ml-auto">Berikutnya &rarr;</Link>
            </nav>
        </div>
    </main>
</template>
