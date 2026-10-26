<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { CalendarCheck2, Fingerprint, MapPin, ShieldCheck } from 'lucide-vue-next';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="presensi-login">
        <div class="login-container">
            <div class="login-story">
                <header class="login-brand">
                    <span class="login-brand-mark"><AppLogoIcon /></span>
                    <div>
                        <strong>ARSY <span>Presensi</span></strong>
                        <small>RS ABDURRAHMAN SYAMSURI</small>
                    </div>
                </header>

                <section class="login-intro" aria-labelledby="login-welcome">
                    <span class="login-eyebrow"><span></span> Hadir untuk melayani</span>
                    <h2 id="login-welcome">Awali hari dengan<br /><em>langkah terbaik.</em></h2>
                    <p>Catat kehadiran, jalani hari dengan tenang.<br />Semua dalam satu genggaman.</p>

                    <div class="login-features">
                        <div>
                            <span><CalendarCheck2 /></span>
                            <p><strong>Presensi lebih praktis</strong><small>Akses kehadiran harian dengan mudah.</small></p>
                        </div>
                        <div>
                            <span><MapPin /></span>
                            <p><strong>Tepat di lokasi Anda</strong><small>Verifikasi lokasi langsung dari perangkat.</small></p>
                        </div>
                        <div>
                            <span><Fingerprint /></span>
                            <p><strong>Personal dan terlindungi</strong><small>Verifikasi wajah untuk setiap kehadiran.</small></p>
                        </div>
                    </div>

                    <span class="login-intro-note"><ShieldCheck /> Terhubung dengan portal presensi RSARSY</span>
                </section>
            </div>

            <div class="login-content">
                <main class="login-card">
                    <div class="login-card-heading">
                        <span class="login-card-eyebrow"><span></span> Akun pegawai</span>
                        <h1 v-if="title">{{ title }}</h1>
                        <p v-if="description">{{ description }}</p>
                    </div>

                    <slot />

                    <div class="login-card-footer"><ShieldCheck /><span>Akses pribadi untuk pegawai RSARSY</span></div>
                </main>

                <footer class="login-footer">Melayani dengan hati, hadir sepenuh arti.</footer>
            </div>
        </div>
    </div>
</template>

<style scoped>
.presensi-login {
    --login-green: #087f78;
    --login-ink: #173238;
    --login-muted: #667c7e;
    min-height: 100svh;
    min-height: 100dvh;
    position: relative;
    isolation: isolate;
    overflow-x: clip;
    color: var(--login-ink);
    background: #f5f8f5;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
    color-scheme: light;
}

.login-story {
    position: relative;
    isolation: isolate;
    display: flex;
    flex-direction: column;
    min-width: 0;
    padding: 36px;
    overflow: hidden;
    border-radius: 28px;
    background: radial-gradient(ellipse at 10% 0, #246964 0, transparent 65%), linear-gradient(150deg, #164f4f, #0d3539);
}

.login-story::before,
.login-story::after {
    content: '';
    width: 440px;
    height: 440px;
    position: absolute;
    z-index: -1;
    right: -270px;
    top: 10%;
    border: 1px solid rgb(228 189 123 / 20%);
    border-radius: 50%;
    box-shadow:
        0 0 0 55px rgb(228 189 123 / 4%),
        0 0 0 110px rgb(228 189 123 / 3%);
}

.login-story::after {
    width: 250px;
    height: 250px;
    top: auto;
    right: auto;
    left: -180px;
    bottom: -100px;
}

.login-container {
    width: min(100%, 1120px);
    min-height: 100svh;
    min-height: 100dvh;
    margin: 0 auto;
    padding: max(28px, env(safe-area-inset-top)) max(28px, env(safe-area-inset-right)) max(28px, env(safe-area-inset-bottom))
        max(28px, env(safe-area-inset-left));
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(320px, 0.95fr);
    gap: clamp(32px, 5vw, 64px);
}

.login-content {
    min-width: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 24px;
    padding-block: 24px;
}

.login-brand {
    display: flex;
    align-items: center;
    gap: 13px;
    color: #fff;
}

.login-brand-mark {
    width: 56px;
    height: 56px;
    padding: 7px;
    display: grid;
    flex-shrink: 0;
    place-items: center;
    border: 1px solid rgb(255 255 255 / 75%);
    border-radius: 17px;
    background: #fff;
    box-shadow: 0 6px 20px rgb(0 0 0 / 8%);
}

.login-brand-mark :deep(img) {
    width: 100%;
    height: 100%;
}

.login-brand > div {
    min-width: 0;
}

.login-brand strong,
.login-brand small {
    display: block;
}

.login-brand strong {
    font-size: 21px;
    font-weight: 800;
    letter-spacing: -0.8px;
}

.login-brand strong > span {
    font-weight: 500;
}

.login-brand small {
    margin-top: 3px;
    color: #bdd5d0;
    font-size: 12px;
    letter-spacing: 0.3px;
    line-height: 1.5;
}

.login-intro {
    margin-block: auto;
    padding-block: 54px 28px;
    color: #fff;
}

.login-eyebrow,
.login-card-eyebrow {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #e4bd7b;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1.8px;
    text-transform: uppercase;
}

.login-eyebrow > span,
.login-card-eyebrow > span {
    width: 6px;
    height: 6px;
    flex-shrink: 0;
    border-radius: 50%;
    background: currentColor;
    box-shadow: 0 0 0 4px rgb(228 189 123 / 10%);
}

.login-intro h2 {
    margin: 22px 0 18px;
    color: #fff;
    font-size: clamp(32px, 3.2vw, 42px);
    font-weight: 700;
    line-height: 1.25;
    letter-spacing: -1.7px;
}

.login-intro h2 em {
    color: #e4bd7b;
    font-style: normal;
}

.login-intro > p {
    margin: 0;
    color: #c2d9d4;
    font-size: 14px;
    line-height: 1.85;
}

.login-features {
    margin-top: 38px;
    display: grid;
    gap: 20px;
}

.login-features > div {
    display: flex;
    align-items: center;
    gap: 13px;
}

.login-features > div > span {
    width: 42px;
    height: 42px;
    display: grid;
    flex-shrink: 0;
    place-items: center;
    border: 1px solid rgb(189 213 208 / 17%);
    border-radius: 13px;
    color: #acd8cc;
    background: rgb(255 255 255 / 5%);
}

.login-features svg {
    width: 19px;
    height: 19px;
    stroke-width: 1.6;
}

.login-features p {
    margin: 0;
}

.login-features strong,
.login-features small {
    display: block;
}

.login-features strong {
    font-size: 13px;
    font-weight: 600;
}

.login-features small {
    margin-top: 4px;
    color: #bdd5d0;
    font-size: 12px;
    line-height: 1.5;
}

.login-intro-note {
    margin-top: 40px;
    display: flex;
    align-items: center;
    gap: 7px;
    color: #bdd5d0;
    font-size: 11px;
    line-height: 1.6;
}

.login-intro-note svg,
.login-card-footer svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
}

