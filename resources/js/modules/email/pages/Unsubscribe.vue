<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { store } from '@/routes/email/unsubscribe';

defineProps<{
    token: string;
    email: string;
    unsubscribed: boolean;
}>();

defineOptions({
    layout: {
        title: 'Email',
        description: 'Manage your subscription',
    },
});
</script>

<template>
    <Head title="Unsubscribe" />

    <div class="flex flex-col gap-6 text-center">
        <template v-if="unsubscribed">
            <p class="text-sm text-muted-foreground">
                <strong class="text-foreground">{{ email }}</strong> has been
                unsubscribed and won't receive emails from the congregation
                anymore.
            </p>
            <p class="text-sm text-muted-foreground">
                Changed your mind? Contact the congregation and ask to be added
                back.
            </p>
        </template>

        <template v-else>
            <p class="text-sm text-muted-foreground">
                Stop sending congregation emails to
                <strong class="text-foreground">{{ email }}</strong
                >?
            </p>
            <Form v-bind="store.form(token)" v-slot="{ processing }">
                <Button type="submit" class="w-full" :disabled="processing">
                    Unsubscribe
                </Button>
            </Form>
        </template>
    </div>
</template>
