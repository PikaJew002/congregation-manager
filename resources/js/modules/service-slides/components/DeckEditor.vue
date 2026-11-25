<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { GripVertical, Plus, Trash2, X } from '@lucide/vue';
import { VueDraggable } from 'vue-draggable-plus';
import { computed, ref, toRef, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    destroy,
    download,
    entries as saveEntries,
    fill,
    generate,
    reset,
    update,
} from '@/routes/service-slides/decks';
import {
    dropSeparator,
    fromRows,
    payloadEntries,
    removeRow,
    slotLabel,
    toRows,
    type DeckRow,
} from '../deckRows';
import { highlight, useContentSearch } from '../contentSearch';
import type { DeckEntry, ServiceDeck, SlideKinds, SlideSource } from '../types';

const props = defineProps<{
    deck: ServiceDeck;
    sources: SlideSource[];
    kinds: SlideKinds;
    teamSlug: string;
}>();

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

let rows = ref<DeckRow[]>([]);
let deckName = ref(props.deck.name);
let saving = ref(false);
let addMode = ref<'source' | 'slide' | 'fill' | null>(null);
let sourceSearch = ref('');
let allKinds = ref(false);
let selectedSlot = ref<string | null>(null);
let selectedSlideId = ref<number | null>(null);
let fillTitles = ref('');
let fillSlot = ref('');

watch(
    () => props.deck.entries,
    (entries) => {
        rows.value = toRows(structuredClone(entries));
    },
    { immediate: true, deep: true },
);

watch(
    () => props.deck.name,
    (name) => {
        deckName.value = name;
    },
);

let slots = computed(() =>
    (props.deck.template.steps ?? []).filter(
        (step): step is Extract<typeof step, { type: 'slot' }> =>
            step.type === 'slot',
    ),
);

let repeatingSlots = computed(() => slots.value.filter((slot) => slot.repeat));

let pickerKind = computed(() => {
    const slot = slots.value.find((item) => item.key === selectedSlot.value);

    return allKinds.value ? null : (slot?.kind ?? null);
});

let { matches: contentMatches, searching } = useContentSearch({
    teamSlug: toRef(props, 'teamSlug'),
    term: sourceSearch,
    kind: pickerKind,
});

const fitsPicker = (source: SlideSource) =>
    source.original_format === props.deck.template.format &&
    (!pickerKind.value || source.kind === pickerKind.value);

let titleMatches = computed(() => {
    const term = sourceSearch.value.trim().toLowerCase();

    return props.sources.filter((source) => {
        if (!fitsPicker(source)) {
            return false;
        }

        if (!term) {
            return true;
        }

        return (
            source.title.toLowerCase().includes(term) ||
            (source.aliases ?? []).some((alias) =>
                alias.alias.toLowerCase().includes(term),
            )
        );
    });
});

/** Items found only by the words on their slides, listed after title matches. */
let slideTextMatches = computed(() => {
    const listed = new Set(titleMatches.value.map((source) => source.id));

    return props.sources.filter(
        (source) =>
            !listed.has(source.id) &&
            contentMatches.value.has(source.id) &&
            fitsPicker(source),
    );
});

let filteredSources = computed(() => [
    ...titleMatches.value,
    ...slideTextMatches.value,
]);

const slideTextMatch = (source: SlideSource) =>
    slideTextMatches.value.includes(source)
        ? contentMatches.value.get(source.id)
        : undefined;

let canDownload = computed(
    () =>
        ['ready', 'stale'].includes(props.deck.status) &&
        Boolean(props.deck.generated_path),
);

const persist = (nextRows: DeckRow[]) => {
    rows.value = nextRows;
    saving.value = true;
    router.visit(saveEntries([props.teamSlug, props.deck.id]), {
        data: { entries: payloadEntries(fromRows(nextRows)) },
        preserveScroll: true,
        onFinish: () => (saving.value = false),
    });
};

const onReorder = () => persist(rows.value);

const removeAt = (index: number) => persist(removeRow(rows.value, index));

const removeAutoSeparator = (index: number) =>
    persist(dropSeparator(rows.value, index));

