import type { Directive } from 'vue';
import type { Auth } from '@/types/auth';
import type { Team } from '@/types/teams';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            currentTeam: Team | null;
            teams: Team[];
            [key: string]: unknown;
        };
        flashDataType: {
            toast?: {
                type: 'success' | 'info' | 'warning' | 'error';
                message: string;
            };
            plainTextToken?: string;
            fillResult?: {
                deck: number;
                unmatched: Record<string, string[]>;
                notes: string[];
            };
            importResult?: {
                decks: number;
                unmatched: Array<{
                    date: string;
                    template: string;
                    slot: string;
                    titles: string[];
                }>;
                errors: string[];
            };
            deck?: number;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
