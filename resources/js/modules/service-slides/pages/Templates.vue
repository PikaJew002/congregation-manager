<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { VueDraggable } from 'vue-draggable-plus';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { templates as templatesRoute } from '@/routes/service-slides';
import { update as updateSlide } from '@/routes/service-slides/template-slides';
import { file, preset, store, update } from '@/routes/service-slides/templates';
import type { Team } from '@/types';
import type {
    LibrarySourceOption,
    PresetStep,
    SlideKinds,
    SlideTemplate,
    TemplateSlide,
} from '../types';

const props = defineProps<{
    templates: SlideTemplate[];
    sources: LibrarySourceOption[];
    kinds: SlideKinds;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Slide templates',
                href: templatesRoute(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();
const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';
const roles = [
    { value: 'intro', label: 'Intro' },
    { value: 'separator', label: 'Separator' },
    { value: 'title', label: 'Title' },
] as const;

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
let selectedId = ref(props.templates[0]?.id ?? null);
let steps = ref<PresetStep[]>([]);
let meta = ref({
    name: '',
    default_kind: '' as string,
    is_history_tracked: false,
});
let uploading = ref(false);
let replaceUrl = ref('');
let replaceUrlError = ref('');

let selected = computed(
    () =>
        props.templates.find((template) => template.id === selectedId.value) ??
        null,
);

const slideLabel = (template: SlideTemplate, id: number | null) =>
    template.slides.find((slide) => slide.id === id)?.label ?? `Slide #${id}`;

const loadSelected = (template: SlideTemplate | null) => {
    if (!template) {
        steps.value = [];
        return;
    }

    meta.value = {
        name: template.name,
        default_kind: template.default_kind ?? '',
        is_history_tracked: template.is_history_tracked,
    };
    steps.value = structuredClone(template.steps ?? []);
};

watch(selected, loadSelected, { immediate: true });
watch(
    () => props.templates,
    () => loadSelected(selected.value),
);

const saveMeta = () => {
    if (!selected.value) {
        return;
    }

    router.visit(update([teamSlug.value, selected.value.id]), {
        data: {
            name: meta.value.name,
            default_kind: meta.value.default_kind || null,
            is_history_tracked: meta.value.is_history_tracked,
        },
        preserveScroll: true,
    });
};

const savePreset = () => {
    if (!selected.value) {
        return;
    }

    router.visit(preset([teamSlug.value, selected.value.id]), {
        data: { steps: steps.value },
        preserveScroll: true,
    });
};

const saveSlide = (slide: TemplateSlide) => {
    router.visit(updateSlide([teamSlug.value, slide.id]), {
        data: { label: slide.label, role: slide.role },
        preserveScroll: true,
    });
};

const replaceFile = (event: Event) => {
    if (!selected.value) {
        return;
    }

    const input = event.target as HTMLInputElement;
    const chosen = input.files?.[0];

    if (!chosen) {
        return;
    }

    uploading.value = true;
    replaceUrlError.value = '';
    router.visit(file([teamSlug.value, selected.value.id]), {
        data: { file: chosen },
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            uploading.value = false;
            input.value = '';
        },
    });
};

const replaceFromUrl = () => {
    if (!selected.value) {
        return;
    }

    const url = replaceUrl.value.trim();

    if (!url) {
        return;
    }

    uploading.value = true;
    replaceUrlError.value = '';
    router.visit(file([teamSlug.value, selected.value.id]), {
        data: { url },
        preserveScroll: true,
        onError: (errors) => {
            replaceUrlError.value = String(errors.url ?? '');
        },
        onSuccess: () => {
            replaceUrl.value = '';
        },
        onFinish: () => {
            uploading.value = false;
        },
    });
};

const addTemplateStep = () => {
    const slide = selected.value?.slides.find((item) => !item.missing);

    if (!slide) {
        return;
    }

    steps.value.push({ type: 'template_slide', slide_id: slide.id });
};

const addSlotStep = () => {
    const kind = selected.value?.default_kind ?? null;

    steps.value.push({
        type: 'slot',
        key: kind === 'song' ? 'songs' : 'items',
        label: kind === 'song' ? 'Songs' : 'Items',
        kind,
        repeat: true,
        separator_slide_id: null,
        default_source_ids: [],
    });
};

const removeStep = (index: number) => {
    steps.value.splice(index, 1);
};

const sourcesForKind = (kind: string | null) =>
    props.sources.filter(
        (source) =>
            source.original_format === selected.value?.format &&
            (!kind || source.kind === kind),
    );
</script>

<template>
    <Head title="Slide templates" />

    <div class="flex flex-col space-y-8 p-4">
        <Heading
            title="Slide templates"
            description="The look of a deck, its structural slides, and the default arrangement for new decks"
        />

        <Form
            v-bind="store.form(teamSlug)"
            class="max-w-3xl space-y-4 rounded-lg border p-4"
            reset-on-success
            :options="{ preserveScroll: true }"
        >
            <template #default="{ errors, processing }">
                <Heading variant="small" title="Upload a template" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            name="name"
                            required
                            placeholder="Music"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="default_kind">Default kind</Label>
                        <select
                            id="default_kind"
                            name="default_kind"
                            :class="selectClass"
                        >
                            <option value="">None</option>
                            <option
                                v-for="(label, key) in kinds"
                                :key="key"
                                :value="key"
                            >
                                {{ label }}
                            </option>
                        </select>
                        <p class="text-xs text-muted-foreground">
                            Song templates need ODP; liturgy templates need
                            PPTX.
                        </p>
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="file">ODP or PPTX file</Label>
                        <Input
                            id="file"
                            type="file"
                            name="file"
                            accept=".odp,.pptx"
                        />
                        <InputError :message="errors.file" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="url">Or Dropbox / Google Drive link</Label>
                        <input
                            id="url"
                            type="url"
                            name="url"
                            placeholder="https://..."
                            class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        />
                        <InputError :message="errors.url" />
                    </div>
                    <label
                        class="flex items-center gap-2 text-sm sm:col-span-2"
                    >
                        <input
                            type="checkbox"
                            name="is_history_tracked"
                            value="1"
                            class="rounded"
                        />
                        Track library items from this template in history
                    </label>
                </div>
                <Button type="submit" :disabled="processing">Add</Button>
            </template>
        </Form>

        <p
            v-if="templates.length === 0"
            class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            No templates yet.
        </p>

        <div v-else class="grid gap-6 lg:grid-cols-[16rem_1fr]">
            <div class="space-y-2">
                <button
                    v-for="template in templates"
                    :key="template.id"
                    type="button"
                    class="flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left text-sm"
                    :class="
                        selectedId === template.id
                            ? 'border-foreground bg-muted/50'
                            : 'hover:bg-muted/30'
                    "
                    @click="selectedId = template.id"
                >
                    <span class="font-medium">{{ template.name }}</span>
                    <span class="flex gap-1">
                        <Badge variant="secondary">{{
                            template.format.toUpperCase()
                        }}</Badge>
                        <Badge variant="outline">{{
                            template.decks_count ?? 0
                        }}</Badge>
                    </span>
                </button>
            </div>

            <div v-if="selected" class="space-y-8">
                <section class="space-y-4 rounded-lg border p-4">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <Heading variant="small" title="Details" />
                        <div class="flex flex-wrap items-center gap-2">
                            <label
                                class="inline-flex h-8 cursor-pointer items-center rounded-md border border-input px-3 text-sm hover:bg-accent"
                                :class="{
                                    'pointer-events-none opacity-50': uploading,
                                }"
                            >
                                {{ uploading ? 'Uploading…' : 'Replace file' }}
                                <input
                                    type="file"
                                    :accept="`.${selected.format}`"
                                    class="sr-only"
                                    @change="replaceFile"
                                />
                            </label>
                        </div>
                    </div>
                    <div class="flex max-w-xl flex-wrap gap-2">
                        <Input
                            v-model="replaceUrl"
                            type="url"
                            class="min-w-0 flex-1"
                            placeholder="Or paste Dropbox / Drive link"
                            :disabled="uploading"
                            @keydown.enter.prevent="replaceFromUrl"
                        />
                        <Button
                            size="sm"
                            variant="secondary"
                            :disabled="uploading || !replaceUrl.trim()"
                            @click="replaceFromUrl"
                        >
                            Replace from link
                        </Button>
                    </div>
                    <InputError
                        v-if="replaceUrlError"
                        :message="replaceUrlError"
                    />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label>Name</Label>
                            <Input v-model="meta.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label>Default kind</Label>
                            <select
                                v-model="meta.default_kind"
                                :class="selectClass"
                            >
                                <option value="">None</option>
                                <option
                                    v-for="(label, key) in kinds"
                                    :key="key"
                                    :value="key"
                                >
                                    {{ label }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox
                            :model-value="meta.is_history_tracked"
                            @update:model-value="
                                (value: boolean | 'indeterminate') =>
                                    (meta.is_history_tracked = value === true)
                            "
                        />
                        Track in history
                    </label>
                    <Button size="sm" @click="saveMeta">Save details</Button>
                </section>

                <section class="space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title="Slides in the file"
                        description="Labels and roles help suggest a starting preset"
                    />
                    <div class="space-y-3">
                        <div
                            v-for="slide in selected.slides"
                            :key="slide.id"
                            class="grid gap-2 rounded-md border p-3 sm:grid-cols-[1fr_10rem_auto]"
                            :class="{ 'border-destructive/40': slide.missing }"
                        >
                            <Input v-model="slide.label" />
                            <select v-model="slide.role" :class="selectClass">
                                <option
                                    v-for="role in roles"
                                    :key="role.value"
                                    :value="role.value"
                                >
                                    {{ role.label }}
                                </option>
                            </select>
                            <div class="flex items-center gap-2">
                                <Badge
                                    v-if="slide.missing"
                                    variant="destructive"
                                    >Missing</Badge
                                >
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    @click="saveSlide(slide)"
                                    >Save</Button
                                >
                            </div>
                        </div>
                    </div>
                </section>

                <section class="space-y-4 rounded-lg border p-4">
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <Heading
                            variant="small"
                            title="Preset"
                            :description="
                                selected.preset_saved
                                    ? 'Default arrangement for new decks. Existing decks keep their own order.'
                                    : 'Suggested from slide roles. Save to keep it.'
                            "
                        />
                        <div class="flex gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                @click="addTemplateStep"
                                >Add slide</Button
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                @click="addSlotStep"
                                >Add slot</Button
                            >
                            <Button size="sm" @click="savePreset"
                                >Save preset</Button
                            >
                        </div>
                    </div>

                    <Badge v-if="!selected.preset_saved" variant="secondary"
                        >Not saved yet</Badge
                    >

                    <VueDraggable
                        v-model="steps"
                        handle=".drag-handle"
                        class="space-y-3"
                    >
                        <div
                            v-for="(step, index) in steps"
                            :key="index"
                            class="rounded-md border p-3"
                        >
                            <div
                                class="mb-3 flex items-center justify-between gap-2"
                            >
                                <button
                                    type="button"
                                    class="drag-handle cursor-grab text-sm text-muted-foreground"
                                >
                                    Drag
                                </button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="removeStep(index)"
                                    >Remove</Button
                                >
                            </div>

                            <div
                                v-if="step.type === 'template_slide'"
                                class="grid gap-2"
                            >
                                <Label>Template slide</Label>
                                <select
                                    v-model="step.slide_id"
                                    :class="selectClass"
                                >
                                    <option
                                        v-for="slide in selected.slides.filter(
                                            (item) => !item.missing,
                                        )"
                                        :key="slide.id"
                                        :value="slide.id"
                                    >
                                        {{ slide.label }}
                                    </option>
                                </select>
                            </div>

                            <div v-else class="grid gap-3 sm:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label>Key</Label>
                                    <Input v-model="step.key" />
                                </div>
                                <div class="grid gap-2">
                                    <Label>Label</Label>
                                    <Input v-model="step.label" />
                                </div>
                                <div class="grid gap-2">
                                    <Label>Kind filter</Label>
                                    <select
                                        v-model="step.kind"
                                        :class="selectClass"
                                    >
                                        <option :value="null">Any</option>
                                        <option
                                            v-for="(label, key) in kinds"
                                            :key="key"
                                            :value="key"
                                        >
                                            {{ label }}
                                        </option>
                                    </select>
                                </div>
                                <div class="grid gap-2">
                                    <Label>Separator after each item</Label>
                                    <select
                                        v-model="step.separator_slide_id"
                                        :class="selectClass"
                                    >
                                        <option :value="null">None</option>
                                        <option
                                            v-for="slide in selected.slides.filter(
                                                (item) => !item.missing,
                                            )"
                                            :key="slide.id"
                                            :value="slide.id"
                                        >
                                            {{ slide.label }}
                                        </option>
                                    </select>
                                </div>
                                <label class="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        :model-value="step.repeat"
                                        @update:model-value="
                                            (
                                                value:
                                                    boolean | 'indeterminate',
                                            ) => (step.repeat = value === true)
                                        "
                                    />
                                    Allow multiple items
                                </label>
                                <div class="grid gap-2 sm:col-span-2">
                                    <Label>Default library items</Label>
                                    <select
                                        multiple
                                        :value="
                                            step.default_source_ids.map(String)
                                        "
                                        :class="selectClass"
                                        class="min-h-28"
                                        @change="
                                            step.default_source_ids =
                                                Array.from(
                                                    (
                                                        $event.target as HTMLSelectElement
                                                    ).selectedOptions,
                                                ).map((option) =>
                                                    Number(option.value),
                                                )
                                        "
                                    >
                                        <option
                                            v-for="source in sourcesForKind(
                                                step.kind,
                                            )"
                                            :key="source.id"
                                            :value="source.id"
                                        >
                                            {{ source.title }}
                                        </option>
                                    </select>
                                    <p class="text-xs text-muted-foreground">
                                        Hold Ctrl or Cmd to select more than
                                        one. Current separator:
                                        {{
                                            step.separator_slide_id
                                                ? slideLabel(
                                                      selected,
                                                      step.separator_slide_id,
                                                  )
                                                : 'none'
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </VueDraggable>
                </section>
            </div>
        </div>
    </div>
</template>
