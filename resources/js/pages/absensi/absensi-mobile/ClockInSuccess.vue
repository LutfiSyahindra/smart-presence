<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Building2, CalendarDays, Check, Clock3, Home, MapPin, ShieldCheck, Sparkles, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    attendance: {
        date: string | null;
        date_label: string | null;
        clock_in_time: string;
        shift_name: string | null;
        start_time: string | null;
        end_time: string | null;
        location_name: string | null;
        location_address: string | null;
        outside_radius: boolean;
        face_verified: boolean;
        face_similarity: number | null;
    };
}>();

const faceVerificationLabel = computed(() => {
    if (!props.attendance.face_verified) return 'Verifikasi tersimpan';
    if (props.attendance.face_similarity === null) return 'Wajah berhasil diverifikasi';

    return `Kecocokan ${Math.round(props.attendance.face_similarity)}%`;
});
</script>

<template>
    <Head title="Clock In Berhasil" />

    <main class="success-workspace">
        <div class="success-shell">
            <span class="ambient ambient-one" aria-hidden="true"></span>
            <span class="ambient ambient-two" aria-hidden="true"></span>

            <header class="success-header">
                <Link :href="route('absensi.mobile.home')" class="close-button" aria-label="Kembali ke beranda"><X /></Link>
                <div class="brand-mark">
                    <span>RSARSY</span>
                    <small>Presence</small>
                </div>
                <span class="secure-mark" aria-label="Data presensi tersimpan aman"><ShieldCheck /></span>
            </header>

            <section class="success-content" aria-labelledby="success-title">
                <div class="success-visual" aria-hidden="true">
                    <span class="success-halo halo-one"></span>
                    <span class="success-halo halo-two"></span>
                    <span class="sparkle sparkle-one"><Sparkles /></span>
                    <span class="sparkle sparkle-two"><Sparkles /></span>
                    <div class="success-check"><Check /></div>
                </div>

                <span class="success-eyebrow"><i></i> Presensi berhasil dikirim</span>
                <h1 id="success-title">Jam masuk berhasil</h1>
                <p class="success-lead">Selamat bekerja. Kehadiran Anda telah tercatat aman di sistem RSARSY.</p>

                <article class="attendance-card">
                    <span class="card-glow" aria-hidden="true"></span>
                    <div class="attendance-date">
                        <span><CalendarDays /></span>
                        <div>
                            <small>Jadwal hari ini</small>
                            <strong>{{ attendance.date_label || 'Hari ini' }}</strong>
                        </div>
                        <i>Tercatat</i>
                    </div>

                    <div class="clock-in-time">
                        <small>Waktu clock in</small>
                        <time>{{ attendance.clock_in_time }}</time>
                        <span>WIB</span>
                    </div>

                    <div class="shift-summary">
                        <span><Building2 /></span>
                        <div>
                            <small>{{ attendance.shift_name || 'Jadwal kerja' }}</small>
                            <strong>{{ attendance.start_time || '--:--' }} — {{ attendance.end_time || '--:--' }} WIB</strong>
                        </div>
                        <Clock3 />
                    </div>
                </article>

                <div class="verification-grid">
                    <article>
                        <span class="detail-icon face"><ShieldCheck /></span>
                        <div>
                            <small>Verifikasi wajah</small>
                            <strong>Berhasil</strong>
                            <p>{{ faceVerificationLabel }}</p>
                        </div>
                    </article>
                    <article :class="{ warning: attendance.outside_radius }">
                        <span class="detail-icon location"><MapPin /></span>
                        <div>
                            <small>{{ attendance.outside_radius ? 'Lokasi dengan catatan' : 'Lokasi terverifikasi' }}</small>
                            <strong>{{ attendance.location_name || 'Lokasi kerja' }}</strong>
                            <p>{{ attendance.location_address || 'Koordinat presensi tersimpan' }}</p>
                        </div>
                    </article>
                </div>

                <div class="delivery-note">
                    <span><Check /></span>
                    <p><strong>Semua selesai</strong>Data akan langsung muncul pada ringkasan kehadiran hari ini.</p>
                </div>
            </section>

            <footer class="success-actions">
                <Link :href="route('absensi.mobile.home')" class="home-action"> <Home /> <span>Kembali ke beranda</span> <ArrowRight /> </Link>
                <Link :href="route('absensi.mobile.home')" class="summary-action">Lihat ringkasan presensi</Link>
            </footer>
        </div>
    </main>
