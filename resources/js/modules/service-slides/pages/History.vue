<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/format';
import { history as historyRoute } from '@/routes/service-slides';
import { show } from '@/routes/service-slides/services';
import type { Team } from '@/types';
import type { HistoryItem, HistoryService, SlideKinds } from '../types';

const props = defineProps<{
    items: HistoryItem[];
    services: HistoryService[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Slide history',
                href: historyRoute(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();
const kinds: SlideKinds = {
    song: 'Song',
    liturgy: 'Liturgy',
    other: 'Other',
};

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
let view = ref<'items' | 'dates'>('items');
let sort = ref<'count' | 'last' | 'title'>('count');

let sortedItems = computed(() => {
    const items = [...props.items];

    if (sort.value === 'title') {
        return items.sort((a, b) => a.title.localeCompare(b.title));
    }

    if (sort.value === 'last') {
        return items.sort((a, b) => b.last_used.localeCompare(a.last_used));
    }

    return items.sort(
        (a, b) => b.count - a.count || a.title.localeCompare(b.title),
    );
});
</script>

<template>
    <Head title="Slide history" />

    <div class="flex flex-col space-y-6 p-4">
        <Heading
            title="Slide history"
            description="Library items used in history-tracked templates"
        />

        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-md px-3 py-1.5 text-sm"
                :class="
                    view === 'items'
                        ? 'bg-muted font-medium'
                        : 'text-muted-foreground'
                "
                @click="view = 'items'"
            >
                By item
            </button>
            <button
                type="button"
                class="rounded-md px-3 py-1.5 text-sm"
                :class="
                    view === 'dates'
                        ? 'bg-muted font-medium'
                        : 'text-muted-foreground'
                "
                @click="view = 'dates'"
            >
                By date
            </button>
        </div>

        <div v-if="view === 'items'" class="space-y-4">
            <div class="flex items-center gap-2 text-sm">
                <span class="text-muted-foreground">Sort by</span>
                <select
                    v-model="sort"
                    class="h-8 rounded-md border border-input bg-transparent px-2 text-sm"
                >
                    <option value="count">Times used</option>
                    <option value="last">Last used</option>
                    <option value="title">Title</option>
                </select>
            </div>

            <p
                v-if="sortedItems.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Nothing has been used in a history-tracked template yet.
            </p>

            <div v-else class="overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Title</th>
                            <th class="px-4 py-2 font-medium">Kind</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Uses
                            </th>
                            <th class="px-4 py-2 font-medium">First</th>
                            <th class="px-4 py-2 font-medium">Last</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in sortedItems"
                            :key="item.id"
                            class="border-t"
                            :class="{ 'opacity-60': item.archived }"
                        >
                            <td class="px-4 py-2 font-medium">
                                {{ item.title }}
                                <Badge
                                    v-if="item.archived"
                                    variant="outline"
                                    class="ml-2"
                                    >Archived</Badge
                                >
                            </td>
                            <td class="px-4 py-2">
                                {{ kinds[item.kind] ?? item.kind }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ item.count }}
                            </td>
                            <td class="px-4 py-2">
                                {{ formatDate(item.first_used) }}
                            </td>
                            <td class="px-4 py-2">
                                {{ formatDate(item.last_used) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-else class="space-y-3">
            <p
                v-if="services.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                No services yet.
            </p>

            <div
                v-for="service in services"
                :key="service.id"
                class="rounded-lg border p-4"
            >
                <Link
                    :href="show([teamSlug, service.id])"
                    class="font-medium underline-offset-4 hover:underline"
                >
                    {{ formatDate(service.service_date) }}
                    <span
                        v-if="service.title !== service.service_date"
                        class="text-muted-foreground"
                    >
                        · {{ service.title }}
                    </span>
                </Link>
                <div class="mt-3 space-y-2 text-sm">
                    <div v-for="(deck, index) in service.decks" :key="index">
                        <p class="font-medium text-muted-foreground">
                            {{ deck.name }}
                        </p>
                        <p>
                            {{
                                deck.items.length
                                    ? deck.items.join(' · ')
                                    : 'No library items'
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
