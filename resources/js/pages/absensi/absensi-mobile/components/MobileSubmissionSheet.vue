<script setup lang="ts">
import { usePermission } from '@/composables/usePermission';
import { registerMobileBackHandler } from '@/lib/mobile-navigation';
import { Dialog, DialogDescription, DialogPanel, DialogTitle, TransitionChild, TransitionRoot } from '@headlessui/vue';
import { Link } from '@inertiajs/vue3';
import { ArrowRightLeft, CalendarDays, ChevronRight, FilePenLine, MapPin, ReceiptText, Sparkles, Timer, UserRoundPen, X } from 'lucide-vue-next';
import { computed, onUnmounted, ref } from 'vue';

const props = defineProps<{ open: boolean }>();
const emit = defineEmits<{ close: [] }>();
const closeButton = ref<HTMLButtonElement | null>(null);
const { can } = usePermission();
const options = computed(() =>
    [
        { label: 'Reimbursement', description: 'Penggantian biaya pekerjaan', icon: ReceiptText, tone: 'teal' },
        {
            label: 'Cuti',
            description: 'Buat pengajuan cuti baru',
            icon: CalendarDays,
            tone: 'sage',
            href: route('absensi.mobile.cuti.index', { action: 'create' }),
            allowed: can('mobile.cuti.view') && can('mobile.cuti.create'),
        },
        {
            label: 'Absensi',
            description: 'Langsung clock-in atau clock-out',
            icon: MapPin,
            tone: 'blue',
            href: route('absensi.mobile.attendance', { action: 'create' }),
            allowed: can('mobile.attendance') && (can('mobile.clock-in') || can('mobile.clock-out')),
        },
        {
            label: 'Perubahan Shift',
            description: 'Ajukan penyesuaian jadwal kerja',
            icon: ArrowRightLeft,
            tone: 'violet',
            href: route('absensi.mobile.perubahan-shift.index', { action: 'create' }),
        },
        { label: 'Lembur', description: 'Buat pengajuan waktu tambahan', icon: Timer, tone: 'amber', href: route('absensi.mobile.lembur.create') },
        { label: 'Perubahan Data', description: 'Pembaruan informasi pribadi', icon: UserRoundPen, tone: 'rose' },
    ].filter((option) => option.allowed !== false),
);

const removeBackHandler = registerMobileBackHandler(() => {
    if (!props.open) return false;
    emit('close');
    return true;
});
onUnmounted(removeBackHandler);
</script>

<template>
    <TransitionRoot as="template" :show="open">
        <Dialog class="submission-dialog" :initial-focus="closeButton" @close="emit('close')">
            <TransitionChild
                as="template"
                enter="submission-fade-enter"
                enter-from="submission-transparent"
                leave="submission-fade-leave"
                leave-to="submission-transparent"
            >
                <div class="submission-backdrop" aria-hidden="true"></div>
            </TransitionChild>
            <div class="submission-position">
                <TransitionChild
                    as="template"
                    enter="submission-panel-enter"
                    enter-from="submission-panel-hidden"
                    leave="submission-panel-leave"
                    leave-to="submission-panel-hidden"
                >
                    <DialogPanel class="submission-sheet">
                        <header class="submission-header">
                            <span class="submission-handle" aria-hidden="true"></span>
                            <span class="submission-header-art" aria-hidden="true"><FilePenLine /></span>
                            <div class="submission-heading">
                                <span class="submission-eyebrow"><span></span> Layanan pegawai</span>
                                <DialogTitle as="h2">Ajukan untuk</DialogTitle>
                                <DialogDescription>Pilih kebutuhan Anda, mulai dari sini.</DialogDescription>
                            </div>
                            <button ref="closeButton" type="button" class="submission-close" aria-label="Tutup menu pengajuan" @click="emit('close')">
                                <X />
                            </button>
                        </header>

                        <div class="submission-content">
                            <div class="submission-options">
                                <component
                                    :is="option.href ? Link : 'button'"
                                    v-for="option in options"
                                    :key="option.label"
                                    :href="option.href"
                                    :type="option.href ? undefined : 'button'"
                                    :disabled="!option.href"
                                    class="submission-option"
                                    :class="{ 'is-unavailable': !option.href }"
                                    @click="emit('close')"
                                >
                                    <span class="submission-option-icon" :class="`tone-${option.tone}`"
                                        ><component :is="option.icon" aria-hidden="true"
                                    /></span>
                                    <span class="submission-option-copy"
                                        ><strong>{{ option.label }}</strong
                                        ><small>{{ option.description }}</small></span
                                    >
                                    <span v-if="!option.href" class="submission-soon">Segera</span>
                                    <span v-else class="submission-arrow" aria-hidden="true"><ChevronRight /></span>
                                </component>
                            </div>
                            <p class="submission-note"><Sparkles aria-hidden="true" /> Satu tempat untuk kebutuhan kerja Anda.</p>
                        </div>
                    </DialogPanel>
                </TransitionChild>
            </div>
        </Dialog>
    </TransitionRoot>
