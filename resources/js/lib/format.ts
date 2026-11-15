const dateTime = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const dateOnly = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
});

export function formatDateTime(value: string | null): string {
    return value ? dateTime.format(new Date(value)) : '—';
}

export function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return dateOnly.format(
        new Date(value.includes('T') ? value : `${value}T12:00:00`),
    );
}
