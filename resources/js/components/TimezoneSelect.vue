<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    id?: string;
    name?: string;
}>();

const model = defineModel<string>({ required: true });

const zones = Intl.supportedValuesOf('timeZone');

// Keep a saved zone the browser does not list (such as an old alias) selectable.
const options = computed(() =>
    zones.includes(model.value) ? zones : [model.value, ...zones],
);
</script>

<template>
    <select
        :id="props.id"
        v-model="model"
        :name="props.name"
        class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
    >
        <option v-for="zone in options" :key="zone" :value="zone">
            {{ zone }}
        </option>
    </select>
</template>
