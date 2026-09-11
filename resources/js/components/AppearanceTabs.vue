<script setup lang="ts">
import { Check, Sun } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';

defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div :class="['appearance-light-card', $props.class]">
        <span class="appearance-light-icon"><Sun /></span>
        <div>
            <strong>Mode terang</strong>
            <p>Palet cerah dan lembut untuk keterbacaan terbaik sepanjang hari.</p>
        </div>
        <span class="appearance-light-status"><Check /> Aktif</span>
    </div>
</template>

<style scoped>
.appearance-light-card {
    min-height: 88px;
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 13px;
    border: 1px solid #dfe4f3;
    border-radius: 16px;
    background: linear-gradient(135deg, #fafbff, #f0f7ff);
    box-shadow: 0 10px 25px rgba(99, 102, 241, 0.06);
}

.appearance-light-icon {
    width: 43px;
    height: 43px;
    display: grid;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 13px;
    color: #c77a1a;
    background: #fff7df;
}

.appearance-light-icon svg {
    width: 19px;
}

.appearance-light-card > div {
    min-width: 0;
    flex: 1;
}

.appearance-light-card strong {
    color: #344158;
    font-size: 10px;
    font-weight: 800;
}

.appearance-light-card p {
    margin: 4px 0 0;
    color: #8490a3;
    font-size: 8px;
    line-height: 1.55;
}

.appearance-light-status {
    padding: 6px 9px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border-radius: 999px;
    color: #087c70;
    background: #e2f8f3;
    font-size: 7px;
    font-weight: 800;
    text-transform: uppercase;
}

.appearance-light-status svg {
    width: 11px;
    stroke-width: 3;
}

@media (max-width: 480px) {
    .appearance-light-card {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .appearance-light-status {
        margin-left: 56px;
    }
}
</style>
