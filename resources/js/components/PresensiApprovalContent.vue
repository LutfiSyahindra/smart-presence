<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, MapPin, X } from 'lucide-vue-next';
export interface PresensiApprovalItem {
    id: number;
    name: string;
    position: string;
    unit: string;
    date: string;
    action: string;
    recorded_at: string;
    reason: string;
    status: string;
    current_level: number | null;
    can_decide: boolean;
    location: { name: string | null; radius_meters: number | null; latitude: number; longitude: number; accuracy: number | null; address: string };
    approvals: { level: number; name: string; status: string; note: string | null; decided_at: string | null }[];
}
const props = defineProps<{ item: PresensiApprovalItem; base: string }>();
const labels: Record<string, string> = {
    pending: 'Menunggu persetujuan',
    waiting: 'Menunggu giliran',
    approved: 'Disetujui',
    rejected: 'Ditolak',
    skipped: 'Dilewati',
};
const form = useForm({ decision: 'approved', note: '' });
function decide(decision: 'approved' | 'rejected') {
    if (!props.item.can_decide || form.processing) return;
    form.clearErrors();
    if (decision === 'rejected' && !form.note.trim()) {
        form.setError('note', 'Alasan penolakan wajib diisi.');
        return;
    }
    form.decision = decision;
    form.transform((data) => ({ ...data, note: data.note.trim() })).post(`${props.base}/${props.item.id}/decision`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>
<template>
    <article class="presensi-review">
        <header>
            <span class="eyebrow"><MapPin :size="18" /> PRESENSI-{{ item.id }}</span>
            <h1>Presensi di luar radius</h1>
            <p>{{ item.name }} · {{ item.unit }} · {{ item.position }}</p>
            <strong class="status" :class="item.status">{{ labels[item.status] }}</strong>
        </header>
        <div class="review-grid">
            <section>
                <h2>Bukti presensi</h2>
                <strong>{{ item.action === 'clock-in' ? 'Clock-in' : 'Clock-out' }} · {{ item.recorded_at }} WIB</strong>
                <p>Tanggal shift: {{ item.date }}</p>
                <p>{{ item.location.address }}</p>
                <p>{{ item.location.name }} · Radius {{ item.location.radius_meters }} m · Akurasi GPS {{ item.location.accuracy }} m</p>
                <a
                    :href="`https://www.google.com/maps?q=${item.location.latitude},${item.location.longitude}`"
                    target="_blank"
                    rel="noopener noreferrer"
                    >Lihat koordinat lokasi</a
                >
                <img :src="`${base}/${item.id}/photo`" alt="Foto verifikasi wajah saat presensi" loading="lazy" />
                <h2>Alasan di luar radius</h2>
                <p class="reason">{{ item.reason }}</p>
                <p class="hint">
                    Waktu dan bukti tetap tersimpan. Persetujuan berlaku untuk tindakan presensi ini setelah seluruh approver menyetujui.
                </p>
            </section>
            <section>
                <h2>Progres persetujuan</h2>
                <ol>
                    <li v-for="step in item.approvals" :key="step.level">
                        <span class="step" :class="step.status"
                            ><Check v-if="step.status === 'approved'" :size="16" /><X v-else-if="step.status === 'rejected'" :size="16" /><template
                                v-else
                                >{{ step.level }}</template
                            ></span
                        >
                        <div>
                            <strong>{{ step.name }}</strong>
                            <p>Tingkat {{ step.level }} · {{ labels[step.status] }}</p>
                            <small v-if="step.decided_at">{{ step.decided_at }} WIB</small>
                            <p v-if="step.note" class="reason">{{ step.note }}</p>
                        </div>
                    </li>
                </ol>
            </section>
        </div>
        <form v-if="item.can_decide" @submit.prevent="decide('approved')">
            <h2>Persetujuan tingkat {{ item.current_level }}</h2>
            <label for="presensi-note">Catatan (wajib untuk penolakan)</label
            ><textarea id="presensi-note" v-model="form.note" rows="3" maxlength="1000" :disabled="form.processing" />
            <p v-for="(error, key) in form.errors" :key="key" class="error" role="alert">{{ error }}</p>
            <div class="actions">
                <button type="button" class="reject" :disabled="form.processing" @click="decide('rejected')">Tolak</button
                ><button type="submit" :disabled="form.processing">{{ form.processing ? 'Menyimpan...' : 'Setujui' }}</button>
            </div>
        </form>
    </article>
</template>
<style scoped>
.presensi-review {
    max-width: 1050px;
    margin: 0 auto;
    color: #183a35;
}
header,
section,
form {
    background: #fff;
    border: 1px solid #dfebe5;
    border-radius: 18px;
    padding: 24px;
}
header {
    margin-bottom: 18px;
}
h1 {
    font-size: 26px;
    margin: 12px 0;
    font-weight: 750;
}
h2 {
    font-size: 17px;
    margin: 0 0 14px;
    font-weight: 700;
}
p {
    margin: 10px 0;
    line-height: 1.6;
}
.eyebrow {
    display: flex;
    gap: 8px;
    align-items: center;
    font-size: 12px;
    font-weight: 700;
}
.review-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-bottom: 18px;
}
.status {
    display: inline-block;
    padding: 7px 13px;
    border-radius: 20px;
    background: #fff3cd;
    color: #856404;
    font-size: 13px;
}
.approved {
    background: #dff3e7;
    color: #12623a;
}
.rejected {
    background: #fde7e9;
    color: #a61c32;
}
a {
    color: #087a65;
    text-decoration: underline;
}
img {
    display: block;
    max-width: 100%;
    width: 220px;
    max-height: 260px;
    object-fit: contain;
    margin: 20px 0;
    border-radius: 12px;
}
.reason {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
.hint {
    color: #64776f;
    font-size: 13px;
}
ol {
    list-style: none;
    padding: 0;
    margin: 0;
}
li {
    display: flex;
    gap: 12px;
    padding: 16px 0;
    border-bottom: 1px solid #edf2ee;
}
li p {
    margin: 5px 0;
    font-size: 13px;
}
.step {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    flex-shrink: 0;
    border-radius: 50%;
    background: #edf3ef;
}
label {
    display: block;
    margin-bottom: 8px;
}
textarea {
    width: 100%;
    border: 1px solid #b6cbbf;
    border-radius: 10px;
    padding: 12px;
    color: #183a35;
}
.actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 16px;
}
button {
    background: #087a65;
    color: white;
    border: 0;
    padding: 11px 24px;
    border-radius: 10px;
    font-weight: 700;
    cursor: pointer;
}
button.reject {
    background: #fde7e9;
    color: #a61c32;
}
button:disabled {
    opacity: 0.5;
    cursor: wait;
}
.error {
    color: #b42338;
}
@media (max-width: 700px) {
    .review-grid {
        grid-template-columns: 1fr;
    }
    header,
    section,
    form {
        padding: 20px;
    }
    h1 {
        font-size: 23px;
    }
}
</style>
