<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { EmailVariable } from '../types';

const variables = defineModel<EmailVariable[]>({ required: true });

const props = withDefaults(
    defineProps<{
        errors?: Record<string, string | undefined>;
        /** Shared values this list can override, shown as hints. */
        inherited?: EmailVariable[];
        disabled?: boolean;
    }>(),
    { errors: () => ({}), inherited: () => [], disabled: false },
);

const add = () => {
    variables.value = [...variables.value, { key: '', value: '' }];
};

const remove = (index: number) => {
    variables.value = variables.value.filter((_, i) => i !== index);
};

const inheritedValue = (key: string) =>
    props.inherited.find((variable) => variable.key === key)?.value;
</script>

<template>
    <div class="space-y-3">
        <p v-if="variables.length === 0" class="text-sm text-muted-foreground">
            No variables yet.
        </p>

        <div
            v-for="(variable, index) in variables"
            :key="index"
            class="grid gap-2 sm:grid-cols-[12rem_1fr_auto]"
        >
            <div class="grid gap-1">
                <Input
                    v-model="variable.key"
                    placeholder="zoom_url"
                    aria-label="Variable name"
                    class="font-mono"
                    :disabled="disabled"
                />
                <InputError :message="errors[`variables.${index}.key`]" />
            </div>
            <div class="grid gap-1">
                <Input
                    v-model="variable.value"
                    placeholder="Value"
                    aria-label="Value"
                    :disabled="disabled"
                />
                <p
                    v-if="inheritedValue(variable.key) !== undefined"
                    class="text-xs text-muted-foreground"
                >
                    Used instead of the shared value “{{
                        inheritedValue(variable.key)
                    }}”.
                </p>
                <InputError :message="errors[`variables.${index}.value`]" />
            </div>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label="Remove variable"
                :disabled="disabled"
                @click="remove(index)"
            >
                <Trash2 class="size-4" />
            </Button>
        </div>

        <InputError :message="errors.variables" />

        <Button
            type="button"
            variant="outline"
            size="sm"
            :disabled="disabled"
            @click="add"
        >
            <Plus class="size-4" /> Add variable
        </Button>
    </div>
</template>
