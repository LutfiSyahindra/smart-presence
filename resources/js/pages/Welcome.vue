<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, BadgeCheck, BarChart3, Check, ChevronRight, Clock3, Fingerprint, ShieldCheck, Sparkles, UsersRound } from 'lucide-vue-next';
</script>

<template>
    <Head title="Smart Presence">
        <meta head-key="description" name="description" content="Sistem kehadiran dan manajemen akses terintegrasi untuk RSARSY." />
    </Head>

    <div class="welcome-shell">
        <div class="welcome-grid" aria-hidden="true"></div>
        <div class="welcome-glow welcome-glow-one" aria-hidden="true"></div>
        <div class="welcome-glow welcome-glow-two" aria-hidden="true"></div>

        <header class="welcome-header">
            <Link :href="route('home')" class="welcome-brand" aria-label="RSARSY Presence">
                <span class="welcome-logo"><AppLogoIcon variant="full" /></span>
            </Link>

            <nav class="welcome-nav" aria-label="Navigasi halaman">
                <a href="#keunggulan">Keunggulan</a>
                <a href="#keamanan">Keamanan</a>
            </nav>

            <div class="welcome-auth-actions">
                <Link v-if="$page.props.auth.user" :href="route('dashboard')" class="welcome-primary-button"> Buka dashboard <ArrowRight /> </Link>
                <template v-else>
                    <Link :href="route('login')" class="welcome-login-link">Masuk</Link>
                    <Link :href="route('register')" class="welcome-primary-button">Mulai sekarang <ArrowRight /></Link>
                </template>
            </div>
        </header>

        <main>
            <section class="welcome-hero">
                <div class="welcome-hero-copy">
                    <div class="welcome-chip"><Sparkles /> Platform operasional RSARSY</div>
                    <h1>Kehadiran lebih tertib.<br /><span>Operasional lebih cerdas.</span></h1>
                    <p>
                        Satu ruang kerja aman untuk mengelola pengguna, kewenangan, dan aktivitas kehadiran—dirancang agar tim bekerja lebih cepat
                        dengan informasi yang selalu jelas.
                    </p>
                    <div class="welcome-hero-actions">
                        <Link :href="$page.props.auth.user ? route('dashboard') : route('login')" class="welcome-hero-primary">
                            {{ $page.props.auth.user ? 'Masuk ke workspace' : 'Masuk ke sistem' }} <ArrowRight />
                        </Link>
                        <a href="#keunggulan" class="welcome-hero-secondary">Pelajari fitur <ChevronRight /></a>
                    </div>
                    <div class="welcome-trust">
                        <span><Check /> Role-based access</span>
                        <span><Check /> Audit-ready</span>
                        <span><Check /> Responsif di semua perangkat</span>
                    </div>
                </div>

                <div class="welcome-product-wrap" aria-label="Pratinjau dashboard RSARSY Presence">
                    <div class="welcome-floating-card welcome-floating-top">
                        <span><ShieldCheck /></span>
                        <div><strong>Akses terlindungi</strong><small>Role &amp; permission aktif</small></div>
                    </div>
                    <div class="welcome-product">
                        <div class="welcome-product-bar">
                            <span class="welcome-product-brand"><AppLogoIcon /> RSARSY</span>
                            <div class="welcome-window-dots"><i></i><i></i><i></i></div>
                        </div>
                        <div class="welcome-product-body">
                            <aside class="welcome-product-sidebar">
                                <span class="active"><BarChart3 /></span>
                                <span><UsersRound /></span>
                                <span><ShieldCheck /></span>
                                <span><Fingerprint /></span>
                            </aside>
                            <div class="welcome-product-content">
                                <div class="welcome-preview-heading">
                                    <div><small>Dashboard</small><strong>Selamat datang kembali.</strong></div>
                                    <span><Clock3 /> 08:24 WIB</span>
                                </div>
                                <div class="welcome-preview-hero">
                                    <span>Sistem operasional</span>
                                    <strong>Semua dalam kendali.</strong>
                                    <small>Pantau aktivitas dan kelola akses dari satu tempat.</small>
                                </div>
                                <div class="welcome-preview-stats">
                                    <div>
                                        <span class="indigo"><UsersRound /></span><small>Pengguna</small><strong>128</strong>
                                    </div>
                                    <div>
                                        <span class="cyan"><ShieldCheck /></span><small>Role</small><strong>12</strong>
                                    </div>
                                    <div>
                                        <span class="violet"><Fingerprint /></span><small>Akses</small><strong>24</strong>
                                    </div>
                                </div>
                                <div class="welcome-preview-bottom">
                                    <div class="welcome-preview-chart">
                                        <span>Aktivitas sistem</span>
                                        <div class="welcome-bars"><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
                                    </div>
                                    <div class="welcome-preview-security"><BadgeCheck /><strong>100%</strong><small>Proteksi aktif</small></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="welcome-floating-card welcome-floating-bottom">
                        <span class="success"><BadgeCheck /></span>
                        <div><strong>Sistem siap</strong><small>Seluruh layanan terhubung</small></div>
                    </div>
                </div>
            </section>

            <section id="keunggulan" class="welcome-features">
                <div class="welcome-section-heading">
                    <span>Dirancang untuk fokus</span>
                    <h2>Semua yang dibutuhkan tim,<br />tanpa kerumitan.</h2>
                    <p>Informasi penting tampil lebih dahulu, tindakan rutin hanya berjarak satu klik.</p>
                </div>
                <div class="welcome-feature-grid">
                    <article>
                        <span class="indigo"><UsersRound /></span>
                        <h3>Manajemen terpusat</h3>
                        <p>Kelola pengguna dan struktur akses dalam satu alur kerja yang ringkas.</p>
                        <small>Lebih efisien <ArrowRight /></small>
                    </article>
                    <article id="keamanan">
                        <span class="cyan"><ShieldCheck /></span>
                        <h3>Kontrol yang presisi</h3>
                        <p>Role dan permission memastikan setiap orang hanya melihat yang diperlukan.</p>
                        <small>Lebih aman <ArrowRight /></small>
                    </article>
                    <article>
                        <span class="violet"><BarChart3 /></span>
                        <h3>Insight yang jelas</h3>
                        <p>Ringkasan informatif membantu keputusan operasional dibuat lebih cepat.</p>
                        <small>Lebih tanggap <ArrowRight /></small>
                    </article>
                </div>
            </section>
        </main>

        <footer class="welcome-footer">
            <span>&copy; {{ new Date().getFullYear() }} RSARSY Presence</span>
            <span><ShieldCheck /> Sistem operasional yang aman dan terpercaya</span>
        </footer>
    </div>