const addSource = (source: SlideSource) => {
    const slot = slots.value.find((item) => item.key === selectedSlot.value);
    const entry: DeckEntry = {
        entry_type: 'source',
        slide_source_id: source.id,
        slide_template_slide_id: null,
        slot_key: slot?.key ?? null,
        auto: false,
        source: {
            id: source.id,
            title: source.title,
            kind: source.kind,
            slide_count: source.slide_count,
            archived: false,
        },
    };
    const extras: DeckEntry[] = [];

    if (slot?.separator_slide_id) {
        const slide = props.deck.template.slides.find(
            (item) => item.id === slot.separator_slide_id,
        );
        extras.push({
            entry_type: 'template_slide',
            slide_source_id: null,
            slide_template_slide_id: slot.separator_slide_id,
            slot_key: slot.key,
            auto: true,
            template_slide: slide ?? null,
        });
    }

    const next = [...rows.value];
    let insertAt = next.length;

    if (slot) {
        const lastIndex = [...next]
            .map((row, index) =>
                row.entries.some((entry) => entry.slot_key === slot.key)
                    ? index
                    : -1,
            )
            .filter((index) => index >= 0)
            .at(-1);

        if (lastIndex !== undefined) {
            insertAt = lastIndex + 1;
        }
    }

    next.splice(insertAt, 0, {
        key: `source-${source.id}-${Date.now()}`,
        entries: [entry, ...extras],
    });
    addMode.value = null;
    sourceSearch.value = '';
    persist(next);
};

const addSlide = () => {
    if (!selectedSlideId.value) {
        return;
    }

    const slide = props.deck.template.slides.find(
        (item) => item.id === selectedSlideId.value,
    );

    if (!slide) {
        return;
    }

    persist([
        ...rows.value,
        {
            key: `slide-${slide.id}-${Date.now()}`,
            entries: [
                {
                    entry_type: 'template_slide',
                    slide_template_slide_id: slide.id,
                    slide_source_id: null,
                    slot_key: null,
                    auto: false,
                    template_slide: slide,
                },
            ],
        },
    ]);
    addMode.value = null;
};

const saveName = () => {
    if (deckName.value.trim() === props.deck.name) {
        return;
    }

    router.visit(update([props.teamSlug, props.deck.id]), {
        data: { name: deckName.value.trim() },
        preserveScroll: true,
    });
};

const resetDeck = () => {
    if (
        !confirm(
            `Reset ${props.deck.name} to its preset? Your current order will be replaced.`,
        )
    ) {
        return;
    }

    router.visit(reset([props.teamSlug, props.deck.id]), {
        preserveScroll: true,
    });
};

const removeDeck = () => {
    if (!confirm(`Remove the ${props.deck.name} deck from this service?`)) {
        return;
    }

    router.visit(destroy([props.teamSlug, props.deck.id]), {
        preserveScroll: true,
    });
};

const generateDeck = () => {
    router.visit(generate([props.teamSlug, props.deck.id]), {
        preserveScroll: true,
        preserveState: true,
    });
};

const fillDeck = () => {
    router.visit(fill([props.teamSlug, props.deck.id]), {
        data: {
            titles: fillTitles.value,
            slot: fillSlot.value || null,
        },
        preserveScroll: true,
        onSuccess: () => {
            fillTitles.value = '';
            addMode.value = null;
        },
    });
};

