<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Head } from '@inertiajs/vue3';
import { ArrowDownToLine, ArrowUpRight, CheckCircle2, Download, Globe2, PlusSquare, Share2, Smartphone } from 'lucide-vue-next';

defineProps<{
    androidApkUrl: string | null;
    status?: string | null;
}>();
</script>

<template>
    <Head title="Install Aplikasi Presensi">
        <meta
            head-key="description"
            name="description"
            content="Unduh APK ARSY Presensi untuk Android atau pasang PWA di iPhone. Ikuti panduan instalasi untuk mulai menggunakan akun Anda."
        />
    </Head>

    <div class="install-shell">
        <header class="install-header">
            <a :href="route('home')" class="install-brand" aria-label="ARSY Presensi, halaman instalasi">
                <AppLogoIcon />
                <span>ARSY <strong>Presensi</strong><small>RSARSY</small></span>
            </a>
            <a href="#pilih-perangkat" class="header-link">Panduan instalasi <ArrowUpRight aria-hidden="true" /></a>
        </header>

        <main class="install-main">
            <div v-if="status" class="activation-status" role="status">
                <CheckCircle2 aria-hidden="true" />
                <p>{{ status }}</p>
            </div>

            <section class="install-intro" aria-labelledby="install-title">
                <span class="intro-label"><Download aria-hidden="true" /> INSTALL APLIKASI</span>
                <h1 id="install-title">Presensi siap<br />di <span>genggaman Anda.</span></h1>
                <p>Pilih perangkat Anda dan ikuti panduan pemasangannya. Setelah terpasang, masuk dengan email dan password akun Anda.</p>
            </section>

            <div id="pilih-perangkat" class="platform-grid">
                <section class="platform-card android-card" aria-labelledby="android-title">
                    <div class="platform-heading">
                        <span class="platform-icon"><Smartphone aria-hidden="true" /></span>
                        <span class="platform-tag">FILE APK</span>
                    </div>
                    <h2 id="android-title">Untuk Android</h2>
                    <p class="platform-description">Unduh dan pasang aplikasi ARSY Presensi langsung di ponsel Android Anda.</p>

                    <a v-if="androidApkUrl" :href="androidApkUrl" class="install-button android-button" download>
                        <ArrowDownToLine aria-hidden="true" /> Download APK Android
                    </a>
                    <button v-else class="install-button" type="button" disabled aria-describedby="apk-unavailable">
                        <ArrowDownToLine aria-hidden="true" /> APK belum tersedia
                    </button>
                    <p v-if="!androidApkUrl" id="apk-unavailable" class="availability-note">
                        Hubungi administrator untuk ketersediaan aplikasi Android.
                    </p>
                    <p v-else class="availability-note">Simpan file APK, lalu buka untuk memulai pemasangan.</p>

                    <div class="install-guide">
                        <h3>Cara memasang APK</h3>
                        <ol class="guide-steps">
                            <li>
                                <span class="step-number">1</span>
                                <div>
                                    <strong>Unduh file APK</strong>
                                    <p>Ketuk Download APK Android dan tunggu hingga unduhan selesai.</p>
                                </div>
                            </li>
                            <li>
                                <span class="step-number">2</span>
                                <div>
                                    <strong>Buka file yang diunduh</strong>
                                    <p>Cari file APK di folder Download atau notifikasi unduhan.</p>
                                </div>
                            </li>
                            <li>
                                <span class="step-number">3</span>
                                <div>
                                    <strong>Izinkan, lalu pasang</strong>
                                    <p>
                                        Jika diminta, izinkan pemasangan dari browser atau pengelola file yang digunakan, lalu ketuk Install / Pasang.
                                    </p>
                                </div>
                            </li>
                            <li>
                                <span class="step-number">4</span>
                                <div>
                                    <strong>Buka ARSY Presensi</strong>
                                    <p>Masuk dengan email dan password yang sudah Anda buat.</p>
                                </div>
                            </li>
                        </ol>
                    </div>
                </section>

                <section class="platform-card ios-card" aria-labelledby="ios-title">
                    <div class="platform-heading">
                        <span class="platform-icon"><Globe2 aria-hidden="true" /></span>
                        <span class="platform-tag">PWA &middot; iOS</span>
                    </div>
                    <h2 id="ios-title">Untuk iPhone</h2>
                    <p class="platform-description">Pasang versi web sebagai aplikasi di Layar Utama iPhone melalui Safari.</p>

                    <a href="#panduan-ios" class="install-button ios-button"><PlusSquare aria-hidden="true" /> Cara install PWA iOS</a>
                    <p class="availability-note">Pemasangan dilakukan melalui menu Bagikan di Safari.</p>

                    <div id="panduan-ios" class="install-guide" tabindex="-1">
                        <h3>Cara memasang PWA</h3>
                        <ol class="guide-steps">
                            <li>
                                <span class="step-number">1</span>
                                <div>
                                    <strong>Buka halaman ini di Safari</strong>
                                    <p>
                                        Gunakan Safari di iPhone. Jika dibuka dari email atau aplikasi lain, buka tautannya di Safari terlebih dahulu.
                                    </p>
                                </div>
                            </li>
                            <li>
                                <span class="step-number">2</span>
                                <div>
                                    <strong>Ketuk Bagikan <Share2 class="inline-icon" aria-hidden="true" /></strong>
                                    <p>Buka menu Safari, lalu pilih Bagikan / Share.</p>
                                </div>
                            </li>
                            <li>
                                <span class="step-number">3</span>
                                <div>
                                    <strong>Tambahkan ke Layar Utama</strong>
                                    <p>
                                        Pilih Add to Home Screen. Jika tersedia, aktifkan Open as Web App / Buka sebagai App Web, lalu ketuk Tambah /
                                        Add.
                                    </p>
                                </div>
                            </li>
                            <li>
                                <span class="step-number">4</span>
                                <div>
                                    <strong>Buka dari ikon aplikasi</strong>
                                    <p>Ketuk ikon ARSY Presensi di Layar Utama, lalu masuk dengan akun Anda.</p>
                                </div>
                            </li>
                        </ol>
                        <a
                            class="apple-guide-link"
                            href="https://support.apple.com/en-lamr/guide/iphone/iphea86e5236/ios"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Panduan Safari dari Apple <ArrowUpRight aria-hidden="true" />
                        </a>
                    </div>
                </section>
            </div>

            <p class="install-reminder">
                <CheckCircle2 aria-hidden="true" /><span
                    >Gunakan akun yang sudah aktif. Saat mulai presensi, izinkan akses kamera dan lokasi jika diminta.</span
                >
            </p>
        </main>

        <footer class="install-footer"><span>ARSY Presensi</span><span>Butuh bantuan pemasangan? Hubungi administrator RSARSY.</span></footer>
    </div>
