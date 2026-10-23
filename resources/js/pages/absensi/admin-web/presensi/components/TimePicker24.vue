<script setup lang="ts">
import { Check, ChevronDown, Clock3, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        disabled?: boolean;
        invalid?: boolean;
        ariaLabel?: string;
    }>(),
    {
        disabled: false,
        invalid: false,
        ariaLabel: 'Pilih waktu dalam format 24 jam',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const root = ref<HTMLElement | null>(null);
const hourWheel = ref<HTMLElement | null>(null);
const minuteWheel = ref<HTMLElement | null>(null);
const open = ref(false);
const draftHour = ref('00');
const draftMinute = ref('00');
const panelId = useId();
const hours = Array.from({ length: 24 }, (_, index) => String(index).padStart(2, '0'));
const minutes = Array.from({ length: 60 }, (_, index) => String(index).padStart(2, '0'));
const validValue = computed(() => /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(props.modelValue));
const displayValue = computed(() => (validValue.value ? props.modelValue : '--:--'));

const setDraftFromValue = () => {
    if (validValue.value) {
        [draftHour.value, draftMinute.value] = props.modelValue.split(':');
        return;
    }

    const now = new Date();
    draftHour.value = String(now.getHours()).padStart(2, '0');
    draftMinute.value = String(now.getMinutes()).padStart(2, '0');
};

const centerSelectedValue = (wheel: HTMLElement | null) => {
    const selected = wheel?.querySelector<HTMLElement>('.selected');
    if (!wheel || !selected) return;
    wheel.scrollTop = selected.offsetTop - wheel.clientHeight / 2 + selected.offsetHeight / 2;
};

const centerDraftValues = async () => {
    await nextTick();
    centerSelectedValue(hourWheel.value);
    centerSelectedValue(minuteWheel.value);
};

const toggle = async () => {
    if (props.disabled) return;
    if (!open.value) setDraftFromValue();
    open.value = !open.value;
    if (open.value) await centerDraftValues();
};

const apply = () => {
    emit('update:modelValue', `${draftHour.value}:${draftMinute.value}`);
    open.value = false;
};

const useCurrentTime = async () => {
    const now = new Date();
    draftHour.value = String(now.getHours()).padStart(2, '0');
    draftMinute.value = String(now.getMinutes()).padStart(2, '0');
    await centerDraftValues();
};

const clear = () => {
    emit('update:modelValue', '');
    open.value = false;
};

const handleOutsideClick = (event: PointerEvent) => {
    if (root.value && !root.value.contains(event.target as Node)) open.value = false;
};

const handleKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') open.value = false;
};

watch(
    () => props.disabled,
    (disabled) => {
        if (disabled) open.value = false;
    },
);

onMounted(() => {
    document.addEventListener('pointerdown', handleOutsideClick);
    window.addEventListener('keydown', handleKeydown);
});
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', handleOutsideClick);
    window.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <div ref="root" class="time-picker-24" :class="{ open, disabled, invalid }">
        <button
            type="button"
            class="time-picker-trigger"
            :disabled="disabled"
            :aria-label="ariaLabel"
            :aria-expanded="open"
            :aria-controls="panelId"
            aria-haspopup="dialog"
            @click="toggle"
        >
            <Clock3 aria-hidden="true" />
            <strong :class="{ placeholder: !validValue }">{{ displayValue }}</strong>
            <small>24 JAM</small>
            <ChevronDown class="chevron" aria-hidden="true" />
        </button>

        <div v-if="open" :id="panelId" class="time-picker-panel" role="dialog" :aria-label="ariaLabel">
            <header>
                <span>PILIH WAKTU</span>
                <strong>{{ draftHour }}<b>:</b>{{ draftMinute }}</strong>
            </header>

            <div class="time-picker-columns">
                <section>
                    <small>Jam</small>
                    <div ref="hourWheel" class="time-picker-wheel">
                        <button v-for="hour in hours" :key="hour" type="button" :class="{ selected: draftHour === hour }" @click="draftHour = hour">
                            {{ hour }}
                        </button>
                    </div>
                </section>
                <span class="time-separator">:</span>
                <section>
                    <small>Menit</small>
                    <div ref="minuteWheel" class="time-picker-wheel">
                        <button
                            v-for="minute in minutes"
                            :key="minute"
                            type="button"
                            :class="{ selected: draftMinute === minute }"
                            @click="draftMinute = minute"
                        >
                            {{ minute }}
                        </button>
                    </div>
                </section>
            </div>

            <footer>
                <button type="button" class="clear-time" title="Kosongkan waktu" @click="clear"><Trash2 /> Hapus</button>
                <button type="button" class="current-time" @click="useCurrentTime">Sekarang</button>
                <button type="button" class="apply-time" @click="apply"><Check /> Terapkan</button>
            </footer>
        </div>
    </div>
