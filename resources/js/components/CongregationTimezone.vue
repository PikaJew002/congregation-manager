<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { edit } from '@/routes/teams';

defineProps<{
    usage: string;
}>();

const page = usePage();
const team = computed(() => page.props.currentTeam);
</script>

<template>
    <div class="grid gap-1">
        <p class="text-sm font-medium">Time zone</p>
        <p class="text-sm">
            {{ team?.timezone ?? 'America/New_York' }}
        </p>
        <p class="text-xs text-muted-foreground">
            {{ usage }} Set by the congregation; change it in
            <Link
                v-if="team"
                :href="edit(team.slug)"
                class="underline underline-offset-4"
                >Congregation settings</Link
            ><span v-else>Congregation settings</span>.
        </p>
    </div>
</template>
