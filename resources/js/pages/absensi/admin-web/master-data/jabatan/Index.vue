<script setup lang="ts">
import UnitJabatanIndex from '../unit-jabatan/Index.vue';

defineOptions({ inheritAttrs: false });
</script>

<template>
    <UnitJabatanIndex v-bind="$attrs" />
</template>
