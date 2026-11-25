import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { search as searchRoute } from '@/routes/service-slides/library';

export type ContentMatch = {
    id: number;
    slide: number | null;
    excerpt: string | null;
};

type Options = {
    teamSlug: Ref<string>;
    term: Ref<string>;
    kind: Ref<string | null>;
    archived?: Ref<boolean>;
};

const MIN_LENGTH = 3;
const DELAY_MS = 250;

/**
 * Library items whose slide text matches the search term, fetched from the server once typing pauses. Titles are
 * still filtered in the browser; these are the extra matches found in the words on the slides.
 */
export function useContentSearch({ teamSlug, term, kind, archived }: Options) {
    const matches = ref(new Map<number, ContentMatch>());
    const searching = ref(false);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;

    const run = async (query: string) => {
        controller?.abort();
        controller = new AbortController();
        searching.value = true;

        try {
            const response = await fetch(
                searchRoute.url(teamSlug.value, {
                    query: {
                        q: query,
                        kind: kind.value ?? undefined,
                        archived: archived?.value ? 1 : undefined,
                    },
                }),
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            );

            if (!response.ok) {
                matches.value = new Map();

                return;
            }

            const data = (await response.json()) as { items: ContentMatch[] };
            matches.value = new Map(data.items.map((item) => [item.id, item]));
        } catch (error) {
            if ((error as Error).name !== 'AbortError') {
                matches.value = new Map();
            }
        } finally {
            searching.value = false;
        }
    };

    watch(
        [term, kind, () => archived?.value],
        () => {
            clearTimeout(timer);
            const query = term.value.trim();

            if (query.length < MIN_LENGTH) {
                controller?.abort();
                matches.value = new Map();
                searching.value = false;

                return;
            }

            timer = setTimeout(() => run(query), DELAY_MS);
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        clearTimeout(timer);
        controller?.abort();
    });

    return { matches, searching };
}

export type HighlightPart = { text: string; match: boolean };

/**
 * Splits an excerpt so the searched words can be shown in bold, ignoring case.
 */
export function highlight(text: string, term: string): HighlightPart[] {
    const words = term
        .trim()
        .split(/\s+/)
        .map((word) => word.replace(/[^\p{L}\p{N}']/gu, ''))
        .filter((word) => word.length > 0)
        .map((word) => word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));

    if (words.length === 0) {
        return [{ text, match: false }];
    }

    // With one capture group, split() alternates text between matches (even) and the matches themselves (odd).
    return text
        .split(new RegExp(`(${words.join('|')})`, 'iu'))
        .map((part, index) => ({ text: part, match: index % 2 === 1 }))
        .filter((part) => part.text !== '');
}
