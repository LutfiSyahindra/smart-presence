<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Link } from '@inertiajs/vue3';
import { BadgeCheck, Check, HeartPulse, ShieldCheck, Sparkles } from 'lucide-vue-next';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="auth-premium-shell">
        <div class="auth-premium-grid" aria-hidden="true"></div>
        <section class="auth-story-panel">
            <Link :href="route('home')" class="auth-brand">
                <span class="auth-brand-logo"><AppLogoIcon /></span>
                <span><strong>RSARSY</strong><small>Smart Presence</small></span>
            </Link>

            <div class="auth-story-copy">
                <span class="auth-story-chip"><Sparkles /> Workspace terpercaya</span>
                <h2>Operasional yang rapi,<br /><span>dimulai dari sini.</span></h2>
                <p>Akses satu ruang kerja terintegrasi untuk menjalankan aktivitas harian dengan cepat, aman, dan terukur.</p>
                <div class="auth-benefits">
                    <span
                        ><i><Check /></i> Akses berbasis role</span
                    >
                    <span
                        ><i><Check /></i> Perlindungan data berlapis</span
                    >
                    <span
                        ><i><Check /></i> Pengalaman responsif</span
                    >
                </div>
            </div>

            <div class="auth-security-card">
                <span class="auth-security-icon"><ShieldCheck /></span>
                <div><strong>Sesi Anda terlindungi</strong><small>Autentikasi aman RSARSY</small></div>
                <BadgeCheck class="auth-security-check" />
            </div>

            <div class="auth-orb auth-orb-one" aria-hidden="true"></div>
            <div class="auth-orb auth-orb-two" aria-hidden="true"></div>
            <HeartPulse class="auth-watermark" aria-hidden="true" />
        </section>

        <main class="auth-form-panel">
            <Link :href="route('home')" class="auth-mobile-brand">
                <span><AppLogoIcon /></span>
                RSARSY Presence
            </Link>

            <div class="auth-form-wrap">
                <div class="auth-form-heading">
                    <span class="auth-form-icon"><ShieldCheck /></span>
                    <h1 v-if="title">{{ title }}</h1>
                    <p v-if="description">{{ description }}</p>
                </div>
                <div class="auth-form-content"><slot /></div>
                <p class="auth-privacy"><ShieldCheck /> Akses aman &amp; data terenkripsi</p>
            </div>
        </main>
    </div>
</template>

<style scoped>
.auth-premium-shell {
    min-height: 100svh;
    display: grid;
    grid-template-columns: minmax(430px, 0.92fr) 1.08fr;
    position: relative;
    overflow: hidden;
    background: #f8fafc;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
}

.auth-premium-grid {
    position: absolute;
    inset: 0 0 0 42%;
    pointer-events: none;
    opacity: 0.45;
    background-image:
        linear-gradient(rgba(99, 102, 241, 0.06) 1px, transparent 1px), linear-gradient(90deg, rgba(99, 102, 241, 0.06) 1px, transparent 1px);
    background-size: 42px 42px;
    mask-image: radial-gradient(circle at center, black, transparent 75%);
}