</template>

<style scoped>
:global(.submission-dialog) {
    position: fixed;
    inset: 0;
    z-index: 100;
    color: #173b3a;
    color-scheme: light;
    font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
}

.submission-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(11, 32, 35, 0.55);
    backdrop-filter: blur(6px);
}

.submission-position {
    position: fixed;
    inset: 0;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    height: 100dvh;
    padding-top: calc(16px + env(safe-area-inset-top, 0px));
}

.submission-sheet {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 500px;
    max-height: 100%;
    min-height: 0;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.4);
    border-bottom: 0;
    border-radius: 30px 30px 0 0;
    background: #f7f9f6;
    box-shadow: 0 -16px 80px rgba(5, 32, 34, 0.24);
}

.submission-header {
    position: relative;
    flex-shrink: 0;
    overflow: hidden;
    padding: 37px 24px 25px;
    background: linear-gradient(120deg, #103b3e, #1c5b54);
}

.submission-handle {
    position: absolute;
    top: 11px;
    left: calc(50% - 18px);
    width: 36px;
    height: 4px;
    border-radius: 9px;
    background: rgba(222, 238, 225, 0.35);
}

.submission-header-art {
    position: absolute;
    right: -17px;
    bottom: -52px;
    display: grid;
    place-items: center;
    width: 180px;
    height: 180px;
    border: 1px solid rgba(228, 189, 123, 0.18);
    border-radius: 50%;
    box-shadow:
        0 0 0 24px rgba(228, 189, 123, 0.04),
        0 0 0 48px rgba(228, 189, 123, 0.025);
    transform: rotate(-15deg);
    pointer-events: none;
}

.submission-header-art svg {
    width: 65px;
    height: 65px;
    color: rgba(228, 189, 123, 0.17);
    stroke-width: 1;
}

.submission-heading {
    position: relative;
    padding-right: 22px;
}

.submission-eyebrow {
    display: flex;
    align-items: center;
    gap: 7px;
    color: #e4bd7b;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.15em;
    text-transform: uppercase;
}

.submission-eyebrow > span {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: currentColor;
    box-shadow: 0 0 0 3px rgba(228, 189, 123, 0.1);
}

.submission-heading h2 {
    margin: 9px 0 7px;
    color: #fffdf5;
    font-size: 29px;
    font-weight: 750;
    line-height: 1.25;
    letter-spacing: -0.035em;
}

.submission-heading p {
    margin: 0;
    color: #c5d9d1;
    font-size: 12px;
    line-height: 1.7;
}

.submission-close {
    position: absolute;
    top: 30px;
    right: 16px;
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    border: 1px solid rgba(222, 238, 225, 0.2);
    border-radius: 15px;
    color: #e4ede6;
    background: rgba(255, 255, 255, 0.07);
}

.submission-close svg {
    width: 18px;
    height: 18px;
}

.submission-content {
    min-height: 0;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 16px 16px calc(17px + env(safe-area-inset-bottom, 0px));
}

.submission-options {
    overflow: hidden;
    border: 1px solid #e3eae4;
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 5px 20px rgba(26, 67, 54, 0.025);
}

.submission-option {
    display: flex;
    align-items: center;
    gap: 13px;
    width: 100%;
    min-height: 77px;
    padding: 14px 15px;
    border: 0;
    background: transparent;
    text-align: left;
    text-decoration: none;
    transition: background 180ms ease;
}

.submission-option + .submission-option {
    border-top: 1px solid #edf1ec;
}

.submission-option-icon {
    display: grid;
    flex-shrink: 0;
    place-items: center;
    width: 45px;
    height: 45px;
    border: 1px solid rgba(30, 66, 49, 0.03);
    border-radius: 15px;
}

.submission-option-icon svg {
    width: 22px;
    height: 22px;
    stroke-width: 1.65;
}

.tone-teal {
    color: #357d78;
    background: #eaf5f2;
}
.tone-sage {
    color: #547b46;
    background: #eff5e8;
}
.tone-blue {
    color: #4679a6;
    background: #edf3fb;
}
.tone-violet {
    color: #7b68a6;
    background: #f2eef9;
}
.tone-amber {
    color: #ac7937;
    background: #fcf2e3;
}
.tone-rose {
    color: #9e7180;
    background: #f8eff2;
}

.submission-option-copy {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
    gap: 5px;
}

.submission-option-copy strong {
    color: #24433e;
    font-size: 14px;
    font-weight: 750;
    line-height: 1.35;
    letter-spacing: -0.02em;
}

.submission-option-copy small {
    color: #768780;
    font-size: 11px;
    line-height: 1.5;
}

.submission-arrow {
    display: grid;
    flex-shrink: 0;
    place-items: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    color: #718d81;
    background: #f4f7f2;
    transition: transform 180ms ease;
}

.submission-arrow svg {
    width: 14px;
    height: 14px;
}

.submission-soon {
    flex-shrink: 0;
    padding: 4px 7px;
    border: 1px solid #e6e9e2;
    border-radius: 7px;
    color: #7d8373;
    background: #f7f8f3;
    font-size: 9px;
    font-weight: 650;
}

.is-unavailable {
    cursor: default;
}
.is-unavailable .submission-option-copy strong {
    color: #6f7e77;
}

.submission-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    margin: 16px 0 0;
    color: #7b897e;
    font-size: 10px;
    line-height: 1.5;
    text-align: center;
}

