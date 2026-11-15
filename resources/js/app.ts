import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const pages = import.meta.glob<{ default: DefineComponent }>(
            './pages/**/*.vue',
        );
        const modules = import.meta.glob<{ default: DefineComponent }>(
            './modules/**/pages/**/*.vue',
        );
        const page = pages[`./pages/${name}.vue`];

        if (page) {
            return (await page()).default;
        }

        const match = name.match(/^([^/]+)\/(.+)$/);

        if (match) {
            const modulePage =
                modules[`./modules/${match[1]}/pages/${match[2]}.vue`];

            if (modulePage) {
                return (await modulePage()).default;
            }
        }

        throw new Error(`Page not found: ${name}`);
    },
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
            case name.startsWith('invitations/'):
            case name === 'weekly-email/Unsubscribe':
                return AuthLayout;
            case name.startsWith('settings/'):
            case name.startsWith('teams/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