.auth-story-panel {
    min-height: 100svh;
    padding: 44px clamp(36px, 5vw, 78px);
    display: flex;
    flex-direction: column;
    position: relative;
    isolation: isolate;
    overflow: hidden;
    color: #26324b;
    background:
        radial-gradient(circle at 8% 5%, rgba(129, 140, 248, 0.2), transparent 30%),
        radial-gradient(circle at 95% 85%, rgba(34, 211, 238, 0.18), transparent 32%), linear-gradient(145deg, #f7f8ff, #edf2ff 55%, #e9faf8);
}

.auth-brand {
    display: inline-flex;
    align-items: center;
    gap: 11px;
    width: fit-content;
    color: #2c3751;
    text-decoration: none;
    position: relative;
    z-index: 2;
}
.auth-brand-logo {
    width: 43px;
    height: 43px;
    display: grid;
    place-items: center;
    border: 1px solid #dce2f3;
    border-radius: 14px;
    color: #6668df;
    background: rgba(255, 255, 255, 0.82);
    box-shadow: 0 9px 22px rgba(99, 102, 241, 0.1);
    backdrop-filter: blur(10px);
}
.auth-brand-logo :deep(svg) {
    width: 29px;
    height: 29px;
}
.auth-brand > span:last-child {
    display: flex;
    flex-direction: column;
    line-height: 1;
}
.auth-brand strong {
    font-size: 17px;
    letter-spacing: 0.08em;
}
.auth-brand small {
    margin-top: 6px;
    color: #7d88a1;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.auth-story-copy {
    max-width: 490px;
    margin: auto 0;
    position: relative;
    z-index: 2;
}
.auth-story-chip {
    width: fit-content;
    padding: 8px 11px;
    display: flex;
    align-items: center;
    gap: 7px;
    border: 1px solid #dde3f3;
    border-radius: 999px;
    color: #6466cf;
    background: rgba(255, 255, 255, 0.74);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
}
.auth-story-chip svg {
    width: 13px;
}
.auth-story-copy h2 {
    margin: 24px 0 18px;
    font-size: clamp(36px, 4vw, 56px);
    font-weight: 800;
    line-height: 1.12;
    letter-spacing: -0.045em;
    color: #29344d;
}
.auth-story-copy h2 span {
    color: #6264d8;
}
.auth-story-copy > p {
    max-width: 470px;
    margin: 0;
    color: #68758c;
    font-size: 14px;
    line-height: 1.8;
}
.auth-benefits {
    margin-top: 30px;
    display: flex;
    flex-direction: column;
    gap: 13px;
}
.auth-benefits > span {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #526078;
    font-size: 11px;
    font-weight: 700;
}
.auth-benefits i {
    width: 22px;
    height: 22px;
    display: grid;
    place-items: center;
    border-radius: 7px;
    color: #0d9488;
    background: #dcf7f2;
}
.auth-benefits svg {
    width: 12px;
    stroke-width: 3;
}
.auth-security-card {
    width: fit-content;
    min-width: 285px;
    padding: 13px 15px;
    display: flex;
    align-items: center;
    gap: 11px;
    border: 1px solid #dce5ef;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.8);
    box-shadow: 0 16px 45px rgba(76, 81, 153, 0.1);
    backdrop-filter: blur(14px);
    position: relative;
    z-index: 2;
}
.auth-security-icon {
    width: 37px;
    height: 37px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    color: #6466db;
    background: #edf0ff;
}
.auth-security-icon svg {
    width: 17px;
}
.auth-security-card > div {
    display: flex;
    flex: 1;
    flex-direction: column;
}
.auth-security-card strong {
    color: #354158;
    font-size: 10px;
}
.auth-security-card small {
    margin-top: 3px;
    color: #8995a8;
    font-size: 8px;
}
.auth-security-check {
    width: 17px;
    color: #13a394;
}
.auth-orb {
    position: absolute;
    z-index: -1;
    border-radius: 50%;
    filter: blur(2px);
}
.auth-orb-one {
    width: 360px;
    height: 360px;
    right: -180px;
    top: 22%;
    border: 1px solid rgba(99, 102, 241, 0.1);
    box-shadow:
        0 0 0 55px rgba(99, 102, 241, 0.025),
        0 0 0 110px rgba(99, 102, 241, 0.018);
}
.auth-orb-two {
    width: 160px;
    height: 160px;
    left: -80px;
    bottom: 12%;
    background: rgba(129, 140, 248, 0.14);
    filter: blur(40px);
}
.auth-watermark {
    width: 250px;
    height: 250px;
    position: absolute;
    right: -35px;
    bottom: -45px;
    color: #7779dc;
    opacity: 0.07;
}

.auth-form-panel {
    min-height: 100svh;
    padding: 42px;
    display: grid;
    place-items: center;
    position: relative;
    z-index: 1;
}
.auth-form-wrap {
    width: 100%;
    max-width: 430px;
    padding: 42px;
    border: 1px solid rgba(226, 232, 240, 0.9);
    border-radius: 26px;
    background: rgba(255, 255, 255, 0.88);
    box-shadow:
        0 30px 80px rgba(30, 41, 59, 0.11),
        0 2px 8px rgba(30, 41, 59, 0.04);
    backdrop-filter: blur(18px);
}
.auth-form-heading {
    margin-bottom: 30px;
    text-align: center;
}
.auth-form-icon {
    width: 52px;
    height: 52px;
    margin: 0 auto 20px;
    display: grid;
    place-items: center;
    border-radius: 16px;
    color: #fff;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    box-shadow: 0 12px 25px rgba(79, 70, 229, 0.25);
}
.auth-form-icon svg {
    width: 22px;
}
.auth-form-heading h1 {
    margin: 0;
    color: #0f172a;
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.025em;
}
.auth-form-heading p {
    max-width: 320px;
    margin: 9px auto 0;
    color: #64748b;
    font-size: 12px;
    line-height: 1.65;
}
.auth-form-content :deep(label) {
    color: #334155;
    font-size: 11px;
    font-weight: 700;
}
.auth-form-content :deep(input[type='text']),
.auth-form-content :deep(input[type='email']),
.auth-form-content :deep(input[type='password']) {
    height: 48px;
    border-color: #dfe4ed;
    border-radius: 13px;
    background: #fbfcfe;
    padding-left: 14px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.02);
    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease,
        background 0.2s ease;
}
.auth-form-content :deep(input:focus) {
    border-color: #818cf8;
    background: #fff;
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    outline: none;
    --tw-ring-shadow: none;
}
.auth-form-content :deep(button[type='submit']) {
    min-height: 48px;
    border-radius: 13px;
    background: linear-gradient(135deg, #4f46e5, #6d5dfc);
    box-shadow: 0 10px 22px rgba(79, 70, 229, 0.2);
    font-weight: 800;
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease;
}
.auth-form-content :deep(button[type='submit']:hover) {
    transform: translateY(-1px);
    box-shadow: 0 14px 28px rgba(79, 70, 229, 0.27);
}
.auth-form-content :deep(a) {
    color: #4f46e5;
    font-weight: 700;
}
.auth-privacy {
    margin: 26px 0 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    color: #94a3b8;
    font-size: 9px;
    font-weight: 600;
}
.auth-privacy svg {
    width: 12px;
}
.auth-mobile-brand {
    display: none;
}

@media (max-width: 900px) {
    .auth-premium-shell {
        display: block;
    }
    .auth-story-panel {
        display: none;
    }
    .auth-form-panel {
        padding: 90px 24px 35px;
    }
    .auth-mobile-brand {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        color: #1e293b;
        font-size: 13px;
        font-weight: 800;
        text-decoration: none;
        position: absolute;
        top: 28px;
        left: 50%;
        transform: translateX(-50%);
        white-space: nowrap;
    }
    .auth-mobile-brand > span {
        width: 31px;
        height: 31px;
        display: grid;
        place-items: center;
        border-radius: 10px;
        color: #fff;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
    }
    .auth-mobile-brand svg {
        width: 23px;
    }
}

@media (max-width: 520px) {
    .auth-form-panel {
        padding: 82px 14px 20px;
        align-items: start;
    }
    .auth-form-wrap {
        padding: 30px 22px;
        border-radius: 21px;
    }
    .auth-form-icon {
        width: 46px;
        height: 46px;
        margin-bottom: 16px;
    }
    .auth-form-heading {
        margin-bottom: 25px;
    }
}
</style>