.submission-note svg {
    width: 13px;
    height: 13px;
    color: #ab8a54;
    flex-shrink: 0;
}

.submission-close,
.submission-option {
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
}
.submission-close:focus-visible,
.submission-option:focus-visible {
    outline: 2px solid #cda969;
    outline-offset: -4px;
}
.submission-option:not(:disabled):active {
    background: #edf5ef;
}

.submission-fade-enter {
    transition: opacity 240ms ease;
}
.submission-fade-leave {
    transition: opacity 180ms ease;
}
.submission-transparent {
    opacity: 0;
}
.submission-panel-enter {
    transition:
        transform 320ms cubic-bezier(0.22, 1, 0.36, 1),
        opacity 260ms ease;
}
.submission-panel-leave {
    transition:
        transform 200ms ease-in,
        opacity 180ms ease;
}
.submission-panel-hidden {
    transform: translateY(100%);
    opacity: 0;
}

@media (hover: hover) {
    .submission-option:not(:disabled):hover {
        background: #f5f9f4;
    }
    .submission-option:hover .submission-arrow {
        transform: translateX(2px);
    }
    .submission-close:hover {
        background: rgba(255, 255, 255, 0.14);
    }
}

@media (max-width: 360px) {
    .submission-header {
        padding-inline: 20px;
    }
    .submission-content {
        padding-inline: 12px;
    }
    .submission-option {
        gap: 10px;
        padding-inline: 12px;
    }
    .submission-option-icon {
        width: 40px;
        height: 40px;
        border-radius: 13px;
    }
}

@media (max-height: 550px) {
    .submission-header {
        padding-block: 25px 16px;
    }
    .submission-heading h2 {
        font-size: 24px;
    }
    .submission-close {
        top: 20px;
    }
}

@media (min-width: 700px) {
    .submission-position {
        padding-bottom: 20px;
    }
    .submission-sheet {
        max-height: 100%;
        border-bottom: 1px solid rgba(255, 255, 255, 0.4);
        border-radius: 30px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .submission-fade-enter,
    .submission-fade-leave,
    .submission-panel-enter,
    .submission-panel-leave,
    .submission-option,
    .submission-arrow {
        transition-duration: 0.01ms;
    }
}
</style>