watch(
    () => props.deck.id,
    () => {
        addMode.value = null;
        selectedSlot.value = slots.value[0]?.key ?? null;
        fillSlot.value =
            repeatingSlots.value.length === 1
                ? repeatingSlots.value[0].key
                : '';
    },
    { immediate: true },
);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                <Input
                    v-model="deckName"
                    class="max-w-xs font-medium"
                    @change="saveName"
                />
                <Badge
                    :variant="
                        deck.blocked || deck.status === 'failed'
                            ? 'destructive'
                            : deck.status === 'ready'
                              ? 'default'
                              : 'secondary'
                    "
                >
                    {{ deck.status }}
                </Badge>
                <Badge v-if="deck.stale" variant="outline"
                    >Needs generate</Badge
                >
                <Badge v-if="deck.blocked" variant="destructive">Blocked</Badge>
                <span v-if="saving" class="text-xs text-muted-foreground"
                    >Saving…</span
                >
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="secondary"
                    :disabled="deck.blocked || deck.status === 'generating'"
                    @click="generateDeck"
                >
                    Generate
                </Button>
                <a
                    v-if="canDownload"
                    :href="download.url([teamSlug, deck.id])"
                    class="inline-flex h-8 items-center rounded-md border border-input px-3 text-sm hover:bg-accent"
                >
                    Download
                </a>
                <Button v-else size="sm" variant="outline" disabled
                    >Download</Button
                >
                <Button size="sm" variant="outline" @click="resetDeck"
                    >Reset to preset</Button
                >
                <Button size="sm" variant="ghost" @click="removeDeck"
                    >Remove deck</Button
                >
            </div>
        </div>

        <ul
            v-if="deck.warnings?.length"
            class="space-y-1 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
        >
            <li v-for="(warning, index) in deck.warnings" :key="index">
                {{ warning }}
            </li>
        </ul>

        <VueDraggable
            v-model="rows"
            handle=".drag-handle"
            class="space-y-2"
            @end="onReorder"
        >
            <div
                v-for="(row, index) in rows"
                :key="row.key"
                class="flex items-start gap-2 rounded-lg border p-3"
                :class="{
                    'border-destructive/40': row.entries.some(
                        (entry) =>
                            entry.source?.archived ||
                            entry.template_slide?.missing ||
                            (entry.entry_type === 'source' && !entry.source) ||
                            (entry.entry_type === 'template_slide' &&
                                !entry.template_slide),
                    ),
                }"
            >
                <button
                    type="button"
                    class="drag-handle mt-1 cursor-grab text-muted-foreground"
                    aria-label="Drag to reorder"
                >
                    <GripVertical class="h-4 w-4" />
                </button>

                <div class="min-w-0 flex-1 space-y-1">
                    <template
                        v-for="(entry, entryIndex) in row.entries"
                        :key="entryIndex"
                    >
                        <div
                            v-if="entry.entry_type === 'source'"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <span class="font-medium">{{
                                entry.source?.title ?? 'Missing library item'
                            }}</span>
                            <Badge variant="secondary">{{
                                entry.source
                                    ? (kinds[entry.source.kind] ??
                                      entry.source.kind)
                                    : 'missing'
                            }}</Badge>
                            <span
                                v-if="entry.source?.slide_count"
                                class="text-xs text-muted-foreground"
                            >
                                {{ entry.source.slide_count }} slides
                            </span>
                            <Badge
                                v-if="
                                    slotLabel(
                                        deck.template.steps,
                                        entry.slot_key,
                                    )
                                "
                                variant="outline"
                            >
                                {{
                                    slotLabel(
                                        deck.template.steps,
                                        entry.slot_key,
                                    )
                                }}
                            </Badge>
                            <Badge
                                v-if="entry.source?.archived"
                                variant="destructive"
                                >Archived</Badge
                            >
                        </div>
                        <div
                            v-else
                            class="flex flex-wrap items-center gap-2 text-sm"
                            :class="{
                                'text-muted-foreground': entry.auto,
                            }"
                        >
                            <span
                                class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium"
                            >
                                {{
                                    entry.template_slide?.label ??
                                    'Missing template slide'
                                }}
                            </span>
                            <span v-if="entry.auto" class="text-xs"
                                >automatic separator</span
                            >
                            <Badge
                                v-if="entry.template_slide?.missing"
                                variant="destructive"
                                >Missing from file</Badge
                            >
                        </div>
                    </template>
                </div>

                <div class="flex gap-1">
                    <Button
                        v-if="row.entries.some((entry) => entry.auto)"
                        size="sm"
                        variant="ghost"
                        title="Remove separator only"
                        @click="removeAutoSeparator(index)"
                    >
                        <X class="h-4 w-4" />
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        :aria-label="'Remove item'"
                        @click="removeAt(index)"
                    >
                        <Trash2 class="h-4 w-4" />
                    </Button>
                </div>
            </div>
        </VueDraggable>

        <p
            v-if="rows.length === 0"
            class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
        >
            This deck is empty. Add library items or template slides, or reset
            to the preset.
        </p>

        <div class="flex flex-wrap gap-2">
            <Button
                size="sm"
                variant="outline"
                @click="
                    addMode = addMode === 'source' ? null : 'source';
                    selectedSlot = slots[0]?.key ?? null;
                "
            >
                <Plus class="mr-1 h-4 w-4" />
                Add library item
            </Button>
            <Button
                size="sm"
                variant="outline"
                @click="
                    addMode = addMode === 'slide' ? null : 'slide';
                    selectedSlideId =
                        deck.template.slides.find((slide) => !slide.missing)
                            ?.id ?? null;
                "
            >
                <Plus class="mr-1 h-4 w-4" />
                Add template slide
            </Button>
            <Button
                size="sm"
                variant="outline"
                @click="addMode = addMode === 'fill' ? null : 'fill'"
            >
                Fill from titles
            </Button>
        </div>

        <div
            v-if="addMode === 'source'"
            class="space-y-3 rounded-lg border p-4"
        >
            <div class="grid gap-3 sm:grid-cols-2">
                <div v-if="slots.length" class="grid gap-2">
                    <Label>Slot</Label>
                    <select v-model="selectedSlot" :class="selectClass">
                        <option
                            v-for="slot in slots"
                            :key="slot.key"
                            :value="slot.key"
                        >
                            {{ slot.label }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label>Search</Label>
                    <Input
                        v-model="sourceSearch"
                        type="search"
                        placeholder="Title, alias, or words on slides"
                    />
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="allKinds" type="checkbox" class="rounded" />
                Show all kinds
            </label>
            <div class="max-h-56 space-y-1 overflow-y-auto">
                <button
                    v-for="source in filteredSources"
                    :key="source.id"
                    type="button"
                    class="flex w-full items-start justify-between gap-3 rounded-md px-3 py-2 text-left text-sm hover:bg-muted/50"
                    @click="addSource(source)"
                >
                    <span class="min-w-0">
                        <span class="block">{{ source.title }}</span>
                        <span
                            v-if="slideTextMatch(source)?.excerpt"
                            class="block text-xs text-muted-foreground"
                        >
                            Slide {{ slideTextMatch(source)?.slide }}:
                            <template
                                v-for="(part, index) in highlight(
                                    slideTextMatch(source)?.excerpt ?? '',
                                    sourceSearch,
                                )"
                                :key="index"
                            >
                                <strong
                                    v-if="part.match"
                                    class="text-foreground"
                                    >{{ part.text }}</strong
                                >
                                <template v-else>{{ part.text }}</template>
                            </template>
                        </span>
                    </span>
                    <span class="shrink-0 text-muted-foreground">{{
                        kinds[source.kind] ?? source.kind
                    }}</span>
                </button>
                <p
                    v-if="filteredSources.length === 0"
                    class="p-3 text-sm text-muted-foreground"
                >
                    {{
                        searching
                            ? 'Searching slides…'
                            : 'No library items match.'
                    }}
                </p>
            </div>
        </div>

        <div v-if="addMode === 'slide'" class="space-y-3 rounded-lg border p-4">
            <Label>Template slide</Label>
            <select v-model="selectedSlideId" :class="selectClass">
                <option
                    v-for="slide in deck.template.slides.filter(
                        (item) => !item.missing,
                    )"
                    :key="slide.id"
                    :value="slide.id"
                >
                    {{ slide.label }}
                </option>
            </select>
            <Button size="sm" @click="addSlide">Add slide</Button>
        </div>

        <div v-if="addMode === 'fill'" class="space-y-3 rounded-lg border p-4">
            <div v-if="repeatingSlots.length !== 1" class="grid gap-2">
                <Label>Slot</Label>
                <select v-model="fillSlot" :class="selectClass">
                    <option
                        v-for="slot in slots"
                        :key="slot.key"
                        :value="slot.key"
                    >
                        {{ slot.label }}
                    </option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label>Titles</Label>
                <textarea
                    v-model="fillTitles"
                    rows="5"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none dark:bg-input/30"
                    placeholder="One title per line"
                />
            </div>
            <Button size="sm" :disabled="!fillTitles.trim()" @click="fillDeck">
                Fill slot
            </Button>
        </div>
    </div>
</template>