</template>

<style scoped>
.welcome-shell {
    --welcome-ink: #0f172a;
    min-height: 100vh;
    overflow: hidden;
    position: relative;
    background: #f8fafc;
    color: var(--welcome-ink);
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
}

.welcome-grid {
    position: absolute;
    inset: 0 0 auto;
    height: 760px;
    opacity: 0.36;
    background-image:
        linear-gradient(rgba(99, 102, 241, 0.08) 1px, transparent 1px), linear-gradient(90deg, rgba(99, 102, 241, 0.08) 1px, transparent 1px);
    background-size: 44px 44px;
    mask-image: linear-gradient(to bottom, black, transparent 90%);
}

.welcome-glow {
    position: absolute;
    border-radius: 999px;
    filter: blur(2px);
    pointer-events: none;
}
.welcome-glow-one {
    width: 560px;
    height: 560px;
    top: -240px;
    left: -230px;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.18), transparent 68%);
}
.welcome-glow-two {
    width: 620px;
    height: 620px;
    right: -280px;
    top: 140px;
    background: radial-gradient(circle, rgba(6, 182, 212, 0.13), transparent 68%);
}

.welcome-header {
    height: 82px;
    max-width: 1240px;
    margin: 0 auto;
    padding: 0 28px;
    display: flex;
    align-items: center;
    position: relative;
    z-index: 5;
}