</template>

<style scoped>
.install-shell {
    min-height: 100svh;
    background: radial-gradient(ellipse at 50% 8%, #e5f3ed 0, transparent 48%), #f6f8f5;
    color: #173238;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
}
.install-header,
.install-main,
.install-footer {
    width: min(1080px, calc(100% - 64px));
    margin-inline: auto;
}
.install-header {
    min-height: 104px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    border-bottom: 1px solid #dfe8e1;
}
.install-brand {
    display: inline-flex;
    align-items: center;
    gap: 11px;
    color: #173238;
    text-decoration: none;
}
.install-brand :deep(img) {
    width: 48px;
    height: 48px;
}
.install-brand > span {
    font-size: 17px;
    font-weight: 800;
    line-height: 1.4;
}
.install-brand strong {
    color: #087f78;
    font-weight: 600;
}
.install-brand small {
    display: block;
    color: #657a75;
    font-size: 10px;
    letter-spacing: 0.18em;
}
.header-link,
.apple-guide-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #246964;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
}
.header-link:hover,
.apple-guide-link:hover {
    text-decoration: underline;
}
.header-link svg,
.apple-guide-link svg {
    width: 15px;
    height: 15px;
}
.install-main {
    padding-block: 32px 40px;
}
.activation-status {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    max-width: 820px;
    margin: 0 auto 28px;
    padding: 18px 22px;
    border: 1px solid #bad8c8;
    border-radius: 14px;
    background: #eaf6ee;
    color: #205d43;
}
.activation-status svg,
.install-reminder svg {
    width: 20px;
    height: 20px;
    flex-shrink: 0;
}
.activation-status p {
    margin: 0;
    font-size: 13px;
    line-height: 1.7;
}
.install-intro {
    max-width: 640px;
    margin: 20px auto 42px;
    text-align: center;
}
.intro-label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 22px;
    color: #246964;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.16em;
}
.intro-label svg {
    width: 15px;
    height: 15px;
}
.install-intro h1 {
    margin: 0 0 20px;
    font-size: clamp(34px, 4.8vw, 54px);
    font-weight: 800;
    line-height: 1.18;
    letter-spacing: -0.045em;
}
.install-intro h1 span {
    color: #087f78;
}
.install-intro > p {
    max-width: 500px;
    margin: 0 auto;
    color: #60736e;
    font-size: 14px;
    line-height: 1.8;
}
.platform-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 24px;
    scroll-margin-top: 24px;
}
.platform-card {
    padding: 30px;
    border: 1px solid #dce7df;
    border-top: 3px solid #087f78;
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 10px 32px #17323805;
}
.ios-card {
    border-top-color: #ad884c;
}
.platform-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 22px;
}
.platform-icon {
    display: grid;
    width: 48px;
    height: 48px;
    place-items: center;
    border-radius: 14px;
    background: #e8f5f1;
    color: #087f78;
}
.ios-card .platform-icon {
    background: #fbf3e6;
    color: #8e682d;
}
.platform-icon svg {
    width: 24px;
    height: 24px;
}
.platform-tag {
    color: #657a75;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.1em;
}
.platform-card h2 {
    margin: 0 0 12px;
    color: #173238;
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.03em;
}
.platform-description {
    min-height: 48px;
    margin: 0 0 22px;
    color: #60736e;
    font-size: 13px;
    line-height: 1.8;
}
.install-button {
    display: flex;
    width: 100%;
    min-height: 50px;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 12px 16px;
    border: 1px solid transparent;
    border-radius: 10px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    line-height: 1.6;
    text-align: center;
    text-decoration: none;
}
.install-button svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}
.android-button {
    background: #087f78;
    color: #fff;
}
.android-button:hover {
    background: #165d5b;
    color: #fff;
}
.ios-button {
    border-color: #d9c9ad;
    background: #fffbf4;
    color: #765724;
}
.ios-button:hover {
    background: #fbf3e6;
    color: #765724;
}
.install-button:disabled {
    border-color: #e1e7e2;
    background: #eef2ef;
    color: #687870;
    cursor: not-allowed;
}
.availability-note {
    min-height: 36px;
    margin: 10px 0 22px;
    color: #657a75;
    font-size: 11px;
    line-height: 1.7;
    text-align: center;
}
.install-guide {
    padding-top: 24px;
    border-top: 1px solid #e7ede8;
    scroll-margin-top: 24px;
}
.install-guide h3 {
    margin: 0 0 22px;
    color: #173238;
    font-size: 13px;
    font-weight: 800;
}
.guide-steps {
    display: grid;
    gap: 22px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.guide-steps li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}
