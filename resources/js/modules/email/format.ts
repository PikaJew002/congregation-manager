export { formatDateTime } from '@/lib/format';

export const weekdays = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

export function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'sent':
        case 'completed':
        case 'subscribed':
            return 'default';
        case 'failed':
        case 'paused':
        case 'bounced':
        case 'complained':
            return 'destructive';
        case 'cancelled':
        case 'unsubscribed':
        case 'suppressed':
            return 'outline';
        default:
            return 'secondary';
    }
}

/**
 * A YYYY-MM-DDTHH:mm already in the congregation's time zone, shown as
 * written for every viewer.
 */
export function formatLocalDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    }).format(new Date(`${value}:00Z`));
}

/** "Thursdays and Fridays", "Every day", or "No days chosen". */
export function describeDays(days: number[]): string {
    if (days.length === 7) {
        return 'Every day';
    }

    const names = [...days]
        .sort((a, b) => a - b)
        .map((day) => `${weekdays[day]}s`);

    if (names.length === 0) {
        return 'No days chosen';
    }

    return names.length === 1
        ? names[0]
        : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

/** "Thursdays at 09:00" or "Once, Dec 1, 2026, 7:00 PM". */
export function describeSchedule(email: {
    send_mode: 'recurring' | 'once';
    send_days: number[];
    send_time: string;
    send_at: string | null;
}): string {
    if (email.send_mode === 'recurring') {
        return `${describeDays(email.send_days)} at ${email.send_time}`;
    }

    return email.send_at
        ? `Once, ${formatLocalDateTime(email.send_at)}`
        : 'Once, time not set';
}
