<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Copy } from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/format';
import { settings as settingsRoute } from '@/routes/service-slides';
import { destroy as destroyConnection } from '@/routes/service-slides/connections';
import type { Team } from '@/types';
import type { OAuthConnection } from '../types';

defineProps<{
    connections: OAuthConnection[];
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
const copy = async (value: string, label: string) => {
    await navigator.clipboard.writeText(value);
    toast.success(`${label} copied.`);
};

const revokeConnection = (connection: OAuthConnection) => {
    if (
        !confirm(
            `Disconnect “${connection.name}”? It will need to sign in again.`,
        )
    ) {
        return;
    }

    router.visit(destroyConnection([teamSlug.value, connection.id]), {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head title="Slide access" />

    <div class="flex max-w-2xl flex-col space-y-10 p-4">
        <Heading
            title="Slide access"
            description="Connect Claude, Cursor or another MCP app with the URL below"
        />

        <section class="space-y-4">
            <Heading
                variant="small"
                title="MCP endpoint"
                description="Add a custom connector with this URL. The app signs you in and you choose the congregation."
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

        <section class="space-y-4">
            <Heading
                variant="small"
                title="Signed-in apps for this congregation"
            />
            <p
                v-if="connections.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Nothing is signed in yet.
            </p>
            <div v-else class="space-y-3">
                <div
                    v-for="connection in connections"
                    :key="connection.id"
                    class="flex items-center justify-between gap-4 rounded-lg border p-4"
                >
                    <div>
                        <p class="font-medium">{{ connection.name }}</p>
                        <p class="text-sm text-muted-foreground">
                            Connected
                            {{ formatDateTime(connection.created_at) }}
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="revokeConnection(connection)"
                        >Disconnect</Button
                    >
                </div>
            </div>
        </section>
    </div>
</template>