.login-card {
    width: 100%;
    flex: 0 0 auto;
    min-width: 0;
    padding: 38px 32px 0;
    overflow: hidden;
    border: 1px solid #e5ece9;
    border-radius: 28px;
    background: #fff;
    box-shadow:
        0 24px 70px rgb(13 53 57 / 12%),
        0 4px 12px rgb(13 53 57 / 3%);
}

.login-card-heading {
    margin-bottom: 28px;
}

.login-card-eyebrow {
    color: var(--login-green);
    font-size: 10px;
    letter-spacing: 1.5px;
}

.login-card-eyebrow > span {
    box-shadow: 0 0 0 4px #e8f5f1;
}

.login-card-heading h1 {
    margin: 17px 0 9px;
    color: var(--login-ink);
    font-size: 26px;
    font-weight: 800;
    line-height: 1.35;
    letter-spacing: -0.9px;
}

.login-card-heading > p {
    margin: 0;
    color: var(--login-muted);
    font-size: 14px;
    line-height: 1.65;
}

.login-card-footer {
    margin: 28px -32px 0;
    padding: 17px 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-top: 1px solid #edf3f0;
    color: #627b76;
    background: #fafcfb;
    font-size: 12px;
    line-height: 1.6;
}

.login-card-footer svg {
    color: var(--login-green);
}

.login-footer {
    color: #667c7e;
    font-size: 12px;
    letter-spacing: 0.2px;
    line-height: 1.6;
    text-align: center;
}

@media (max-width: 960px) {
    .login-container {
        width: 100%;
        padding: 0;
        display: flex;
        flex-direction: column;
        justify-content: flex-start;
        gap: 0;
    }

    .login-story {
        flex: 0 0 auto;
        padding: calc(32px + env(safe-area-inset-top)) max(24px, env(safe-area-inset-right)) 64px max(24px, env(safe-area-inset-left));
        border-radius: 0 0 32px 32px;
    }

    .login-story::before {
        width: 300px;
        height: 300px;
        right: -230px;
        top: 30px;
    }

    .login-content {
        width: min(100%, 480px);
        margin: -32px auto 0;
        padding: 0 max(20px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(20px, env(safe-area-inset-left));
        position: relative;
        z-index: 1;
        flex: 1 0 auto;
        justify-content: flex-start;
        gap: 20px;
    }

    .login-brand {
        justify-content: center;
        gap: 13px;
    }

    .login-brand-mark {
        width: 58px;
        height: 58px;
        border-radius: 17px;
        padding: 7px;
    }

    .login-brand strong {
        font-size: 23px;
    }

    .login-brand small {
        font-size: 11px;
    }

    .login-intro {
        display: none;
    }

    .login-card {
        width: 100%;
        padding: 28px 24px 0;
        border-radius: 24px;
    }

    .login-card-heading {
        margin-bottom: 24px;
    }

    .login-card-heading h1 {
        margin-top: 14px;
        font-size: 24px;
    }

    .login-card-footer {
        margin: 24px -24px 0;
        padding: 15px 16px;
        font-size: 11px;
    }

    .login-footer {
        margin-top: auto;
        padding-inline: 8px;
        font-size: 11px;
    }
}

@media (max-width: 380px) {
    .login-content {
        padding-right: max(16px, env(safe-area-inset-right));
        padding-left: max(16px, env(safe-area-inset-left));
    }

    .login-card {
        padding-right: 20px;
        padding-left: 20px;
    }

    .login-card-heading h1 {
        font-size: 22px;
    }

    .login-card-footer {
        margin-right: -20px;
        margin-left: -20px;
    }
}

@media (max-width: 960px) and (max-height: 600px) {
    .login-story {
        padding-top: calc(16px + env(safe-area-inset-top));
        padding-bottom: 48px;
    }

    .login-brand-mark {
        width: 46px;
        height: 46px;
        border-radius: 14px;
    }

    .login-content {
        gap: 16px;
    }

    .login-card {
        padding-top: 22px;
    }

    .login-card-heading {
        margin-bottom: 20px;
    }
}
</style>
