<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { services as servicesRoute } from '@/routes/service-slides';
import {
    destroy,
    download,
    generate,
    show,
    update,
} from '@/routes/service-slides/services';
import { store as storeDeck } from '@/routes/service-slides/services/decks';
import type { Team } from '@/types';
import DeckEditor from '../components/DeckEditor.vue';
import type {
    ServiceDetail,
    SlideKinds,
    SlideSource,
    SlideTemplate,
} from '../types';

const props = defineProps<{
    service: ServiceDetail;
    sources: SlideSource[];
    templates: Array<Pick<SlideTemplate, 'id' | 'name'>>;
    kinds: SlideKinds;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null; service: ServiceDetail }) => ({
        breadcrumbs: [
            {
                title: 'Services',
                href: servicesRoute(props.currentTeam?.slug ?? ''),
            },
            {
                title: formatDate(props.service.service_date),
                href: show([props.currentTeam?.slug ?? '', props.service.id]),
            },
        ],
    }),
});

const page = usePage();

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
let activeDeckId = ref(props.service.decks[0]?.id ?? null);
let title = ref(props.service.title);
let notes = ref(props.service.notes ?? '');
let addingDeck = ref(false);
let templateId = ref<number | null>(null);
let pollTimer: ReturnType<typeof setInterval> | null = null;

let availableTemplates = computed(() =>
    props.templates.filter(
        (template) =>
            !props.service.decks.some(
                (deck) => deck.slide_template_id === template.id,
            ),
    ),
);

let activeDeck = computed(
    () =>
        props.service.decks.find((deck) => deck.id === activeDeckId.value) ??
        props.service.decks[0] ??
        null,
);

let anyGenerating = computed(() =>
    props.service.decks.some((deck) => deck.status === 'generating'),
);

const stopPolling = () => {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

watch(
    anyGenerating,
    (generating) => {
        stopPolling();

        if (generating) {
            pollTimer = setInterval(
                () => router.reload({ only: ['service'] }),
                4000,
            );
        }
    },
    { immediate: true },
);

watch(
    () => props.service.decks.map((deck) => deck.id).join(','),
    () => {
        if (
            !props.service.decks.some((deck) => deck.id === activeDeckId.value)
        ) {
            activeDeckId.value = props.service.decks[0]?.id ?? null;
        }
    },
);

let stopFlash: (() => void) | null = null;

onMounted(() => {
    const flashedDeck = page.flash.deck;

    if (flashedDeck) {
        activeDeckId.value = flashedDeck;
    }

    stopFlash = router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash as
            | {
                  deck?: number;
                  fillResult?: {
                      unmatched: Record<string, string[]>;
                      notes: string[];
                  };
              }
            | undefined;

        if (flash?.deck) {
            activeDeckId.value = flash.deck;
        }

        if (flash?.fillResult) {
            for (const note of flash.fillResult.notes) {
                toast.warning(note);
            }

            for (const [slot, titles] of Object.entries(
                flash.fillResult.unmatched,
            )) {
                toast.warning(`${slot}: ${titles.join(', ')}`);
            }
        }
    });
});

onUnmounted(() => {
    stopPolling();
    stopFlash?.();
});

const saveService = () => {
    router.visit(update([teamSlug.value, props.service.id]), {
        data: { title: title.value, notes: notes.value },
        preserveScroll: true,
    });
};

const addDeck = () => {
    if (!templateId.value) {
        return;
    }

    router.visit(storeDeck([teamSlug.value, props.service.id]), {
        data: { template_id: templateId.value },
        preserveScroll: true,
        onSuccess: () => {
            addingDeck.value = false;
            templateId.value = null;
        },
    });
};

const generateAll = () => {
    router.visit(generate([teamSlug.value, props.service.id]), {
        preserveScroll: true,
    });
};

const deleteService = () => {
    if (
        !confirm(
            `Delete the service on ${formatDate(props.service.service_date)} and all of its decks?`,
        )
    ) {
        return;
    }

    router.visit(destroy([teamSlug.value, props.service.id]));
};
</script>

<template>
    <Head :title="formatDate(service.service_date)" />

    <div class="flex flex-col space-y-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-1">
                <Heading
                    :title="formatDate(service.service_date)"
                    :description="service.title"
                />
                <Link
                    :href="servicesRoute(teamSlug)"
                    class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    All services
                </Link>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    variant="secondary"
                    :disabled="service.decks.length === 0"
                    @click="generateAll"
                >
                    Generate all
                </Button>
                <a
                    :href="download.url([teamSlug, service.id])"
                    class="inline-flex h-9 items-center rounded-md border border-input px-4 text-sm hover:bg-accent"
                >
                    Download all
                </a>
                <Button variant="ghost" @click="deleteService"
                    >Delete service</Button
                >
            </div>
        </div>

        <div class="grid max-w-2xl gap-3 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="title">Title</Label>
                <Input id="title" v-model="title" @change="saveService" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="notes">Notes</Label>
                <textarea
                    id="notes"
                    v-model="notes"
                    rows="2"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none dark:bg-input/30"
                    @change="saveService"
                />
            </div>
        </div>

        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-2 border-b pb-2">
                <button
                    v-for="deck in service.decks"
                    :key="deck.id"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm"
                    :class="
                        activeDeck?.id === deck.id
                            ? 'bg-muted font-medium'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="activeDeckId = deck.id"
                >
                    {{ deck.name }}
                    <span
                        v-if="deck.status !== 'ready'"
                        class="ml-1 text-xs opacity-70"
                        >({{ deck.status }})</span
                    >
                </button>
                <Button
                    v-if="availableTemplates.length"
                    size="sm"
                    variant="ghost"
                    @click="addingDeck = !addingDeck"
                >
                    Add deck
                </Button>
            </div>

            <div
                v-if="addingDeck"
                class="flex flex-wrap items-end gap-3 rounded-lg border p-4"
            >
                <div class="grid min-w-56 flex-1 gap-2">
                    <Label>Template</Label>
                    <select
                        v-model="templateId"
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option :value="null" disabled>
                            Choose a template
                        </option>
                        <option
                            v-for="template in availableTemplates"
                            :key="template.id"
                            :value="template.id"
                        >
                            {{ template.name }}
                        </option>
                    </select>
                </div>
                <Button :disabled="!templateId" @click="addDeck"
                    >Add deck</Button
                >
            </div>

            <p
                v-if="service.decks.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                No decks yet.
                <template v-if="availableTemplates.length">
                    Add one from a template to get started.
                </template>
                <template v-else> Upload a template first. </template>
            </p>

            <DeckEditor
                v-if="activeDeck"
                :key="activeDeck.id"
                :deck="activeDeck"
                :sources="sources"
                :kinds="props.kinds"
                :team-slug="teamSlug"
            />
        </div>
    </div>
</template>
