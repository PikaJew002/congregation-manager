<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { services as servicesRoute } from '@/routes/service-slides';
import { importMethod, show, store } from '@/routes/service-slides/services';
import type { Team } from '@/types';
import type { ServiceSummary, SlideTemplate } from '../types';

const props = defineProps<{
    services: ServiceSummary[];
    templates: Array<Pick<SlideTemplate, 'id' | 'name' | 'default_kind'>>;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Services',
                href: servicesRoute(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
let selectedTemplates = ref<number[]>(
    props.templates.map((template) => template.id),
);
let csv = ref('');
let importOpen = ref(false);

const statusVariant = (
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (status === 'failed') {
        return 'destructive';
    }

    if (status === 'ready') {
        return 'default';
    }

    if (status === 'stale') {
        return 'outline';
    }

    return 'secondary';
};

const toggleTemplate = (id: number, checked: boolean) => {
    selectedTemplates.value = checked
        ? [...selectedTemplates.value, id]
        : selectedTemplates.value.filter((value) => value !== id);
};

const onImportSuccess = () => {
    const result = page.flash.importResult;

    if (!result) {
        return;
    }

    toast.success(
        result.decks === 1
            ? 'Imported 1 deck.'
            : `Imported ${result.decks} decks.`,
    );

    for (const error of result.errors) {
        toast.warning(error);
    }

    for (const item of result.unmatched) {
        toast.warning(
            `${item.date} ${item.template}/${item.slot}: ${item.titles.join(', ')}`,
        );
    }

    importOpen.value = false;
    csv.value = '';
};
</script>

<template>
    <Head title="Services" />

    <div class="flex flex-col space-y-10 p-4">
        <Heading
            title="Services"
            description="A service date can have several decks, such as music and liturgy"
        />

        <Form
            v-bind="store.form(teamSlug)"
            class="max-w-2xl space-y-4 rounded-lg border p-4"
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
        >
            <Heading variant="small" title="New service" />
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="service_date">Date</Label>
                    <Input
                        id="service_date"
                        type="date"
                        name="service_date"
                        required
                    />
                    <InputError :message="errors.service_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="title">Title (optional)</Label>
                    <Input id="title" name="title" />
                </div>
            </div>
            <div v-if="templates.length" class="space-y-2">
                <p class="text-sm font-medium">Start with decks</p>
                <label
                    v-for="template in templates"
                    :key="template.id"
                    class="flex items-center gap-2 text-sm"
                >
                    <Checkbox
                        :model-value="selectedTemplates.includes(template.id)"
                        @update:model-value="
                            (value: boolean | 'indeterminate') =>
                                toggleTemplate(template.id, value === true)
                        "
                    />
                    {{ template.name }}
                    <input
                        v-if="selectedTemplates.includes(template.id)"
                        type="hidden"
                        name="template_ids[]"
                        :value="template.id"
                    />
                </label>
            </div>
            <Button type="submit" :disabled="processing">Create</Button>
        </Form>

        <div class="space-y-3">
            <Button
                variant="outline"
                size="sm"
                @click="importOpen = !importOpen"
            >
                {{ importOpen ? 'Hide CSV import' : 'Import CSV' }}
            </Button>
            <Form
                v-if="importOpen"
                v-bind="importMethod.form(teamSlug)"
                class="max-w-3xl space-y-3 rounded-lg border p-4"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
                @success="onImportSuccess"
            >
                <Heading
                    variant="small"
                    title="Import CSV"
                    description="Columns: date,template,slot,titles. Titles are separated with |. Leave slot blank for the short form."
                />
                <textarea
                    v-model="csv"
                    name="csv"
                    rows="8"
                    required
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none dark:bg-input/30"
                    placeholder="date,template,slot,titles&#10;2026-11-01,Music,,Kumi Ori|Trees of the Field&#10;2026-11-01,Liturgy,opening,Psalm 19"
                />
                <InputError :message="errors.csv" />
                <Button type="submit" :disabled="processing || !csv.trim()">
                    Import
                </Button>
            </Form>
        </div>

        <div class="space-y-3">
            <p
                v-if="services.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                No services yet.
            </p>

            <Link
                v-for="service in services"
                :key="service.id"
                :href="show([teamSlug, service.id])"
                class="block rounded-lg border p-4 hover:bg-muted/30"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="font-medium">
                            {{ formatDate(service.service_date) }}
                            <span
                                v-if="service.title !== service.service_date"
                                class="text-muted-foreground"
                            >
                                · {{ service.title }}
                            </span>
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ service.decks.length }}
                            {{ service.decks.length === 1 ? 'deck' : 'decks' }}
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Badge
                            v-for="deck in service.decks"
                            :key="deck.id"
                            :variant="statusVariant(deck.status)"
                        >
                            {{ deck.name }}: {{ deck.status }}
                        </Badge>
                    </div>
                </div>
            </Link>
        </div>
    </div>
</template>