.welcome-brand {
    display: inline-flex;
    align-items: center;
    gap: 11px;
    color: var(--welcome-ink);
    text-decoration: none;
}
.welcome-logo {
    width: 76px;
    height: 76px;
    display: grid;
    place-items: center;
}
.welcome-logo :deep(img) {
    width: 76px;
    height: 76px;
}
.welcome-brand > span:last-child {
    display: flex;
    flex-direction: column;
    line-height: 1;
}
.welcome-brand strong {
    font-size: 16px;
    letter-spacing: 0.08em;
}
.welcome-brand small {
    margin-top: 6px;
    color: #64748b;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}
.welcome-nav {
    display: flex;
    gap: 36px;
    margin: 0 auto;
}
.welcome-nav a,
.welcome-login-link {
    color: #475569;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s ease;
}
.welcome-nav a:hover,
.welcome-login-link:hover {
    color: #4f46e5;
}
.welcome-auth-actions {
    display: flex;
    align-items: center;
    gap: 22px;
}
.welcome-primary-button,
.welcome-hero-primary,
.welcome-hero-secondary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    font-weight: 700;
    transition:
        transform 0.2s ease,
        box-shadow 0.2s ease,
        background 0.2s ease;
}
.welcome-primary-button {
    min-height: 42px;
    padding: 0 18px;
    gap: 9px;
    border-radius: 13px;
    background: linear-gradient(135deg, #6d70e8, #8b7be7);
    color: #fff;
    font-size: 12px;
    box-shadow: 0 8px 22px rgba(99, 102, 241, 0.18);
}
.welcome-primary-button svg {
    width: 15px;
}
.welcome-primary-button:hover,
.welcome-hero-primary:hover {
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 14px 28px rgba(79, 70, 229, 0.28);
}

.welcome-hero {
    max-width: 1240px;
    min-height: 665px;
    margin: 0 auto;
    padding: 70px 28px 90px;
    display: grid;
    grid-template-columns: 0.9fr 1.1fr;
    align-items: center;
    gap: 72px;
    position: relative;
    z-index: 2;
}
.welcome-chip {
    width: fit-content;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border: 1px solid rgba(99, 102, 241, 0.18);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.72);
    color: #4f46e5;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    box-shadow: 0 8px 30px rgba(99, 102, 241, 0.07);
    backdrop-filter: blur(12px);
}
.welcome-chip svg {
    width: 14px;
}
.welcome-hero-copy h1 {
    margin: 24px 0 22px;
    color: #111827;
    font-size: clamp(42px, 4.5vw, 67px);
    font-weight: 800;
    line-height: 1.09;
    letter-spacing: -0.045em;
}
.welcome-hero-copy h1 span {
    background: linear-gradient(100deg, #4f46e5, #7c3aed 50%, #0891b2);
    background-clip: text;
    -webkit-background-clip: text;
    color: transparent;
}
.welcome-hero-copy > p {
    max-width: 610px;
    margin: 0;
    color: #64748b;
    font-size: 16px;
    line-height: 1.8;
}
.welcome-hero-actions {
    display: flex;
    align-items: center;
    gap: 13px;
    margin-top: 32px;
}
.welcome-hero-primary,
.welcome-hero-secondary {
    min-height: 50px;
    padding: 0 22px;
    gap: 10px;
    border-radius: 15px;
    font-size: 13px;
}
.welcome-hero-primary {
    color: #fff;
    background: linear-gradient(135deg, #4f46e5, #6d5dfc);
    box-shadow: 0 12px 28px rgba(79, 70, 229, 0.23);
}
.welcome-hero-secondary {
    border: 1px solid #e2e8f0;
    color: #334155;
    background: rgba(255, 255, 255, 0.75);
}
.welcome-hero-secondary:hover {
    color: #4f46e5;
    border-color: #c7d2fe;
    background: #fff;
    transform: translateY(-2px);
}
.welcome-hero-actions svg {
    width: 16px;
}
.welcome-trust {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    margin-top: 27px;
    color: #64748b;
    font-size: 10px;
    font-weight: 700;
}
.welcome-trust span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.welcome-trust svg {
    width: 13px;
    color: #10b981;
    stroke-width: 3;
}

.welcome-product-wrap {
    position: relative;
    perspective: 1200px;
}
.welcome-product {
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.9);
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.9);
    box-shadow:
        0 35px 90px rgba(30, 41, 59, 0.18),
        0 0 0 1px rgba(148, 163, 184, 0.13);
    transform: rotateY(-3deg) rotateX(1deg);
    backdrop-filter: blur(18px);
}
.welcome-product-bar {
    height: 48px;
    padding: 0 17px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #edf0f5;
}
.welcome-product-brand {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #312e81;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.06em;
}
.welcome-product-brand :deep(img) {
    width: 22px;
    height: 22px;
}
.welcome-window-dots {
    display: flex;
    gap: 5px;
}
.welcome-window-dots i {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #cbd5e1;
}
.welcome-product-body {
    min-height: 410px;
    display: flex;
}
.welcome-product-sidebar {
    width: 62px;
    padding: 21px 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    border-right: 1px solid #edf0f5;
    background: #fbfcff;
}
.welcome-product-sidebar span {
    width: 35px;
    height: 35px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    color: #94a3b8;
}
.welcome-product-sidebar span.active {
    color: #fff;
    background: linear-gradient(135deg, #4f46e5, #7c3aed);
    box-shadow: 0 8px 16px rgba(99, 102, 241, 0.24);
}
.welcome-product-sidebar svg {
    width: 15px;
}
.welcome-product-content {
    flex: 1;
    padding: 24px;
    background: #f8fafc;
}
.welcome-preview-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 18px;
}
.welcome-preview-heading > div {
    display: flex;
    flex-direction: column;
}
.welcome-preview-heading small {
    color: #94a3b8;
    font-size: 8px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
}
.welcome-preview-heading strong {
    margin-top: 4px;
    color: #1e293b;
    font-size: 14px;
}
.welcome-preview-heading > span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 9px;
    border: 1px solid #e2e8f0;
    border-radius: 9px;
    background: #fff;
    color: #64748b;
    font-size: 8px;
    font-weight: 700;
}
.welcome-preview-heading svg {
    width: 11px;
}
.welcome-preview-hero {
    min-height: 105px;
    padding: 19px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    border-radius: 17px;
    border: 1px solid #dce5f3;
    color: #2d3853;
    background: radial-gradient(circle at 90% 0, rgba(34, 211, 238, 0.2), transparent 38%), linear-gradient(135deg, #f3f5ff, #eaf8f7);
    box-shadow: 0 12px 24px rgba(76, 81, 153, 0.09);
}
.welcome-preview-hero span {
    width: fit-content;
    padding: 4px 7px;
    border-radius: 999px;
    color: #5d61c9;
    background: rgba(255, 255, 255, 0.72);
    font-size: 7px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
.welcome-preview-hero strong {
    margin: 10px 0 4px;
    font-size: 17px;
}
.welcome-preview-hero small {
    color: #718096;
    font-size: 8px;
}
.welcome-preview-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin: 12px 0;
}
.welcome-preview-stats > div {
    padding: 12px;
    display: grid;
    grid-template-columns: auto 1fr;
    grid-template-rows: auto auto;
    align-items: center;
    column-gap: 8px;
    border: 1px solid #edf0f5;
    border-radius: 13px;
    background: #fff;
}
.welcome-preview-stats > div > span {
    grid-row: 1 / 3;
    width: 28px;
    height: 28px;
    display: grid;
    place-items: center;
    border-radius: 9px;
}
.welcome-preview-stats svg {
    width: 13px;
}
.welcome-preview-stats small {
    color: #94a3b8;
    font-size: 7px;
}
.welcome-preview-stats strong {
    color: #1e293b;
    font-size: 13px;
}
.indigo {
    color: #4f46e5;
    background: #eef2ff;
}
.cyan {
    color: #0891b2;
    background: #ecfeff;
}
.violet {
    color: #7c3aed;
    background: #f5f3ff;
}
.welcome-preview-bottom {
    display: grid;
    grid-template-columns: 1.6fr 0.8fr;
    gap: 10px;
}
.welcome-preview-chart,
.welcome-preview-security {
    min-height: 82px;
    padding: 13px;
    border: 1px solid #edf0f5;
    border-radius: 13px;
    background: #fff;
}
.welcome-preview-chart > span {
    color: #64748b;
    font-size: 8px;
    font-weight: 700;
}
.welcome-bars {
    height: 43px;
    display: flex;
    align-items: end;
    gap: 7px;
    margin-top: 6px;
}
.welcome-bars i {
    flex: 1;
    min-height: 20%;
    border-radius: 4px 4px 2px 2px;
    background: linear-gradient(to top, #6366f1, #a5b4fc);
}
.welcome-bars i:nth-child(2) {
    height: 46%;
}
.welcome-bars i:nth-child(3) {
    height: 70%;
}
.welcome-bars i:nth-child(4) {
    height: 53%;
}
.welcome-bars i:nth-child(5) {
    height: 88%;
}
.welcome-bars i:nth-child(6) {
    height: 67%;
}
.welcome-bars i:nth-child(7) {
    height: 100%;
}
.welcome-preview-security {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}
.welcome-preview-security svg {
    width: 17px;
    color: #10b981;
}
.welcome-preview-security strong {
    margin-top: 3px;
    color: #1e293b;
    font-size: 14px;
}
.welcome-preview-security small {
    color: #94a3b8;
    font-size: 7px;
}
.welcome-floating-card {
    position: absolute;
    z-index: 3;
    min-width: 195px;
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid rgba(255, 255, 255, 0.92);
    border-radius: 15px;
    background: rgba(255, 255, 255, 0.9);
    box-shadow: 0 16px 40px rgba(30, 41, 59, 0.16);
    backdrop-filter: blur(16px);
    animation: welcome-float 4s ease-in-out infinite;
}
.welcome-floating-card > span {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    color: #4f46e5;
    background: #eef2ff;
}
.welcome-floating-card > span.success {
    color: #059669;
    background: #ecfdf5;
}
.welcome-floating-card svg {
    width: 16px;
}
.welcome-floating-card div {
    display: flex;
    flex-direction: column;
}
.welcome-floating-card strong {
    color: #1e293b;
    font-size: 10px;
}
.welcome-floating-card small {
    margin-top: 3px;
    color: #94a3b8;
    font-size: 8px;
}
.welcome-floating-top {
    top: -25px;
    right: -22px;
}
.welcome-floating-bottom {
    bottom: -22px;
    left: -36px;
    animation-delay: -2s;
}
@keyframes welcome-float {
    50% {
        transform: translateY(-7px);
    }
}

.welcome-features {
    max-width: 1184px;
    margin: 0 auto 90px;
    padding: 85px 28px;
    border-radius: 34px;
    background: #fff;
    box-shadow: 0 24px 70px rgba(30, 41, 59, 0.07);
    position: relative;
    z-index: 2;
}
.welcome-section-heading {
    max-width: 650px;
    margin: 0 auto 48px;
    text-align: center;
}
.welcome-section-heading > span {
    color: #4f46e5;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.13em;
    text-transform: uppercase;
}
.welcome-section-heading h2 {
    margin: 12px 0 14px;
    color: #111827;
    font-size: clamp(29px, 3vw, 42px);
    font-weight: 800;
    line-height: 1.2;
    letter-spacing: -0.035em;
}
.welcome-section-heading p {
    margin: 0;
    color: #64748b;
    font-size: 14px;
    line-height: 1.7;
}
.welcome-feature-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
}
.welcome-feature-grid article {
    padding: 26px;
    border: 1px solid #edf0f5;
    border-radius: 20px;
    background: linear-gradient(145deg, #fff, #fbfcff);
    transition:
        transform 0.25s ease,
        box-shadow 0.25s ease,
        border-color 0.25s ease;
}
.welcome-feature-grid article:hover {
    transform: translateY(-6px);
    border-color: #dfe3ff;
    box-shadow: 0 18px 35px rgba(30, 41, 59, 0.09);
}
.welcome-feature-grid article > span {
    width: 46px;
    height: 46px;
    display: grid;
    place-items: center;
    border-radius: 14px;
}
.welcome-feature-grid svg {
    width: 20px;
}
.welcome-feature-grid h3 {
    margin: 20px 0 9px;
    color: #1e293b;
    font-size: 16px;
    font-weight: 800;
}
.welcome-feature-grid p {
    min-height: 63px;
    margin: 0;
    color: #64748b;
    font-size: 12px;
    line-height: 1.7;
}
.welcome-feature-grid small {
    margin-top: 18px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #4f46e5;
    font-size: 10px;
    font-weight: 800;
}
.welcome-feature-grid small svg {
    width: 13px;
}
.welcome-footer {
    max-width: 1240px;
    margin: 0 auto;
    padding: 28px;
    display: flex;
    justify-content: space-between;
    border-top: 1px solid #e2e8f0;
    color: #94a3b8;
    font-size: 10px;
    font-weight: 600;
    position: relative;
    z-index: 2;
}
.welcome-footer span {
    display: flex;
    align-items: center;
    gap: 7px;
}
.welcome-footer svg {
    width: 14px;
}

@media (max-width: 991px) {
    .welcome-nav {
        display: none;
    }
    .welcome-auth-actions {
        margin-left: auto;
    }
    .welcome-hero {
        grid-template-columns: 1fr;
        padding-top: 55px;
        text-align: center;
    }
    .welcome-chip,
    .welcome-hero-copy > p {
        margin-left: auto;
        margin-right: auto;
    }
    .welcome-hero-actions,
    .welcome-trust {
        justify-content: center;
    }
    .welcome-product-wrap {
        max-width: 680px;
        margin: 10px auto 0;
        width: 100%;
    }
    .welcome-product {
        transform: none;
    }
}

@media (max-width: 680px) {
    .welcome-header {
        height: 72px;
        padding: 0 18px;
    }
    .welcome-brand small,
    .welcome-login-link {
        display: none;
    }
    .welcome-primary-button {
        padding: 0 13px;
    }
    .welcome-hero {
        padding: 48px 18px 78px;
        gap: 55px;
    }
    .welcome-hero-copy h1 {
        font-size: 39px;
    }
    .welcome-hero-copy > p {
        font-size: 14px;
    }
    .welcome-hero-actions {
        flex-direction: column;
        align-items: stretch;
    }
    .welcome-trust {
        gap: 10px;
    }
    .welcome-product-body {
        min-height: 330px;
    }
    .welcome-product-sidebar {
        display: none;
    }
    .welcome-product-content {
        padding: 15px;
    }
    .welcome-preview-stats {
        gap: 6px;
    }
    .welcome-preview-stats > div {
        padding: 8px;
        grid-template-columns: 1fr;
    }
    .welcome-preview-stats > div > span {
        display: none;
    }
    .welcome-floating-card {
        display: none;
    }
    .welcome-features {
        margin: 0 12px 50px;
        padding: 58px 18px;
        border-radius: 25px;
    }
    .welcome-feature-grid {
        grid-template-columns: 1fr;
    }
    .welcome-feature-grid p {
        min-height: 0;
    }
    .welcome-footer {
        margin: 0 18px;
        padding: 25px 0;
        flex-direction: column;
        align-items: center;
        gap: 10px;
        text-align: center;
    }
}

@media (prefers-reduced-motion: reduce) {
    .welcome-floating-card {
        animation: none;
    }
    .welcome-feature-grid article,
    .welcome-primary-button,
    .welcome-hero-primary,
    .welcome-hero-secondary {
        transition: none;
    }
}
</style>
