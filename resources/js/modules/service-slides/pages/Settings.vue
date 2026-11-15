<script setup lang="ts">
import { Form, Head, router, usePage } from '@inertiajs/vue3';
import { Copy } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/format';
import { settings as settingsRoute } from '@/routes/service-slides';
import { destroy, store } from '@/routes/service-slides/tokens';
import type { Team } from '@/types';
import type { AccessToken } from '../types';

defineProps<{
    tokens: AccessToken[];
    mcpUrl: string;
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Slide access',
                href: settingsRoute(props.currentTeam?.slug ?? ''),
            },
        ],
    }),
});

const page = usePage();

let teamSlug = computed(() => page.props.currentTeam?.slug ?? '');
let plainTextToken = ref<string | null>(null);

let stopFlash: (() => void) | null = null;

onMounted(() => {
    if (page.flash.plainTextToken) {
        plainTextToken.value = page.flash.plainTextToken;
    }

    stopFlash = router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash as
            | { plainTextToken?: string }
            | undefined;

        if (flash?.plainTextToken) {
            plainTextToken.value = flash.plainTextToken;
        }
    });
});

onUnmounted(() => stopFlash?.());

const copy = async (value: string, label: string) => {
    await navigator.clipboard.writeText(value);
    toast.success(`${label} copied.`);
};

const revoke = (token: AccessToken) => {
    if (
        !confirm(`Revoke “${token.name}”? Agents using it will stop working.`)
    ) {
        return;
    }

    router.visit(destroy([teamSlug.value, token.id]), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Slide access" />

    <div class="flex max-w-2xl flex-col space-y-10 p-4">
        <Heading
            title="Slide access"
            description="Tokens for agents and scripts that plan service decks"
        />

        <section class="space-y-4">
            <Heading
                variant="small"
                title="MCP endpoint"
                description="Point an agent at this URL with a Sanctum token from below."
            />
            <div class="flex gap-2">
                <Input
                    :model-value="mcpUrl"
                    readonly
                    class="font-mono text-xs"
                />
                <Button
                    type="button"
                    variant="outline"
                    aria-label="Copy MCP URL"
                    @click="copy(mcpUrl, 'MCP URL')"
                >
                    <Copy class="h-4 w-4" />
                </Button>
            </div>
        </section>

        <section
            v-if="plainTextToken"
            class="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10"
        >
            <Heading
                variant="small"
                title="Copy this token now"
                description="It will not be shown again."
            />
            <div class="flex gap-2">
                <Input
                    :model-value="plainTextToken"
                    readonly
                    class="font-mono text-xs"
                />
                <Button
                    type="button"
                    variant="outline"
                    @click="copy(plainTextToken, 'Token')"
                >
                    <Copy class="h-4 w-4" />
                </Button>
            </div>
            <Button size="sm" variant="ghost" @click="plainTextToken = null"
                >Dismiss</Button
            >
        </section>

        <Form
            v-bind="store.form(teamSlug)"
            class="space-y-4 rounded-lg border p-4"
            reset-on-success
            :options="{ preserveScroll: true }"
            v-slot="{ errors, processing }"
        >
            <Heading variant="small" title="Create a token" />
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    placeholder="Cursor agent"
                />
                <InputError :message="errors.name" />
            </div>
            <Button type="submit" :disabled="processing">Create token</Button>
        </Form>

        <section class="space-y-4">
            <Heading variant="small" title="Tokens for this congregation" />
            <p
                v-if="tokens.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                No tokens yet.
            </p>
            <div v-else class="space-y-3">
                <div
                    v-for="token in tokens"
                    :key="token.id"
                    class="flex items-center justify-between gap-4 rounded-lg border p-4"
                >
                    <div>
                        <p class="font-medium">{{ token.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            Created {{ formatDateTime(token.created_at) }}
                            <template v-if="token.last_used_at">
                                · last used
                                {{ formatDateTime(token.last_used_at) }}
                            </template>
                            <template v-else> · never used</template>
                        </p>
                    </div>
                    <Button size="sm" variant="outline" @click="revoke(token)"
                        >Revoke</Button
                    >
                </div>
            </div>
        </section>
    </div>
</template>