</template>

<style scoped>
.time-picker-24 {
    position: relative;
    width: 100%;
}

.time-picker-trigger {
    display: grid;
    grid-template-columns: auto 1fr auto auto;
    align-items: center;
    gap: 9px;
    width: 100%;
    height: 44px;
    padding: 0 12px;
    border: 1px solid var(--rs-border);
    border-radius: 10px;
    outline: none;
    color: var(--rs-primary-deep);
    background: white;
    text-align: left;
}

.time-picker-trigger:focus,
.open .time-picker-trigger {
    border-color: var(--rs-primary-pale);
    box-shadow: 0 0 0 3px rgba(var(--rs-primary-rgb), 0.09);
}

.invalid .time-picker-trigger {
    border-color: #dc5364;
}

.disabled .time-picker-trigger {
    color: var(--rs-muted);
    background: var(--rs-surface-muted);
    cursor: not-allowed;
}

.time-picker-trigger > svg {
    width: 17px;
    color: var(--rs-primary);
}

.time-picker-trigger strong {
    font-size: 14px;
    letter-spacing: 0.04em;
}

.time-picker-trigger strong.placeholder {
    color: var(--rs-muted);
}

.time-picker-trigger small {
    padding: 3px 6px;
    border-radius: 5px;
    color: var(--rs-primary);
    background: var(--rs-primary-soft);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.05em;
}

.time-picker-trigger .chevron {
    width: 15px;
    color: var(--rs-muted);
    transition: transform 0.15s ease;
}

.open .time-picker-trigger .chevron {
    transform: rotate(180deg);
}

.time-picker-panel {
    position: absolute;
    z-index: 50;
    top: calc(100% + 6px);
    left: 0;
    width: min(300px, calc(100vw - 40px));
    overflow: hidden;
    border: 1px solid var(--rs-border);
    border-radius: 14px;
    background: white;
    box-shadow: 0 18px 45px rgba(var(--rs-primary-deep-rgb), 0.22);
}

.time-picker-panel > header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border-bottom: 1px solid var(--rs-surface-muted);
    background: linear-gradient(to right, var(--rs-canvas), #fff);
}

.time-picker-panel > header span {
    color: var(--rs-text-soft);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.1em;
}

.time-picker-panel > header strong {
    color: var(--rs-primary);
    font-size: 20px;
    letter-spacing: 0.05em;
}

.time-picker-panel > header b {
    padding: 0 3px;
    color: var(--rs-primary-pale);
}

.time-picker-columns {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    gap: 8px;
    padding: 12px 14px;
}

.time-picker-columns section {
    min-width: 0;
}

.time-picker-columns section > small {
    display: block;
    margin-bottom: 6px;
    color: var(--rs-text-soft);
    font-size: 11px;
    font-weight: 750;
    text-align: center;
}

.time-separator {
    align-self: center;
    color: var(--rs-muted);
    font-size: 21px;
    font-weight: 800;
}

.time-picker-wheel {
    position: relative;
    display: grid;
    gap: 3px;
    height: 176px;
    overflow-y: auto;
    padding: 4px;
    border: 1px solid var(--rs-border);
    border-radius: 10px;
    background: var(--rs-canvas);
    scrollbar-width: thin;
}

.time-picker-wheel button {
    min-height: 32px;
    border: 0;
    border-radius: 7px;
    color: var(--rs-text-soft);
    background: transparent;
    font-size: 13px;
    font-weight: 700;
}

.time-picker-wheel button:hover {
    background: var(--rs-primary-soft);
}

.time-picker-wheel button.selected {
    color: white;
    background: var(--rs-primary);
    box-shadow: 0 4px 10px rgba(var(--rs-primary-rgb), 0.22);
}

.time-picker-panel > footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
    padding: 10px 12px;
    border-top: 1px solid var(--rs-surface-muted);
    background: var(--rs-canvas);
}

.time-picker-panel > footer button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    min-height: 33px;
    padding: 0 10px;
    border: 1px solid var(--rs-border);
    border-radius: 8px;
    color: var(--rs-text-soft);
    background: white;
    font-size: 10px;
    font-weight: 750;
}

.time-picker-panel > footer button svg {
    width: 13px;
}

.time-picker-panel > footer .clear-time {
    margin-right: auto;
    color: #c85261;
}

.time-picker-panel > footer .current-time {
    color: var(--rs-primary);
    border-color: var(--rs-primary-soft);
    background: var(--rs-canvas);
}

.time-picker-panel > footer .apply-time {
    color: white;
    border-color: var(--rs-primary);
    background: var(--rs-primary);
}
</style>
