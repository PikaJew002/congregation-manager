import { usePage } from '@inertiajs/vue3';

const formatters = new Map<string, Intl.DateTimeFormat>();

/** The current congregation's time zone; the browser's when there is none. */
function timeZone(): string | undefined {
    return usePage().props.currentTeam?.timezone ?? undefined;
}

function formatter(
    options: Intl.DateTimeFormatOptions,
    zone: string | undefined,
): Intl.DateTimeFormat {
    const key = `${JSON.stringify(options)}|${zone ?? ''}`;
    let cached = formatters.get(key);

    if (!cached) {
        cached = new Intl.DateTimeFormat(undefined, {
            ...options,
            timeZone: zone,
        });
        formatters.set(key, cached);
    }

    return cached;
}

/** A moment, shown in the congregation's time zone. */
export function formatDateTime(value: string | null): string {
    return value
        ? formatter(
              { dateStyle: 'medium', timeStyle: 'short' },
              timeZone(),
          ).format(new Date(value))
        : '—';
}

/**
 * A calendar date. A plain YYYY-MM-DD is that day wherever the viewer is; a
 * timestamp is shown as its day in the congregation's time zone.
 */
export function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    if (!value.includes('T')) {
        // Read and shown in UTC, so the day stays as written for every viewer.
        return formatter({ dateStyle: 'medium' }, 'UTC').format(
            new Date(`${value}T12:00:00Z`),
        );
    }

    return formatter({ dateStyle: 'medium' }, timeZone()).format(
        new Date(value),
    );
}
