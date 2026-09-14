<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CalendarClock, ChevronRight, Clock3, LogOut, ShieldCheck, Smartphone } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{
    canAccessAdmin: boolean;
}>();

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);
const firstName = computed(() => user.value.name.trim().split(/\s+/)[0]);
</script>

<template>
    <Head title="Aplikasi Presensi" />

    <main class="min-h-svh bg-slate-50 text-slate-900">
        <div class="mx-auto flex min-h-svh w-full max-w-md flex-col bg-white shadow-xl shadow-slate-200/60">
            <header class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-2xl bg-indigo-600 text-white shadow-lg shadow-indigo-200">
                        <AppLogoIcon class="size-6" />
                    </span>
                    <div>
                        <strong class="block text-sm font-extrabold tracking-tight">RSARSY Presence</strong>
                        <span class="text-xs text-slate-500">Portal presensi pegawai</span>
                    </div>
                </div>

                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="grid size-10 place-items-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-600"
                    aria-label="Keluar"
                >
                    <LogOut class="size-4" />
                </Link>
            </header>

            <section class="flex flex-1 flex-col px-5 py-6">
                <div
                    class="overflow-hidden rounded-3xl bg-gradient-to-br from-indigo-600 via-indigo-600 to-cyan-500 p-6 text-white shadow-xl shadow-indigo-200"
                >
                    <div class="mb-8 flex items-start justify-between">
                        <span class="rounded-full bg-white/15 px-3 py-1 text-xs font-semibold backdrop-blur">Fondasi mobile aktif</span>
                        <Smartphone class="size-6 text-white/80" />
                    </div>
                    <p class="text-sm text-indigo-100">Selamat datang,</p>
                    <h1 class="mt-1 text-2xl font-extrabold tracking-tight">{{ firstName }}</h1>
                    <p class="mt-3 max-w-xs text-sm leading-6 text-indigo-100">
                        Anda berada di aplikasi presensi. Fitur check-in, jadwal, dan riwayat akan ditempatkan di portal ini.
                    </p>
                </div>

                <div class="mt-6 grid grid-cols-2 gap-3">
                    <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <span class="grid size-9 place-items-center rounded-xl bg-indigo-100 text-indigo-600"><Clock3 class="size-4" /></span>
                        <strong class="mt-3 block text-sm">Presensi</strong>
                        <small class="mt-1 block text-slate-500">Segera tersedia</small>
                    </article>
                    <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <span class="grid size-9 place-items-center rounded-xl bg-cyan-100 text-cyan-600"><CalendarClock class="size-4" /></span>
                        <strong class="mt-3 block text-sm">Jadwal kerja</strong>
                        <small class="mt-1 block text-slate-500">Segera tersedia</small>
                    </article>
                </div>

                <div class="mt-auto pt-8">
                    <Link
                        v-if="canAccessAdmin"
                        :href="route('portal.switch', { portal: 'admin' })"
                        method="post"
                        as="button"
                        class="flex w-full items-center gap-3 rounded-2xl border border-slate-200 px-4 py-3 text-left transition hover:border-indigo-200 hover:bg-indigo-50"
                    >
                        <span class="grid size-10 place-items-center rounded-xl bg-slate-900 text-white"><ShieldCheck class="size-5" /></span>
                        <span class="flex-1">
                            <strong class="block text-sm">Buka portal admin</strong>
                            <small class="text-slate-500">Akses secara sengaja saat dibutuhkan</small>
                        </span>
                        <ChevronRight class="size-4 text-slate-400" />
                    </Link>

                    <p class="mt-5 text-center text-xs leading-5 text-slate-400">
                        Portal ini disiapkan sebagai entry point Capacitor, PWA, dan presensi melalui browser.
                    </p>
                </div>
            </section>
        </div>
    </main>
</template>
