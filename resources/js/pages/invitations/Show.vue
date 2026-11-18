<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import TextLink from '@/components/TextLink.vue';
import { login, register } from '@/routes';
import { accept, decline } from '@/routes/invitations';

defineProps<{
    invitation: {
        code: string;
        email: string;
        congregation: string;
        role: string;
    };
    canAccept: boolean;
    accountExists?: boolean;
}>();

defineOptions({
    layout: {
        title: 'Congregation invitation',
        description: 'Accept an invitation to join a congregation',
    },
});
</script>

<template>
    <Head title="Invitation" />

    <div class="flex flex-col gap-6">
        <p class="text-sm text-muted-foreground">
            You have been invited to join
            <strong>{{ invitation.congregation }}</strong>
            as {{ invitation.role }}.
        </p>

        <div v-if="canAccept" class="flex flex-col gap-3">
            <Form v-bind="accept.form(invitation.code)">
                <Button type="submit" class="w-full">Accept invitation</Button>
            </Form>
            <Form v-bind="decline.form(invitation.code)">
                <Button type="submit" variant="secondary" class="w-full">
                    Decline
                </Button>
            </Form>
        </div>

        <div v-else class="flex flex-col gap-3 text-sm">
            <TextLink
                v-if="accountExists"
                :href="login.url({ query: { invitation: invitation.code } })"
            >
                Log in as {{ invitation.email }} to accept
            </TextLink>
            <TextLink
                v-else
                :href="register.url({ query: { invitation: invitation.code } })"
            >
                Create an account for {{ invitation.email }}
            </TextLink>
        </div>
    </div>
</template>