</template>

<style scoped>
.success-workspace {
    --success-ink: #143639;
    --success-muted: #768886;
    --success-teal: #087d74;
    min-height: 100svh;
    color: var(--success-ink);
    background:
        radial-gradient(circle at 12% 8%, rgba(34, 143, 126, 0.13), transparent 25rem),
        radial-gradient(circle at 92% 90%, rgba(211, 165, 84, 0.14), transparent 24rem), #eaf1ee;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
}

.success-shell {
    width: 100%;
    max-width: 500px;
    min-height: 100svh;
    margin: 0 auto;
    display: flex;
    overflow: hidden;
    position: relative;
    flex-direction: column;
    background: linear-gradient(180deg, #f8fbfa 0%, #fff 44%, #f6faf8 100%);
    box-shadow: 0 0 80px rgba(18, 55, 54, 0.14);
}

.ambient {
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
    filter: blur(1px);
}

.ambient-one {
    width: 230px;
    height: 230px;
    top: -150px;
    right: -85px;
    background: rgba(25, 151, 132, 0.09);
}

.ambient-two {
    width: 190px;
    height: 190px;
    bottom: 90px;
    left: -145px;
    background: rgba(213, 169, 91, 0.1);
}

.success-header {
    min-height: 70px;
    padding: max(15px, env(safe-area-inset-top)) 18px 8px;
    display: grid;
    align-items: center;
    position: relative;
    z-index: 2;
    grid-template-columns: 42px 1fr 42px;
}

.close-button,
.secure-mark {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border: 1px solid #e1eae6;
    border-radius: 14px;
    color: #526c6c;
    background: rgba(255, 255, 255, 0.84);
    box-shadow: 0 8px 22px rgba(20, 62, 59, 0.06);
}

.close-button {
    transition: 160ms ease;
}

.close-button:hover {
    color: var(--success-teal);
    transform: translateY(-1px);
}

.close-button svg,
.secure-mark svg {
    width: 18px;
    height: 18px;
}

.secure-mark {
    color: #a1783b;
    background: #fff9ed;
}

.brand-mark {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
}

.brand-mark span {
    color: #173d40;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 0.15em;
}

.brand-mark small {
    margin-top: 1px;
    color: #aa8248;
    font-size: 7px;
    font-weight: 800;
    letter-spacing: 0.22em;
    text-transform: uppercase;
}

.success-content {
    padding: 5px 18px 18px;
    position: relative;
    z-index: 1;
    flex: 1;
}

.success-visual {
    width: 142px;
    height: 142px;
    margin: 3px auto 0;
    display: grid;
    place-items: center;
    position: relative;
}

.success-halo {
    position: absolute;
    border-radius: 50%;
}

.halo-one {
    inset: 1px;
    border: 1px solid rgba(17, 145, 124, 0.11);
    background: rgba(224, 246, 239, 0.72);
    animation: halo-in 700ms ease-out both;
}

.halo-two {
    inset: 17px;
    border: 1px solid rgba(255, 255, 255, 0.85);
    background: #eef9f5;
    box-shadow: 0 18px 38px rgba(9, 100, 89, 0.11);
}

.success-check {
    width: 86px;
    height: 86px;
    display: grid;
    place-items: center;
    position: relative;
    z-index: 2;
    border: 1px solid rgba(255, 255, 255, 0.36);
    border-radius: 30px;
    color: #fff;
    background: linear-gradient(145deg, #1aaa8f, #05746d 78%);
    box-shadow:
        0 19px 34px rgba(6, 116, 103, 0.26),
        inset 0 1px rgba(255, 255, 255, 0.25);
    transform: rotate(-3deg);
    animation: check-in 520ms cubic-bezier(0.22, 0.9, 0.28, 1.2) both;
}

.success-check svg {
    width: 44px;
    height: 44px;
    stroke-width: 2.6;
    transform: rotate(3deg);
}

.sparkle {
    position: absolute;
    z-index: 3;
    color: #d3a554;
}

.sparkle svg {
    width: 19px;
    height: 19px;
    fill: currentColor;
    stroke-width: 1.3;
}

.sparkle-one {
    top: 17px;
    right: 3px;
}

.sparkle-two {
    bottom: 14px;
    left: 7px;
    color: #24a58f;
    transform: scale(0.65);
}

.success-eyebrow {
    width: fit-content;
    margin: 5px auto 0;
    padding: 6px 10px;
    display: flex;
    align-items: center;
    gap: 6px;
    border: 1px solid #d7ebe4;
    border-radius: 999px;
    color: #08796f;
    background: #edf8f4;
    font-size: 7px;
    font-weight: 850;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.success-eyebrow i {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #23a78e;
    box-shadow: 0 0 0 4px rgba(35, 167, 142, 0.12);
}

.success-content > h1 {
    margin: 11px 0 0;
    color: #143639;
    font-size: clamp(27px, 8vw, 35px);
    font-weight: 900;
    letter-spacing: -0.055em;
    line-height: 1.05;
    text-align: center;
}

.success-lead {
    max-width: 350px;
    margin: 9px auto 0;
    color: var(--success-muted);
    font-size: 10px;
    line-height: 1.65;
    text-align: center;
}

.attendance-card {
    margin-top: 21px;
    padding: 14px;
    overflow: hidden;
    position: relative;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 25px;
    color: #f3fffb;
    background: linear-gradient(145deg, #0b353a, #0b5552 70%, #12675f);
    box-shadow: 0 20px 42px rgba(8, 57, 56, 0.2);
}

.card-glow {
    width: 150px;
    height: 150px;
    position: absolute;
    right: -73px;
    bottom: -94px;
    border-radius: 50%;
    background: rgba(224, 184, 111, 0.15);
}

.attendance-date {
    display: flex;
    align-items: center;
    gap: 9px;
    position: relative;
    z-index: 1;
}

.attendance-date > span,
.shift-summary > span {
    width: 35px;
    height: 35px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 11px;
    color: #edca8b;
    background: rgba(3, 29, 32, 0.24);
}

.attendance-date svg,
.shift-summary svg {
    width: 15px;
    height: 15px;
}

.attendance-date > div,
.shift-summary > div {
    min-width: 0;
    display: flex;
    flex: 1;
    flex-direction: column;
}

.attendance-date small,
.shift-summary small {
    color: rgba(205, 234, 227, 0.62);
    font-size: 7px;
}

.attendance-date strong,
.shift-summary strong {
    margin-top: 2px;
    overflow: hidden;
    font-size: 9px;
    font-weight: 760;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.attendance-date > i {
    padding: 5px 8px;
    border: 1px solid rgba(126, 223, 195, 0.17);
    border-radius: 999px;
    color: #8ce2c8;
    background: rgba(4, 36, 38, 0.25);
    font-size: 7px;
    font-style: normal;
    font-weight: 800;
}

.clock-in-time {
    margin: 17px 0 16px;
    padding: 14px 0 13px;
    display: flex;
    align-items: baseline;
    justify-content: center;
    position: relative;
    border-block: 1px solid rgba(255, 255, 255, 0.09);
}

.clock-in-time small {
    position: absolute;
    top: 7px;
    left: 0;
    color: rgba(203, 231, 225, 0.58);
    font-size: 7px;
}

.clock-in-time time {
    font-size: 47px;
    font-weight: 900;
    letter-spacing: -0.06em;
    line-height: 1;
}

.clock-in-time > span {
    margin-left: 7px;
    color: #e7c17d;
    font-size: 9px;
    font-weight: 800;
}

.shift-summary {
    display: flex;
    align-items: center;
    gap: 9px;
    position: relative;
    z-index: 1;
}

.shift-summary > svg {
    color: rgba(211, 233, 227, 0.46);
}

.verification-grid {
    margin-top: 12px;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 9px;
}

.verification-grid article {
    min-width: 0;
    padding: 11px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    border: 1px solid #e0eae6;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.9);
    box-shadow: 0 9px 22px rgba(25, 62, 60, 0.05);
}

.detail-icon {
    width: 30px;
    height: 30px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 10px;
}

.detail-icon.face {
    color: #087d74;
    background: #e4f4ef;
}

.detail-icon.location {
    color: #9a7133;
    background: #fff3dc;
}

.detail-icon svg {
    width: 14px;
    height: 14px;
}

.verification-grid article > div {
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.verification-grid small {
    color: #879693;
    font-size: 6px;
    font-weight: 700;
}

.verification-grid strong {
    margin-top: 2px;
    overflow: hidden;
    font-size: 9px;
    font-weight: 830;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.verification-grid p {
    margin: 2px 0 0;
    overflow: hidden;
    color: #98a4a2;
    font-size: 6px;
    line-height: 1.4;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.delivery-note {
    margin-top: 11px;
    padding: 10px 11px;
    display: flex;
    align-items: center;
    gap: 9px;
    border: 1px solid #dfeae6;
    border-radius: 15px;
    background: #f8fbfa;
}

.delivery-note > span {
    width: 27px;
    height: 27px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 50%;
    color: #fff;
    background: #15917f;
}

.delivery-note svg {
    width: 13px;
    height: 13px;
    stroke-width: 2.7;
}

.delivery-note p {
    margin: 0;
    display: flex;
    color: #82918f;
    flex-direction: column;
    font-size: 7px;
    line-height: 1.45;
}

.delivery-note strong {
    color: #365657;
    font-size: 8px;
    font-weight: 820;
}

.success-actions {
    padding: 11px 18px max(17px, env(safe-area-inset-bottom));
    position: sticky;
    z-index: 5;
    bottom: 0;
    background: linear-gradient(180deg, rgba(248, 251, 250, 0), rgba(248, 251, 250, 0.96) 25%);
    backdrop-filter: blur(10px);
}

.home-action {
    min-height: 54px;
    padding: 0 17px;
    display: grid;
    align-items: center;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 17px;
    color: #fff;
    background: linear-gradient(135deg, #0b8178, #07524f);
    box-shadow: 0 15px 28px rgba(6, 92, 87, 0.23);
    grid-template-columns: 18px 1fr 18px;
    font-size: 11px;
    font-weight: 820;
    transition: 160ms ease;
}

.home-action:hover {
    transform: translateY(-1px);
    box-shadow: 0 18px 32px rgba(6, 92, 87, 0.27);
}

.home-action svg {
    width: 16px;
    height: 16px;
}

.home-action span {
    text-align: center;
}

.summary-action {
    min-height: 35px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #758784;
    font-size: 8px;
    font-weight: 720;
}

@keyframes check-in {
    from {
        opacity: 0;
        transform: scale(0.62) rotate(-10deg);
    }
    to {
        opacity: 1;
        transform: scale(1) rotate(-3deg);
    }
}

@keyframes halo-in {
    from {
        opacity: 0;
        transform: scale(0.7);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

@media (min-width: 700px) {
    .success-shell {
        margin-block: 22px;
        min-height: calc(100svh - 44px);
        border: 1px solid rgba(255, 255, 255, 0.75);
        border-radius: 32px;
    }
}

@media (max-height: 760px) {
    .success-visual {
        width: 118px;
        height: 118px;
    }

    .success-check {
        width: 72px;
        height: 72px;
        border-radius: 25px;
    }

    .success-check svg {
        width: 37px;
        height: 37px;
    }

    .success-content > h1 {
        font-size: 27px;
    }

    .attendance-card {
        margin-top: 15px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .success-check,
    .halo-one {
        animation: none;
    }
}
</style>