.step-number {
    display: grid;
    width: 25px;
    height: 25px;
    flex-shrink: 0;
    place-items: center;
    border: 1px solid #dce9e0;
    border-radius: 50%;
    background: #f1f7f3;
    color: #246964;
    font-size: 11px;
    font-weight: 800;
}
.ios-card .step-number {
    border-color: #eadfcb;
    background: #fffbf4;
    color: #8e682d;
}
.guide-steps strong {
    display: block;
    margin-bottom: 5px;
    color: #28453f;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.7;
}
.guide-steps p {
    margin: 0;
    color: #657a75;
    font-size: 12px;
    line-height: 1.8;
}
.inline-icon {
    display: inline-block;
    width: 14px;
    height: 14px;
    margin-left: 3px;
    vertical-align: -2px;
}
.apple-guide-link {
    margin: 24px 0 0 37px;
    font-size: 11px;
}
.install-reminder {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin: 28px auto 0;
    color: #60736e;
    font-size: 12px;
    line-height: 1.8;
}
.install-reminder svg {
    color: #087f78;
}
.install-footer {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding-block: 22px;
    border-top: 1px solid #dfe8e1;
    color: #657a75;
    font-size: 11px;
    line-height: 1.8;
}
.install-footer > span:first-child {
    color: #28453f;
    font-weight: 700;
}
a:focus-visible,
button:focus-visible,
.install-guide:focus-visible {
    outline: 3px solid #087f78;
    outline-offset: 5px;
}
@media (max-width: 700px) {
    .install-header,
    .install-main,
    .install-footer {
        width: calc(100% - 36px);
    }
    .install-header {
        min-height: 88px;
        gap: 12px;
    }
    .install-brand > span {
        font-size: 15px;
    }
    .header-link {
        font-size: 11px;
    }
    .install-main {
        padding-top: 24px;
    }
    .install-intro {
        margin: 16px auto 32px;
    }
    .install-intro > p {
        font-size: 13px;
    }
    .platform-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 20px;
    }
    .platform-card {
        padding: 24px;
    }
    .platform-description,
    .availability-note {
        min-height: 0;
    }
    .install-reminder {
        align-items: flex-start;
    }
    .install-footer {
        flex-direction: column;
        gap: 4px;
    }
}
</style>
