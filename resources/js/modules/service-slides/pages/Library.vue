<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { library as libraryRoute } from '@/routes/service-slides';
import { destroy as destroyAlias } from '@/routes/service-slides/aliases';
import {
    aliases,
    destroy,
    file,
    restore,
    store,
    update,
} from '@/routes/service-slides/library';
import type { Team } from '@/types';
import { highlight, useContentSearch } from '../contentSearch';
import type { SlideKinds, SlideSource } from '../types';

const props = defineProps<{
    sources: SlideSource[];
    kinds: SlideKinds;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Slide library',
                href: libraryRoute(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();
const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
let search = ref('');
let kindFilter = ref('all');
let showArchived = ref(false);
let expandedId = ref<number | null>(null);
let editingId = ref<number | null>(null);
let editError = ref('');
let editForm = ref({
    title: '',
    kind: 'song',
    author: '',
    ccli: '',
    notes: '',
});
let aliasText = ref('');
let uploading = ref(false);
let replaceUrl = ref('');
let replaceUrlError = ref('');

let contentKind = computed(() =>
    kindFilter.value === 'all' ? null : kindFilter.value,
);
let { matches: contentMatches, searching } = useContentSearch({
    teamSlug,
    term: search,
    kind: contentKind,
    archived: showArchived,
});

const isListed = (source: SlideSource) =>
    (showArchived.value || !source.archived) &&
    (kindFilter.value === 'all' || source.kind === kindFilter.value);

let titleMatches = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.sources.filter((source) => {
        if (!isListed(source)) {
            return false;
        }

        if (!term) {
            return true;
        }

        return (
            source.title.toLowerCase().includes(term) ||
            (source.author ?? '').toLowerCase().includes(term) ||
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
            isListed(source),
    );
});

let filtered = computed(() => [
    ...titleMatches.value,
    ...slideTextMatches.value,
]);

const slideTextMatch = (source: SlideSource) =>
    slideTextMatches.value.includes(source)
        ? contentMatches.value.get(source.id)
        : undefined;

const startEdit = (source: SlideSource) => {
    editingId.value = source.id;
    editError.value = '';
    editForm.value = {
        title: source.title,
        kind: source.kind,
        author: source.author ?? '',
        ccli: source.ccli ?? '',
        notes: source.notes ?? '',
    };
};

const saveEdit = (source: SlideSource) => {
    editError.value = '';
    router.visit(update([teamSlug.value, source.id]), {
        data: editForm.value,
        preserveScroll: true,
        onError: (errors) => {
            editError.value = String(errors.title ?? '');
        },
        onSuccess: () => {
            editingId.value = null;
        },
    });
};

const addAlias = (source: SlideSource) => {
    const alias = aliasText.value.trim();

    if (!alias) {
        return;
    }

    router.visit(aliases([teamSlug.value, source.id]), {
        data: { alias },
        preserveScroll: true,
        onSuccess: () => (aliasText.value = ''),
    });
};

const removeAlias = (aliasId: number) => {
    router.visit(destroyAlias([teamSlug.value, aliasId]), {
        preserveScroll: true,
    });
};

const archiveOrRestore = (source: SlideSource) => {
    router.visit(
        source.archived
            ? restore([teamSlug.value, source.id])
            : destroy([teamSlug.value, source.id]),
        { preserveScroll: true },
    );
};

const replaceFile = (source: SlideSource, event: Event) => {
    const input = event.target as HTMLInputElement;
    const chosen = input.files?.[0];

    if (!chosen) {
        return;
    }

    uploading.value = true;
    replaceUrlError.value = '';
    router.visit(file([teamSlug.value, source.id]), {
        data: { file: chosen },
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            uploading.value = false;
            input.value = '';
        },
    });
};

const replaceFromUrl = (source: SlideSource) => {
    const url = replaceUrl.value.trim();

    if (!url) {
        return;
    }

    uploading.value = true;
    replaceUrlError.value = '';
    router.visit(file([teamSlug.value, source.id]), {
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
</script>

<template>
    <Head title="Slide library" />

    <div class="flex flex-col space-y-10 p-4">
        <Heading
            title="Slide library"
            description="Songs are ODP; liturgy is PPTX. Upload a file or paste a Dropbox or Google Drive share link. A deck only uses files in its template's format."
        />

        <Form
            v-bind="store.form(teamSlug)"
            class="max-w-3xl space-y-4 rounded-lg border p-4"
            reset-on-success
            :options="{ preserveScroll: true }"
        >
            <template #default="{ errors, processing }">
                <Heading variant="small" title="Add to library" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="kind">Kind</Label>
                        <select
                            id="kind"
                            name="kind"
                            required
                            :class="selectClass"
                        >
                            <option
                                v-for="(label, key) in kinds"
                                :key="key"
                                :value="key"
                            >
                                {{ label }}
                            </option>
                        </select>
                        <InputError :message="errors.kind" />
                        <p class="text-xs text-muted-foreground">
                            Song = ODP. Liturgy = PPTX.
                        </p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="title">Title</Label>
                        <Input id="title" name="title" required />
                        <InputError :message="errors.title" />
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
                    <div class="grid gap-2">
                        <Label for="author">Author (optional)</Label>
                        <Input id="author" name="author" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ccli">CCLI (optional)</Label>
                        <Input id="ccli" name="ccli" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="notes">Notes (optional)</Label>
                        <textarea
                            id="notes"
                            name="notes"
                            rows="2"
                            class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        />
                    </div>
                </div>
                <Button type="submit" :disabled="processing">Add</Button>
            </template>
        </Form>

        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-3">
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Search title, alias, or words on slides"
                    class="max-w-xs"
                />
                <select v-model="kindFilter" :class="selectClass" class="w-40">
                    <option value="all">All kinds</option>
                    <option
                        v-for="(label, key) in kinds"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        v-model="showArchived"
                        type="checkbox"
                        class="rounded"
                    />
                    Show archived
                </label>
            </div>

            <p
                v-if="filtered.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                {{ searching ? 'Searching slides…' : 'No library items match.' }}
            </p>

            <div v-else class="space-y-3">
                <div
                    v-for="source in filtered"
                    :key="source.id"
                    class="rounded-lg border"
                    :class="{ 'opacity-60': source.archived }"
                >
                    <button
                        type="button"
                        class="flex w-full items-start justify-between gap-4 p-4 text-left"
                        @click="
                            expandedId =
                                expandedId === source.id ? null : source.id
                        "
                    >
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{
                                    source.title
                                }}</span>
                                <Badge variant="secondary">{{
                                    kinds[source.kind] ?? source.kind
                                }}</Badge>
                                <Badge variant="outline">{{
                                    source.original_format.toUpperCase()
                                }}</Badge>
                                <Badge v-if="source.archived" variant="outline"
                                    >Archived</Badge
                                >
                            </div>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{ source.slide_count ?? 0 }} slides
                                <template v-if="source.aliases?.length">
                                    ·
                                    {{
                                        source.aliases
                                            .map((alias) => alias.alias)
                                            .join(', ')
                                    }}
                                </template>
                                <template v-if="source.last_used_on">
                                    · last used
                                    {{ formatDate(source.last_used_on) }}
                                </template>
                            </p>
                            <p
                                v-if="slideTextMatch(source)?.excerpt"
                                class="mt-1 text-sm"
                            >
                                <span class="text-muted-foreground"
                                    >Slide {{ slideTextMatch(source)?.slide }}:
                                </span>
                                <template
                                    v-for="(part, index) in highlight(
                                        slideTextMatch(source)?.excerpt ?? '',
                                        search,
                                    )"
                                    :key="index"
                                >
                                    <strong v-if="part.match">{{
                                        part.text
                                    }}</strong>
                                    <template v-else>{{ part.text }}</template>
                                </template>
                            </p>
                        </div>
                    </button>

                    <div
                        v-if="expandedId === source.id"
                        class="space-y-4 border-t p-4"
                        @click.stop
                    >
                        <div
                            v-if="editingId === source.id"
                            class="grid gap-3 sm:grid-cols-2"
                        >
                            <div class="grid gap-2">
                                <Label>Title</Label>
                                <Input v-model="editForm.title" required />
                                <InputError :message="editError" />
                            </div>
                            <div class="grid gap-2">
                                <Label>Kind</Label>
                                <select
                                    v-model="editForm.kind"
                                    :class="selectClass"
                                >
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
                                <Label>Author</Label>
                                <Input v-model="editForm.author" />
                            </div>
                            <div class="grid gap-2">
                                <Label>CCLI</Label>
                                <Input v-model="editForm.ccli" />
                            </div>
                            <div class="grid gap-2 sm:col-span-2">
                                <Label>Notes</Label>
                                <textarea
                                    v-model="editForm.notes"
                                    rows="2"
                                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none dark:bg-input/30"
                                />
                            </div>
                            <div class="flex gap-2 sm:col-span-2">
                                <Button size="sm" @click="saveEdit(source)"
                                    >Save</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="editingId = null"
                                    >Cancel</Button
                                >
                            </div>
                        </div>

                        <div v-else class="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                @click="startEdit(source)"
                                >Edit details</Button
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                @click="archiveOrRestore(source)"
                            >
                                {{ source.archived ? 'Restore' : 'Archive' }}
                            </Button>
                            <label
                                class="inline-flex h-8 cursor-pointer items-center rounded-md border border-input px-3 text-sm hover:bg-accent"
                                :class="{
                                    'pointer-events-none opacity-50': uploading,
                                }"
                            >
                                {{ uploading ? 'Uploading…' : 'Replace file' }}
                                <input
                                    type="file"
                                    :accept="`.${source.original_format}`"
                                    class="sr-only"
                                    @change="replaceFile(source, $event)"
                                />
                            </label>
                            <div class="flex w-full max-w-xl flex-wrap gap-2">
                                <Input
                                    v-model="replaceUrl"
                                    type="url"
                                    class="min-w-0 flex-1"
                                    placeholder="Or paste Dropbox / Drive link"
                                    :disabled="uploading"
                                    @keydown.enter.prevent="
                                        replaceFromUrl(source)
                                    "
                                />
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    :disabled="uploading || !replaceUrl.trim()"
                                    @click="replaceFromUrl(source)"
                                >
                                    Replace from link
                                </Button>
                            </div>
                            <InputError
                                v-if="replaceUrlError"
                                :message="replaceUrlError"
                            />
                        </div>

                        <div class="space-y-2">
                            <p class="text-sm font-medium">Aliases</p>
                            <div class="flex flex-wrap gap-2">
                                <Badge
                                    v-for="alias in source.aliases ?? []"
                                    :key="alias.id"
                                    variant="outline"
                                    class="gap-1"
                                >
                                    {{ alias.alias }}
                                    <button
                                        type="button"
                                        class="text-muted-foreground hover:text-foreground"
                                        @click="removeAlias(alias.id)"
                                    >
                                        ×
                                    </button>
                                </Badge>
                            </div>
                            <div class="flex max-w-md gap-2">
                                <Input
                                    v-model="aliasText"
                                    placeholder="Add alias"
                                    @keydown.enter.prevent="addAlias(source)"
                                />
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    @click="addAlias(source)"
                                    >Add</Button
                                >
                            </div>
                        </div>

                        <div
                            v-if="source.slide_lines?.length"
                            class="space-y-1"
                        >
                            <p class="text-sm font-medium">
                                First line of each slide
                            </p>
                            <ol
                                class="list-decimal space-y-1 pl-5 text-sm text-muted-foreground"
                            >
                                <li
                                    v-for="(line, index) in source.slide_lines"
                                    :key="index"
                                >
                                    {{ line || '(blank)' }}
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
