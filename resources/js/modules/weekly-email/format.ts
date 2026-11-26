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
